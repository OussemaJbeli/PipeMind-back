<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Anomaly;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class AnomalyDetected implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Anomaly $anomaly)
    {
        $project = $anomaly->project;

        $this->channels = [new PrivateChannel("project.{$project->uuid}")];

        $this->payload = [
            'uuid' => $anomaly->uuid,
            'type' => $anomaly->type,
            'severity' => $anomaly->severity->value,
            'title' => $anomaly->title,
            'description' => $anomaly->description,
            'metric_name' => $anomaly->metric_name,
            'project_slug' => $project->slug,
        ];
    }

    public function broadcastAs(): string
    {
        return 'anomaly.detected';
    }
}
