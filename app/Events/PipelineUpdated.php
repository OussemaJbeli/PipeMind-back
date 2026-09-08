<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Pipeline;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PipelineUpdated implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Pipeline $pipeline)
    {
        $project = $pipeline->project;

        $this->channels = [
            new PrivateChannel("project.{$project->uuid}"),
            new PrivateChannel("pipeline.{$pipeline->uuid}"),
        ];

        $this->payload = [
            'uuid' => $pipeline->uuid,
            'iid' => $pipeline->iid,
            'status' => $pipeline->status->value,
            'ref' => $pipeline->ref,
            'duration_seconds' => $pipeline->duration_seconds,
            'finished_at' => $pipeline->finished_at?->toIso8601String(),
            'jobs_total' => $pipeline->jobs_total,
            'jobs_failed' => $pipeline->jobs_failed,
            'has_failure' => (bool) $pipeline->has_failure,
            'project_slug' => $project->slug,
        ];
    }

    public function broadcastAs(): string
    {
        return 'pipeline.updated';
    }
}
