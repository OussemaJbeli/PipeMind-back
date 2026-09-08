<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RemediationStatus;
use App\Integrations\ProviderRegistry;
use App\Models\Pipeline;
use App\Models\Remediation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Waits to find out whether the remediation actually worked, then closes the loop.
 *
 * "Succeeded" from an executor only means the API call was accepted — the retry
 * was requested, the pull request was opened. Whether it *fixed* anything is a
 * separate fact that arrives minutes later, and it is the fact worth having: a
 * retry that goes green is a human-grade confirmation that this resolution
 * works, which is exactly what promotes the signature to known and lets every
 * future occurrence short-circuit without a model call.
 *
 * Only retries are self-evidencing. A merge request proves nothing until someone
 * merges it, so those are left for a person to judge.
 */
class WatchRemediationOutcome implements ShouldQueue
{
    use Queueable;

    /**
     * Re-dispatched by hand rather than by the queue's retry, so each attempt
     * is a deliberate poll with its own delay rather than a backoff curve.
     */
    public int $tries = 1;

    public int $timeout = 60;

    private const MAX_ATTEMPTS = 10;

    private const POLL_MINUTES = 2;

    public function __construct(
        public int $remediationId,
        public int $attempt = 1,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(ProviderRegistry $registry): void
    {
        $remediation = Remediation::withoutGlobalScopes()
            ->with(['project.team', 'project.integration', 'failure.signature', 'recommendation'])
            ->find($this->remediationId);

        if (! $remediation?->project?->team || $remediation->status !== RemediationStatus::SUCCEEDED) {
            return;
        }

        withTeam($remediation->project->team, function () use ($remediation): void {
            $pipeline = $this->resultingPipeline($remediation);

            if (! $pipeline) {
                $this->pollAgain($remediation, 'no pipeline yet');

                return;
            }

            $remediation->update(['resulting_pipeline_id' => $pipeline->id]);

            if (! $pipeline->status->isTerminal()) {
                $this->pollAgain($remediation, "pipeline {$pipeline->iid} still running");

                return;
            }

            $success = $pipeline->status->value === 'success';

            $remediation->update(['outcome_success' => $success]);
            $remediation->appendAudit($success ? 'outcome_success' : 'outcome_failure', null, [
                'pipeline_iid' => $pipeline->iid,
                'status' => $pipeline->status->value,
            ]);

            activity_log($remediation->project,
                $success ? 'remediation.verified' : 'remediation.did_not_fix',
                $success ? 'success' : 'warning',
                $remediation->recommendation->title ?? $remediation->action_type->label(),
                $success
                    ? "Pipeline #{$pipeline->iid} passed after the remediation."
                    : "Pipeline #{$pipeline->iid} failed again after the remediation.",
                $remediation,
            );

            if ($success) {
                $this->promoteSignature($remediation);
            }
        });
    }

    /**
     * A retry produces a new pipeline on the same branch and commit. Matching on
     * both, created after the remediation ran, is what identifies it — the
     * provider's response id refers to a run request, not to the pipeline row we
     * later ingest from the webhook.
     */
    private function resultingPipeline(Remediation $remediation): ?Pipeline
    {
        $original = $remediation->failure?->pipeline;

        if (! $original || ! $remediation->executed_at) {
            return null;
        }

        return Pipeline::withoutGlobalScopes()
            ->where('project_id', $remediation->project_id)
            ->where('ref', $original->ref)
            ->where('commit_sha', $original->commit_sha)
            ->where('created_at', '>=', $remediation->executed_at)
            ->whereKeyNot($original->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Promotes the signature to known, so the next occurrence short-circuits.
     *
     * Deliberately conservative: it never overwrites a resolution a human
     * confirmed. A person's wording is better than ours, and silently replacing
     * it would be the system overruling the expert it learned from.
     */
    private function promoteSignature(Remediation $remediation): void
    {
        $signature = $remediation->failure?->signature;
        $recommendation = $remediation->recommendation;

        if (! $signature || ! $recommendation || $signature->is_known) {
            return;
        }

        $signature->forceFill([
            'is_known' => true,
            'known_root_cause' => $signature->known_root_cause
                ?? $remediation->failure->latestAnalysis?->root_cause,
            'known_resolution' => $recommendation->title,
            // No confirmed_by: nobody typed this. The provenance has to say the
            // system inferred it from a green pipeline, not that a human vouched.
            'resolution_confirmed_at' => now(),
        ])->save();

        activity_log($remediation->project, 'signature.resolution_confirmed', 'success',
            $remediation->project->name,
            "Confirmed by a passing pipeline after remediation: {$recommendation->title}",
            $signature);
    }

    private function pollAgain(Remediation $remediation, string $why): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            // Giving up is recorded rather than silent: outcome_success stays
            // null, which the UI shows as "unverified" instead of "did not fix".
            $remediation->appendAudit('outcome_unknown', null, ['reason' => $why]);

            return;
        }

        try {
            self::dispatch($remediation->id, $this->attempt + 1)
                ->delay(now()->addMinutes(self::POLL_MINUTES));
        } catch (Throwable $exception) {
            Log::warning('remediation.watch_redispatch_failed', [
                'remediation' => $remediation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
