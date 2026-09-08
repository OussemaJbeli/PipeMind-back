<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RemediationStatus;
use App\Events\RemediationStatusChanged;
use App\Models\Remediation;
use App\Services\Remediation\RemediationExecutorRegistry;
use App\Services\Remediation\RemediationPolicyEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performs an approved remediation.
 *
 * The important work here is the three checks before anything happens. An
 * approval is permission to act *then*, and time has passed since: the policy
 * may have been tightened, the approval may have aged out, and a human may have
 * already fixed the thing. Each of those must stop execution, and each stops it
 * with a distinct reason so the trail says which one it was.
 */
class ExecuteRemediation implements ShouldQueue
{
    use Queueable;

    /**
     * Not retried. Every executor mutates something outside PipeMind, and a
     * retry that opens a second pull request is worse than a failure somebody
     * has to look at.
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $remediationId,
        public bool $dryRun = false,
    ) {
        $this->onQueue('default');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping("remediation:{$this->remediationId}")];
    }

    public function handle(
        RemediationExecutorRegistry $registry,
        RemediationPolicyEvaluator $evaluator,
    ): void {
        $remediation = Remediation::withoutGlobalScopes()
            ->with(['project.team', 'project.integration', 'failure.pipeline', 'failure.job', 'recommendation', 'approver'])
            ->find($this->remediationId);

        if (! $remediation?->project?->team) {
            return;
        }

        withTeam($remediation->project->team, function () use ($remediation, $registry, $evaluator): void {
            // Only an approved or auto-allowed remediation may run. Anything
            // else reaching here means a double dispatch or a stale queue entry.
            if (! in_array($remediation->status, [RemediationStatus::APPROVED, RemediationStatus::QUEUED], true)) {
                return;
            }

            if ($remediation->isExpired()) {
                $this->stop($remediation, RemediationStatus::EXPIRED,
                    'The approval expired before it could be executed.');

                return;
            }

            // Re-evaluated, never trusted from the row: an approval granted an
            // hour ago must not execute under a policy that has since changed.
            if ($remediation->recommendation && $remediation->failure) {
                $decision = $evaluator->evaluate($remediation->recommendation, $remediation->failure);

                if ($decision->isForbidden()) {
                    $this->stop($remediation, RemediationStatus::CANCELLED,
                        "Policy no longer permits this action: {$decision->reason}");

                    return;
                }
            }

            if (! $registry->handles($remediation->action_type)) {
                $this->stop($remediation, RemediationStatus::CANCELLED,
                    "PipeMind cannot perform '{$remediation->action_type->value}' itself — apply this one by hand.");

                return;
            }

            $executor = $registry->for($remediation->action_type);

            if (! $executor->canExecute($remediation)) {
                $this->stop($remediation, RemediationStatus::CANCELLED,
                    $executor->blockedReason($remediation));

                return;
            }

            $remediation->update([
                'status' => RemediationStatus::EXECUTING,
                'executed_at' => now(),
            ]);
            $remediation->appendAudit('executing', $remediation->approved_by);
            $this->announce($remediation);

            try {
                $result = $executor->execute($remediation, $this->dryRun);

                $remediation->update([
                    'status' => RemediationStatus::SUCCEEDED,
                    'result' => $result->toArray(),
                    'completed_at' => now(),
                ]);
                $remediation->appendAudit('succeeded', $remediation->approved_by, $result->toArray());
                $this->announce($remediation);

                activity_log($remediation->project, 'remediation.succeeded', 'success',
                    $remediation->recommendation->title ?? $remediation->action_type->label(),
                    $result->summary, $remediation);

                // A retry that goes green is evidence the fix works, which is
                // what promotes the signature to known. Only the provider can
                // tell us that, and only later.
                if ($result->externalId && ! $result->dryRun) {
                    WatchRemediationOutcome::dispatch($remediation->id)->delay(now()->addMinutes(2));
                }
            } catch (Throwable $exception) {
                $remediation->update([
                    'status' => RemediationStatus::FAILED,
                    'error' => Str::limit($exception->getMessage(), 1000),
                    'completed_at' => now(),
                ]);
                $remediation->appendAudit('failed', $remediation->approved_by, [
                    'error' => Str::limit($exception->getMessage(), 250),
                ]);
                $this->announce($remediation);

                activity_log($remediation->project, 'remediation.failed', 'error',
                    $remediation->recommendation->title ?? $remediation->action_type->label(),
                    Str::limit($exception->getMessage(), 250), $remediation);

                throw $exception;
            }
        });
    }

    /**
     * A status change nobody is watching for is a page that stays wrong until
     * it is reloaded — and these transitions happen on the queue, seconds after
     * the user clicked Approve and started waiting.
     */
    private function announce(Remediation $remediation): void
    {
        RemediationStatusChanged::dispatch(
            $remediation->loadMissing('project.team', 'recommendation')
        );
    }

    private function stop(Remediation $remediation, RemediationStatus $status, string $reason): void
    {
        $remediation->update([
            'status' => $status,
            'error' => $reason,
            'completed_at' => now(),
        ]);
        $remediation->appendAudit($status->value, null, ['reason' => $reason]);
        $this->announce($remediation);

        activity_log($remediation->project, "remediation.{$status->value}", 'warning',
            $remediation->recommendation->title ?? $remediation->action_type->label(),
            $reason, $remediation);
    }
}
