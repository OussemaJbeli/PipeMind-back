<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Enums\ActionType;
use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;

class RetryJobExecutor implements RemediationExecutor
{
    use ResolvesProvider;

    public static function actionType(): string
    {
        return ActionType::RETRY_JOB->value;
    }

    public function canExecute(Remediation $remediation): bool
    {
        $job = $remediation->failure?->job;

        // Pipelines get retried by humans too. If the job has already been run
        // again since the analysis, retrying is duplicate work at best.
        return $job !== null
            && $job->external_id !== null
            && ! ($remediation->failure->pipeline?->status?->isActive() ?? false);
    }

    public function blockedReason(Remediation $remediation): string
    {
        $job = $remediation->failure?->job;

        return match (true) {
            $job === null => 'The failed job is no longer on record.',
            $job->external_id === null => 'The job has no provider id, so it cannot be retried.',
            default => 'The pipeline is already running again — someone retried it first.',
        };
    }

    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult
    {
        $job = $remediation->failure->job;
        $project = $remediation->project;

        if ($dryRun) {
            return ExecutionResult::dryRun("Would re-run job '{$job->name}' on {$project->external_path}.");
        }

        [$provider, $integration] = $this->provider($remediation);
        $response = $provider->retryJob($integration, $project, $job);

        return ExecutionResult::make(
            summary: "Re-ran job '{$job->name}'.",
            url: $response['html_url'] ?? $response['web_url'] ?? null,
            externalId: isset($response['id']) ? (string) $response['id'] : null,
        );
    }
}
