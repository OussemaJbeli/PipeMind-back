<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Failure;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lets the failure page show "analysing…" the moment the job picks the work up,
 * rather than staying blank for the four seconds a model takes. The gap is
 * short, but it is exactly when someone is watching.
 */
class AnalysisStarted implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Failure $failure)
    {
        $this->channels = [new PrivateChannel("project.{$failure->project->uuid}")];

        $this->payload = [
            'failure_uuid' => $failure->uuid,
            'status' => 'analyzing',
        ];
    }

    public function broadcastAs(): string
    {
        return 'analysis.started';
    }
}
