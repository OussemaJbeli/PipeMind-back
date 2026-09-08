<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\BroadcastsSnapshot;
use App\Models\Analysis;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A summary, not the analysis. Evidence, recommendations and patches are far
 * too large for a socket frame, and the failure page needs to fetch them to
 * render anyway — so this says "it is ready" and carries just enough to update
 * a list row in place.
 */
class AnalysisCompleted implements ShouldBroadcast
{
    use BroadcastsSnapshot, Dispatchable;

    /** @var array<int,PrivateChannel> */
    public array $channels;

    /** @var array<string,mixed> */
    public array $payload;

    public function __construct(Analysis $analysis)
    {
        $failure = $analysis->failure;

        $this->channels = [new PrivateChannel("project.{$failure->project->uuid}")];

        $this->payload = [
            'failure_uuid' => $failure->uuid,
            'analysis_uuid' => $analysis->uuid,
            'status' => 'analyzed',
            'category' => $analysis->category?->value,
            'confidence' => $analysis->confidence !== null ? (float) $analysis->confidence : null,
            'summary' => $analysis->summary,
            'cache_hit' => (bool) $analysis->cache_hit,
        ];
    }

    public function broadcastAs(): string
    {
        return 'analysis.completed';
    }
}
