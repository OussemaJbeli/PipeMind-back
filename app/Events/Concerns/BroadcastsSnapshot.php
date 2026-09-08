<?php

declare(strict_types=1);

namespace App\Events\Concerns;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Events carry a snapshot, never a model.
 *
 * `SerializesModels` stores an id and re-queries when the queued broadcast runs
 * — and that re-query happens with NO TEAM BOUND, so `TeamScope` filters the
 * project to null and `broadcastOn()` dereferences it. In the test suite, where
 * the queue is synchronous, that surfaced as a 500 on the webhook endpoint: a
 * broadcast was able to fail the ingestion it was only meant to report.
 *
 * Building both the channels and the payload in the constructor removes the
 * re-query entirely. It is also more correct: an event describes a moment, and
 * re-reading the row later would describe a different one.
 */
trait BroadcastsSnapshot
{
    use InteractsWithSockets;

    /**
     * Broadcasting is best-effort. Retrying a live update that failed two
     * minutes ago would deliver something already stale, and the frontend
     * resynchronises on reconnect anyway.
     */
    public int $tries = 1;

    /** @return array<int,PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
