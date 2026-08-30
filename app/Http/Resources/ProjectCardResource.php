<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The project card on the workspace grid (ui/workspace.png). */
class ProjectCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $last = $this->whenLoaded('lastPipeline');

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'color' => $this->color,
            'tech_stack' => $this->tech_stack ?? [],

            // "Right now", deliberately distinct from the 30-day success_rate:
            // a project at 98% whose last pipeline just failed must not read green.
            'health_status' => $this->health_status,

            'success_rate' => (float) $this->success_rate,
            'failures_today' => (int) $this->failures_today,
            'pipelines_count' => (int) $this->pipelines_count,

            'last_pipeline' => $this->last_pipeline_id && $this->relationLoaded('lastPipeline') && $last
                ? [
                    'uuid' => $last->uuid,
                    'iid' => $last->iid,
                    'status' => $last->status->value,
                    'finished_at' => $last->finished_at?->toIso8601String(),
                    'duration_seconds' => $last->duration_seconds,
                ]
                : null,
        ];
    }
}
