<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveFailureRequest;
use App\Http\Resources\FailureDetailResource;
use App\Http\Resources\FailureListResource;
use App\Jobs\AnalyzeFailure;
use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\Project;
use App\Services\Ai\AnalysisCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FailureController extends Controller
{
    /** Team-wide. `?project=slug` narrows it to one project. */
    public function index(Request $request): array
    {
        $failures = $this->filtered($request)
            ->with([
                'project:id,uuid,name,slug,color',
                'pipeline:id,uuid,iid,ref',
                // latestOfMany() self-joins analyses, so these must be qualified.
                'latestAnalysis:analyses.id,analyses.failure_id,analyses.confidence',
            ])
            ->reorder('failed_at', 'desc')
            ->paginate(min($request->integer('per_page', 25), 100));

        return [
            'data' => FailureListResource::collection($failures->items())->resolve(),
            'meta' => [
                'current_page' => $failures->currentPage(),
                'last_page' => $failures->lastPage(),
                'per_page' => $failures->perPage(),
                'total' => $failures->total(),
            ],
        ];
    }

    public function show(Failure $failure): array
    {
        $failure->load([
            'project:id,uuid,name,slug',
            'pipeline.changes',
            'job.log',
            'latestAnalysis.evidence',
            'latestAnalysis.feedback',
            'recommendations',
        ]);

        // The previous pipeline on the same ref is an observed fact and one of
        // the highest-signal ones: green until this commit narrows it enormously.
        $failure->previous_pipeline = $failure->pipeline
            ? $this->previousPipeline($failure->pipeline)
            : null;

        return ['data' => (new FailureDetailResource($failure))->resolve()];
    }

    /** Queues an analysis. `?force=1` bypasses the cache and any stored result. */
    public function analyze(Request $request, Failure $failure): JsonResponse
    {
        if ($failure->status->value === 'analyzing') {
            return response()->json([
                'message' => 'Analysis already in progress.',
                'error_code' => 'ANALYSIS_IN_PROGRESS',
                'retryable' => false,
            ], 409);
        }

        $force = $request->boolean('force');

        AnalyzeFailure::dispatch($failure->id, force: $force);

        $failure->update(['status' => 'queued']);

        return response()->json([
            'message' => 'Analysis queued.',
            'data' => [
                'status' => 'queued',
                'failure_uuid' => $failure->uuid,
                'forced' => $force,
            ],
        ], 202);
    }

    public function resolve(ResolveFailureRequest $request, Failure $failure, AnalysisCache $cache): array
    {
        $failure->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
            'resolution_type' => $request->string('resolution_type')->toString(),
            'resolution_note' => $request->input('resolution_note'),
            'resolution_commit_sha' => $request->input('resolution_commit_sha'),
            'time_to_resolution_seconds' => $failure->failed_at
                ? (int) now()->diffInSeconds($failure->failed_at, absolute: true)
                : null,
        ]);

        // A human has just said what actually fixed this. Any cached analysis
        // for the signature predates that knowledge.
        $cache->forget($failure);

        activity_log(
            $failure->project,
            'failure.resolved',
            'success',
            $failure->project->name,
            $failure->error_message ?? 'Failure resolved',
            $failure,
        );

        return ['data' => ['uuid' => $failure->uuid, 'status' => 'resolved']];
    }

    public function ignore(Failure $failure): array
    {
        $failure->update([
            'status' => 'ignored',
            'resolved_at' => now(),
            'resolution_type' => 'ignored',
        ]);

        return ['data' => ['uuid' => $failure->uuid, 'status' => 'ignored']];
    }

    /**
     * Neighbours found by the last analysis.
     *
     * Read from the stored analysis rather than re-queried: the vectors that
     * produced it are what the user was shown, and recomputing could quietly
     * return something different.
     */
    public function similar(Request $request, Failure $failure): array
    {
        $limit = min($request->integer('limit', 5), 20);
        $similar = $failure->latestAnalysis?->raw_response['similar_failures'] ?? [];

        return ['data' => array_slice($similar, 0, $limit)];
    }

    /**
     * What happened to this failure, in order.
     *
     * Detection, each analysis attempt, feedback and resolution as one ordered
     * list. Reconstructed from the rows rather than stored as its own log: a
     * separate timeline table would be a second version of the truth, free to
     * drift from the first.
     */
    public function timeline(Failure $failure): array
    {
        $failure->load(['analyses.feedback', 'resolver:id,name']);

        $events = [
            [
                'at' => $failure->failed_at?->toIso8601String(),
                'type' => 'failed',
                'title' => 'Pipeline failed',
                'detail' => $failure->error_message,
            ],
            [
                'at' => $failure->detected_at?->toIso8601String(),
                'type' => 'detected',
                'title' => 'Failure detected',
                'detail' => $failure->occurrence_index > 1
                    ? "Occurrence #{$failure->occurrence_index} of this signature"
                    : 'First time this signature has been seen',
            ],
        ];

        foreach ($failure->analyses as $analysis) {
            $events[] = [
                'at' => $analysis->completed_at?->toIso8601String() ?? $analysis->created_at?->toIso8601String(),
                'type' => $analysis->status->value === 'completed' ? 'analyzed' : 'analysis_failed',
                'title' => $analysis->status->value === 'completed'
                    ? 'Analysed'.($analysis->cache_hit ? ' (reused a previous answer)' : '')
                    : 'Analysis failed',
                'detail' => $analysis->status->value === 'completed'
                    ? sprintf('%s · %d%% confidence · %s',
                        $analysis->model_name ?? 'unknown model',
                        round(((float) $analysis->confidence) * 100),
                        $analysis->cache_hit ? 'no cost' : '$'.number_format((float) $analysis->cost_usd, 4))
                    : $analysis->error,
                'analysis_uuid' => $analysis->uuid,
            ];

            foreach ($analysis->feedback as $feedback) {
                $events[] = [
                    'at' => $feedback->created_at?->toIso8601String(),
                    'type' => $feedback->was_helpful ? 'feedback_positive' : 'feedback_negative',
                    'title' => $feedback->was_helpful ? 'Marked helpful' : 'Marked unhelpful',
                    'detail' => $feedback->correct_category
                        ? 'Corrected to '.($feedback->correct_category->value ?? $feedback->correct_category)
                        : $feedback->comment,
                ];
            }
        }

        if ($failure->resolved_at) {
            $events[] = [
                'at' => $failure->resolved_at->toIso8601String(),
                'type' => $failure->status->value === 'ignored' ? 'ignored' : 'resolved',
                'title' => $failure->status->value === 'ignored' ? 'Ignored' : 'Resolved',
                'detail' => $failure->resolution_note
                    ?? ($failure->resolution_type ? "Marked {$failure->resolution_type}" : null),
                'by' => $failure->resolver?->name,
            ];
        }

        // Undated rows sink rather than jumping to 1970, which is what sorting
        // a null timestamp ascending would do.
        $events = array_values(array_filter($events, fn (array $e) => $e['at'] !== null));
        usort($events, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));

        return ['data' => $events];
    }

    /** Recommendations alone, for a panel that refreshes without the whole page. */
    public function recommendations(Failure $failure): array
    {
        $failure->load('recommendations');

        return ['data' => $failure->recommendations->map(fn ($rec) => [
            'uuid' => $rec->uuid,
            'title' => $rec->title,
            'description' => $rec->description,
            'rationale' => $rec->rationale,
            'action_type' => $rec->action_type->value ?? $rec->action_type,
            'risk' => $rec->risk->value ?? $rec->risk,
            'confidence' => $rec->confidence !== null ? (float) $rec->confidence : null,
            'affected_files' => $rec->affected_files ?? [],
            'has_patch' => filled($rec->patch),
            'patch' => $rec->patch,
            'status' => $rec->status,
        ])->all()];
    }

    private function filtered(Request $request)
    {
        return Failure::query()
            ->when($request->filled('project'), function ($query) use ($request) {
                $projectId = Project::where('slug', $request->string('project'))->value('id');
                $query->where('project_id', $projectId);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('resolved'), fn ($q) => $request->boolean('resolved')
                ? $q->whereNotNull('resolved_at')
                : $q->whereNull('resolved_at'))
            ->when($request->filled('range'), fn ($q) => $q->where(
                'failed_at', '>=', now()->sub($this->interval($request->string('range')->toString())),
            ));
    }

    /** @return array<string,mixed>|null */
    private function previousPipeline(Pipeline $pipeline): ?array
    {
        $previous = Pipeline::query()
            ->where('project_id', $pipeline->project_id)
            ->where('ref', $pipeline->ref)
            ->where('id', '<', $pipeline->id)
            ->whereIn('status', ['success', 'failed'])
            ->reorder('id', 'desc')
            ->first(['iid', 'status', 'finished_at']);

        return $previous ? [
            'iid' => $previous->iid,
            'status' => $previous->status->value,
            'finished_at' => $previous->finished_at?->toIso8601String(),
        ] : null;
    }

    private function interval(string $range): \DateInterval
    {
        return match ($range) {
            '24h' => new \DateInterval('PT24H'),
            '30d' => new \DateInterval('P30D'),
            '90d' => new \DateInterval('P90D'),
            default => new \DateInterval('P7D'),
        };
    }
}
