<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Enums\ActionType;
use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;

class RetryPipelineExecutor implements RemediationExecutor
{
    use ResolvesProvider;

    public static function actionType(): string
    {
        return ActionType::RETRY_PIPELINE->value;
    }

    public function canExecute(Remediation $remediation): bool
    {
        $pipeline = $remediation->failure?->pipeline;

        // No external_id check: the column is NOT NULL, unlike a job's.
        return $pipeline !== null && ! $pipeline->status->isActive();
    }

    public function blockedReason(Remediation $remediation): string
    {
        return ($remediation->failure?->pipeline?->status?->isActive() ?? false)
            ? 'The pipeline is already running again — someone retried it first.'
            : 'The pipeline is no longer on record.';
    }

    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult
    {
        $pipeline = $remediation->failure->pipeline;
        $project = $remediation->project;

        if ($dryRun) {
            return ExecutionResult::dryRun("Would re-run pipeline #{$pipeline->iid} on {$project->external_path}.");
        }

        [$provider, $integration] = $this->provider($remediation);
        $response = $provider->retryPipeline($integration, $project, $pipeline);

        return ExecutionResult::make(
            summary: "Re-ran the failed jobs of pipeline #{$pipeline->iid}.",
            url: $response['html_url'] ?? $response['web_url'] ?? $pipeline->web_url,
            externalId: isset($response['id']) ? (string) $response['id'] : null,
        );
    }
}
