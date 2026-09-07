<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The failure investigation page, in one response.
 *
 * `observed` and `analysis` are a hard structural split, not a styling choice.
 * `observed` holds facts — what the provider reported, what the commit changed.
 * `analysis` holds inference, and always carries confidence and provenance. The
 * moment a fact and an inference share a shape, the UI can no longer honestly
 * distinguish them, so they never share one here.
 */
class FailureDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $analysis = $this->latestAnalysis;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'category_color' => $this->category->color(),
            'subcategory' => $this->subcategory,
            'error_message' => $this->error_message,
            'error_type' => $this->error_type,
            'ecosystem' => $this->ecosystem,
            'stage_name' => $this->stage_name,
            'job_name' => $this->job_name,
            'exit_code' => $this->exit_code,
            'failed_at' => $this->failed_at?->toIso8601String(),
            'occurrence_index' => $this->occurrence_index,
            'is_flaky' => (bool) $this->is_flaky,
            'is_transient' => (bool) $this->is_transient,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_type' => $this->resolution_type,
            'resolution_note' => $this->resolution_note,

            'project' => [
                'uuid' => $this->project->uuid,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
            ],

            'pipeline' => $this->pipeline ? [
                'uuid' => $this->pipeline->uuid,
                'iid' => $this->pipeline->iid,
                'ref' => $this->pipeline->ref,
                'commit_short_sha' => $this->pipeline->commit_short_sha,
                'commit_message' => $this->pipeline->commit_message,
                'web_url' => $this->pipeline->web_url,
            ] : null,

            'job' => $this->job ? [
                'uuid' => $this->job->uuid,
                'name' => $this->job->name,
                'duration_seconds' => $this->job->duration_seconds,
                'web_url' => $this->job->web_url,
            ] : null,

            // OBSERVED — facts, never AI output.
            'observed' => [
                'changed_files' => $this->pipeline?->relationLoaded('changes')
                    ? $this->pipeline->changes->map(fn ($change) => [
                        'path' => $change->file_path,
                        'change_type' => $change->change_type,
                        'additions' => $change->additions,
                        'deletions' => $change->deletions,
                        'is_config' => (bool) $change->is_config,
                        'is_dependency' => (bool) $change->is_dependency,
                        'patch' => $change->patch,
                        'patch_truncated' => (bool) $change->patch_truncated,
                    ])->values()->all()
                    : [],
                'previous_pipeline' => $this->previous_pipeline,
                'log_excerpt' => $this->job?->log?->excerpt,
                'log_excerpt_start_line' => $this->job?->log?->excerpt_start_line,
                'log_excerpt_url' => $this->job
                    ? "/api/v1/jobs/{$this->job->uuid}/log"
                    : null,
            ],

            // PIPEMIND ANALYSIS — inference.
            'analysis' => $analysis ? $this->analysis($analysis) : null,

            'similar_failures' => $analysis?->raw_response['similar_failures'] ?? [],

            'recommendations' => $this->whenLoaded(
                'recommendations',
                fn () => $this->recommendations->map(fn ($rec) => [
                    'uuid' => $rec->uuid,
                    'title' => $rec->title,
                    'description' => $rec->description,
                    'rationale' => $rec->rationale,
                    'action_type' => $rec->action_type,
                    'risk' => $rec->risk,
                    'confidence' => $rec->confidence !== null ? (float) $rec->confidence : null,
                    'affected_files' => $rec->affected_files ?? [],
                    'has_patch' => filled($rec->patch),
                    // Sent inline: it is already bounded to 8 KB by the
                    // validator, and a second round trip to read a diff the user
                    // is looking at buys nothing.
                    'patch' => $rec->patch,
                    'status' => $rec->status,
                ])->values()->all(),
                [],
            ),
        ];
    }

    /** @return array<string,mixed> */
    protected function analysis(Analysis $analysis): array
    {
        $feedback = $analysis->relationLoaded('feedback') ? $analysis->feedback->first() : null;

        return [
            'uuid' => $analysis->uuid,
            'status' => $analysis->status->value,
            'confidence' => $analysis->confidence !== null ? (float) $analysis->confidence : null,
            'summary' => $analysis->summary,
            'root_cause' => $analysis->root_cause,
            'explanation' => $analysis->explanation,
            'is_transient' => (bool) $analysis->is_transient,
            'retry_recommended' => (bool) $analysis->retry_recommended,

            // Provenance. Every one of these is why a user should or should not
            // trust the paragraph above.
            'classification_source' => $analysis->classification_source,
            'classification_confidence' => $analysis->classification_confidence !== null
                ? (float) $analysis->classification_confidence
                : null,
            'used_rag' => (bool) $analysis->used_rag,
            'similar_failures_count' => (int) $analysis->similar_failures_count,
            'model_provider' => $analysis->model_provider,
            'model_name' => $analysis->model_name,
            'latency_ms' => $analysis->latency_ms,
            'cost_usd' => (float) $analysis->cost_usd,
            'cache_hit' => (bool) $analysis->cache_hit,
            'error' => $analysis->error,
            'completed_at' => $analysis->completed_at?->toIso8601String(),

            'evidence' => $analysis->relationLoaded('evidence')
                ? $analysis->evidence->map(fn ($item) => [
                    'type' => $item->type,
                    'content' => $item->content,
                    'source_ref' => $item->source_ref,
                    'line_number' => $item->line_number,
                    'weight' => (float) $item->weight,
                ])->values()->all()
                : [],

            'feedback' => [
                'given' => (bool) $feedback,
                'was_helpful' => $feedback?->was_helpful,
            ],
        ];
    }
}
