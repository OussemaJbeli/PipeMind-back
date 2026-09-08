<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\PipelineJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Only the pipeline channel. Nothing on a project page renders individual jobs,
 * and a busy pipeline emits dozens of these.
 */
class JobUpdated implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(PipelineJob $job)
    {
        $pipeline = $job->pipeline;

        $this->channels = [new PrivateChannel("pipeline.{$pipeline->uuid}")];

        $this->payload = [
            'id' => $job->id,
            'name' => $job->name,
            'status' => $job->status->value,
            'stage_name' => $job->stage_name,
            'duration_seconds' => $job->duration_seconds,
            'pipeline_uuid' => $pipeline->uuid,
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.updated';
    }
}
