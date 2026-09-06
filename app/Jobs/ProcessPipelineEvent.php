<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\PipelineEvent;
use App\Services\Ingestion\PipelineIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class ProcessPipelineEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $eventId)
    {
        $this->onQueue('ingestion');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        $event = PipelineEvent::withoutGlobalScopes()->find($this->eventId);

        // GitLab fires a pipeline event and several build events within the same
        // second. Concurrent workers upserting the same pipeline produce lost
        // updates and duplicate jobs, so serialise per pipeline.
        return [
            (new WithoutOverlapping(sprintf(
                'pipeline-event:%s:%s',
                $event?->integration_id ?? 'x',
                $event?->external_object_id ?? $this->eventId,
            )))->releaseAfter(5)->expireAfter(180),
        ];
    }

    public function handle(ProviderRegistry $registry, PipelineIngestor $ingestor): void
    {
        $event = PipelineEvent::withoutGlobalScopes()->find($this->eventId);

        if (! $event || $event->processing_status === 'processed') {
            return;   // redelivery after a partial failure
        }

        $integration = $event->integration()->withoutGlobalScopes()->with('team')->first();

        if (! $integration?->team) {
            $event->update([
                'processing_status' => 'skipped',
                'processing_error' => 'Integration or team no longer exists.',
                'processed_at' => now(),
            ]);

            return;
        }

        // MANDATORY: a worker has no authenticated user, so the global scope is
        // inert without an explicit binding and the job would operate across
        // every tenant.
        withTeam($integration->team, function () use ($event, $integration, $registry, $ingestor): void {
            $event->update([
                'processing_status' => 'processing',
                'attempts' => $event->attempts + 1,
            ]);

            $ingestor->ingest($integration, $registry->for($integration), $event);

            $event->refresh();

            if ($event->processing_status === 'processing') {
                $event->update(['processing_status' => 'processed', 'processed_at' => now()]);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        PipelineEvent::withoutGlobalScopes()->where('id', $this->eventId)->update([
            'processing_status' => 'failed',
            'processing_error' => Str::limit($e?->getMessage() ?? 'Unknown error', 2000),
        ]);
    }
}
