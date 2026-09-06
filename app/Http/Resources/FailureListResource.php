<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list shape. FailureDetailResource returns the analysis, its evidence, its
 * recommendations and the similar failures — none of which a 25-row table needs.
 */
class FailureListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'category_color' => $this->category->color(),
            'subcategory' => $this->subcategory,
            'error_message' => $this->error_message,
            'stage_name' => $this->stage_name,
            'job_name' => $this->job_name,
            'occurrence_index' => $this->occurrence_index,
            'is_flaky' => (bool) $this->is_flaky,
            'is_transient' => (bool) $this->is_transient,
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failed_at_display' => $this->failed_at?->diffForHumans(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'time_to_resolution_display' => Format::duration($this->time_to_resolution_seconds),

            'project' => $this->whenLoaded('project', fn () => [
                'uuid' => $this->project->uuid,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
                'color' => $this->project->color,
            ]),

            'pipeline' => $this->whenLoaded('pipeline', fn () => [
                'uuid' => $this->pipeline->uuid,
                'iid' => $this->pipeline->iid,
                'ref' => $this->pipeline->ref,
            ]),

            // Lets the list render a confidence chip without loading the analysis.
            'analysis_confidence' => $this->whenLoaded(
                'latestAnalysis',
                fn () => $this->latestAnalysis?->confidence !== null
                    ? (float) $this->latestAnalysis->confidence
                    : null,
            ),
        ];
    }
}
