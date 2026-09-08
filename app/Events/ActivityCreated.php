<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\ActivityLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Team-scoped rather than project-scoped: activity spans projects, and the feed
 * is the one place people watch to see the system doing anything at all.
 */
class ActivityCreated implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(ActivityLog $activity)
    {
        $this->channels = [new PrivateChannel("team.{$activity->team->uuid}")];

        $this->payload = [
            'uuid' => $activity->uuid,
            'action' => $activity->action,
            'level' => $activity->level,
            'title' => $activity->title,
            'description' => $activity->description,
            'actor_type' => $activity->actor_type,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'activity.created';
    }
}
