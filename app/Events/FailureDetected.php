<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Failure;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries the route parameters, not just the news. A notification that says
 * "something failed" and then makes you find it is worse than none.
 */
class FailureDetected implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Failure $failure)
    {
        $project = $failure->project;

        $this->channels = [new PrivateChannel("project.{$project->uuid}")];

        $this->payload = [
            'uuid' => $failure->uuid,
            'category' => $failure->category->value,
            'severity' => $failure->severity->value,
            'error_message' => $failure->error_message,
            'job_name' => $failure->job_name,
            'pipeline_iid' => $failure->pipeline?->iid,
            'ref' => $failure->pipeline?->ref,
            'project_slug' => $project->slug,
        ];
    }

    public function broadcastAs(): string
    {
        return 'failure.detected';
    }
}
