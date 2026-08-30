<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list shape: nine fields. PipelineResource returns everything plus stages,
 * jobs and changes — loading that for a 25-row table is how list endpoints get slow.
 */
class PipelineListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $failure = $this->whenLoaded('failures', fn () => $this->failures->first());

        return [
            'uuid' => $this->uuid,
            'iid' => $this->iid,
            'status' => $this->status->value,
            'ref' => $this->ref,
            'source' => $this->source,
            'provider' => $this->provider,
            'commit_short_sha' => $this->commit_short_sha,
            'commit_message' => $this->commit_message,
            'duration_seconds' => $this->duration_seconds,
            'duration_display' => Format::duration($this->duration_seconds),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'has_failure' => (bool) $this->has_failure,

            // A failed row routes to the FAILURE, not the pipeline: the user's next
            // question is "why", and the failure page answers it directly.
            'failure_uuid' => $this->relationLoaded('failures') && $failure ? $failure->uuid : null,
        ];
    }
}
