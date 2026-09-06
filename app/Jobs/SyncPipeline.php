<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\Pipeline;
use App\Models\PipelineEvent;
use App\Services\Ingestion\PipelineIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Authoritative refetch from the provider API.
 *
 * Webhooks are hints; the API is truth. Used by reconciliation to rescue
 * pipelines whose terminal event never arrived.
 */
class SyncPipeline implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $pipelineId)
    {
        $this->onQueue('ingestion');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("sync-pipeline:{$this->pipelineId}"))->releaseAfter(10)->expireAfter(180)];
    }

    public function handle(ProviderRegistry $registry, PipelineIngestor $ingestor): void
    {
        $pipeline = Pipeline::withoutGlobalScopes()
            ->with('project.integration.team')
            ->find($this->pipelineId);

        $integration = $pipeline?->project?->integration;

        if (! $pipeline || ! $integration?->team) {
            return;
        }

        withTeam($integration->team, function () use ($pipeline, $integration, $registry, $ingestor): void {
            $adapter = $registry->for($integration);

            $normalized = $adapter->fetchPipeline($integration, $pipeline->project, $pipeline->external_id);
            $jobs = $adapter->fetchJobs($integration, $pipeline->project, $pipeline->external_id);

            // Reuse the ingest path so reconciliation and webhooks converge to
            // exactly the same state rather than drifting apart.
            $event = PipelineEvent::create([
                'integration_id' => $integration->id,
                'project_id' => $pipeline->project_id,
                'pipeline_id' => $pipeline->id,
                'provider' => $integration->provider,
                'event_type' => 'reconcile',
                'external_delivery_id' => 'reconcile:'.$pipeline->id.':'.now()->timestamp,
                'external_object_id' => $pipeline->external_id,
                'signature_valid' => true,
                'payload' => [
                    ...$normalized->raw,
                    'builds' => array_map(fn ($job) => $job->raw, $jobs),
                ],
                'processing_status' => 'processing',
            ]);

            $ingestor->ingest($integration, $adapter, $event);

            $event->update(['processing_status' => 'processed', 'processed_at' => now()]);
        });
    }
}
