<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Remediation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Both channels, deliberately. The project page shows the remediation list; the
 * team channel drives the pending-approval badge, which has to be visible from
 * anywhere in the workspace — a pending approval is somebody blocked.
 */
class RemediationStatusChanged implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Remediation $remediation)
    {
        $project = $remediation->project;

        $this->channels = [
            new PrivateChannel("project.{$project->uuid}"),
            new PrivateChannel("team.{$project->team->uuid}"),
        ];

        $this->payload = [
            'uuid' => $remediation->uuid,
            'status' => $remediation->status->value,
            'action_type' => $remediation->action_type->value,
            'title' => $remediation->recommendation->title ?? $remediation->action_type->label(),
            'project_slug' => $project->slug,
            'error' => $remediation->error,
            'outcome_success' => $remediation->outcome_success,
        ];
    }

    public function broadcastAs(): string
    {
        return 'remediation.status';
    }
}
