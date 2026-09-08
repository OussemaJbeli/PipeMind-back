<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Project;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Header counters after a pipeline settles. Separate from `pipeline.updated`
 * because they are recomputed by a job that runs after ingestion finishes —
 * sending them with the pipeline event would broadcast figures one run stale.
 */
class ProjectStatsUpdated implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Project $project)
    {
        $this->channels = [new PrivateChannel("project.{$project->uuid}")];

        $this->payload = [
            'uuid' => $project->uuid,
            'slug' => $project->slug,
            'health_status' => $project->health_status,
            'success_rate' => (float) $project->success_rate,
            'failures_today' => $project->failures_today,
            'pipelines_count' => $project->pipelines_count,
            'last_pipeline_at' => $project->last_pipeline_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stats.updated';
    }
}
