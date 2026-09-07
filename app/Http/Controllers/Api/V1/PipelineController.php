<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineListResource;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PipelineController extends Controller
{
    /** Paginated and filtered. Filters travel in the query string so a view is shareable. */
    public function index(Request $request, Project $project): array
    {
        $pipelines = $project->pipelines()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('ref'), fn ($q) => $q->where('ref', $request->string('ref')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('range'), fn ($q) => $q->where(
                'created_at', '>=', now()->sub($this->interval($request->string('range')->toString())),
            ))
            // The failure is what makes this list worth visiting instead of the
            // provider's own: a bare pipeline list is something GitLab already has.
            ->with(['failures:id,pipeline_id,uuid,category,subcategory,severity,status,error_message'])
            ->reorder('id', 'desc')
            ->paginate(min($request->integer('per_page', 25), 100));

        return [
            'data' => PipelineListResource::collection($pipelines->items())->resolve(),
            'meta' => [
                'current_page' => $pipelines->currentPage(),
                'last_page' => $pipelines->lastPage(),
                'per_page' => $pipelines->perPage(),
                'total' => $pipelines->total(),
            ],
            // Populates the branch filter without a second request, and without
            // the client guessing from whatever happens to be on page one.
            'filters' => [
                'refs' => $project->pipelines()
                    ->reorder()->distinct()->limit(50)->pluck('ref')->all(),
            ],
        ];
    }

    /**
     * By UUID, for links that must survive without a project slug.
     *
     * Reuses show(): one shape for a pipeline, whichever key was used to find
     * it. Two presenters would drift.
     */
    public function showByUuid(Pipeline $pipeline): array
    {
        return $this->show($pipeline->project, $pipeline->iid);
    }

    /** Bound by iid within the project, because that is the number users see. */
    public function show(Project $project, int $iid): array
    {
        $pipeline = $project->pipelines()
            ->where('iid', $iid)
            ->with([
                'stages' => fn ($q) => $q->orderBy('position'),
                'jobs' => fn ($q) => $q->orderBy('position'),
                'changes',
                // latestOfMany() self-joins analyses, so these must be qualified.
                'failures.latestAnalysis:analyses.id,analyses.failure_id,analyses.confidence,analyses.status,analyses.summary',
            ])
            ->firstOrFail();

        return ['data' => [
            'uuid' => $pipeline->uuid,
            'iid' => $pipeline->iid,
            'status' => $pipeline->status->value,
            'source' => $pipeline->source,
            'ref' => $pipeline->ref,
            'provider' => $pipeline->provider,
            'commit_sha' => $pipeline->commit_sha,
            'commit_short_sha' => $pipeline->commit_short_sha,
            'commit_message' => $pipeline->commit_message,
            'commit_author_name' => $pipeline->commit_author_name,
            'commit_url' => $pipeline->commit_url,
            'web_url' => $pipeline->web_url,
            'duration_seconds' => $pipeline->duration_seconds,
            'queue_seconds' => $pipeline->queue_seconds,
            'jobs_total' => (int) $pipeline->jobs_total,
            'jobs_failed' => (int) $pipeline->jobs_failed,
            'started_at' => $pipeline->started_at?->toIso8601String(),
            'finished_at' => $pipeline->finished_at?->toIso8601String(),

            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'slug' => $project->slug,
            ],

            'stages' => $pipeline->stages->map(fn ($stage) => [
                'name' => $stage->name,
                'position' => (int) $stage->position,
                'status' => $stage->status,
                'duration_seconds' => $stage->duration_seconds,
                'jobs_count' => (int) $stage->jobs_count,
            ])->values()->all(),

            'jobs' => $pipeline->jobs->map(fn ($job) => [
                'uuid' => $job->uuid,
                'name' => $job->name,
                'stage_name' => $job->stage_name,
                'position' => (int) $job->position,
                'status' => $job->status->value ?? $job->status,
                'exit_code' => $job->exit_code,
                'failure_reason' => $job->failure_reason,
                'duration_seconds' => $job->duration_seconds,
                'allow_failure' => (bool) $job->allow_failure,
                'log_fetched' => (bool) $job->log_fetched,
                'web_url' => $job->web_url,
            ])->values()->all(),

            'changes' => $pipeline->changes->map(fn ($change) => [
                'path' => $change->file_path,
                'change_type' => $change->change_type,
                'additions' => $change->additions,
                'deletions' => $change->deletions,
                'is_config' => (bool) $change->is_config,
                'is_dependency' => (bool) $change->is_dependency,
            ])->values()->all(),

            'failures' => $pipeline->failures->map(fn ($failure) => [
                'uuid' => $failure->uuid,
                'category' => $failure->category->value,
                'subcategory' => $failure->subcategory,
                'severity' => $failure->severity->value,
                'status' => $failure->status->value,
                'error_message' => $failure->error_message,
                'job_name' => $failure->job_name,
                'analysis_confidence' => $failure->latestAnalysis?->confidence !== null
                    ? (float) $failure->latestAnalysis->confidence
                    : null,
                'analysis_summary' => $failure->latestAnalysis?->summary,
            ])->values()->all(),
        ]];
    }

    /**
     * Every analysis for a project, with its cost and whether anyone corrected it.
     *
     * This is the page that answers "what is the AI actually costing us, and is
     * it any good" — the two questions a reviewer asks first.
     */
    public function analyses(Request $request, Project $project): array
    {
        $analyses = DB::table('analyses')
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->leftJoin('analysis_feedback', 'analysis_feedback.analysis_id', '=', 'analyses.id')
            ->where('failures.project_id', $project->id)
            ->when($request->filled('status'), fn ($q) => $q->where('analyses.status', $request->string('status')))
            ->orderByDesc('analyses.id')
            ->select([
                'analyses.uuid', 'analyses.status', 'analyses.category', 'analyses.subcategory',
                'analyses.confidence', 'analyses.summary', 'analyses.model_provider',
                'analyses.model_name', 'analyses.cost_usd', 'analyses.latency_ms',
                'analyses.cache_hit', 'analyses.used_rag', 'analyses.classification_source',
                'analyses.created_at', 'failures.uuid as failure_uuid',
                'analysis_feedback.was_helpful',
            ])
            ->paginate(min($request->integer('per_page', 25), 100));

        $totals = DB::table('analyses')
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->where('failures.project_id', $project->id)
            ->selectRaw('count(*) as total, coalesce(sum(cost_usd),0) as cost,
                         coalesce(avg(latency_ms),0) as latency,
                         coalesce(avg(confidence),0) as confidence,
                         sum(case when cache_hit then 1 else 0 end) as cached')
            ->first();

        return [
            'data' => collect($analyses->items())->map(fn ($row) => [
                'uuid' => $row->uuid,
                'failure_uuid' => $row->failure_uuid,
                'status' => $row->status,
                'category' => $row->category,
                'subcategory' => $row->subcategory,
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'summary' => $row->summary,
                'model_provider' => $row->model_provider,
                'model_name' => $row->model_name,
                'cost_usd' => (float) $row->cost_usd,
                'latency_ms' => $row->latency_ms,
                'cache_hit' => (bool) $row->cache_hit,
                'used_rag' => (bool) $row->used_rag,
                'classification_source' => $row->classification_source,
                'created_at' => $row->created_at,
                'was_helpful' => $row->was_helpful === null ? null : (bool) $row->was_helpful,
            ])->all(),
            'meta' => [
                'current_page' => $analyses->currentPage(),
                'last_page' => $analyses->lastPage(),
                'per_page' => $analyses->perPage(),
                'total' => $analyses->total(),
            ],
            'totals' => [
                'analyses' => (int) ($totals->total ?? 0),
                'cost_usd' => round((float) ($totals->cost ?? 0), 6),
                'avg_latency_ms' => (int) ($totals->latency ?? 0),
                'avg_confidence' => round((float) ($totals->confidence ?? 0), 3),
                // The number that proves the cache earns its complexity.
                'cache_hit_rate' => $totals->total
                    ? round(((int) $totals->cached) / (int) $totals->total, 3)
                    : 0.0,
            ],
        ];
    }

    /**
     * The signature catalogue.
     *
     * Signatures, not failures: the same error forty times is one entry with a
     * count, which is the shape the information actually has and the view that
     * makes recurring problems obvious.
     */
    public function signatures(Request $request, Project $project): array
    {
        $signatures = DB::table('failure_signatures as s')
            ->join('failures as f', 'f.signature_id', '=', 's.id')
            ->where('f.project_id', $project->id)
            ->when($request->filled('category'), fn ($q) => $q->where('s.category', $request->string('category')))
            ->when($request->boolean('known'), fn ($q) => $q->where('s.is_known', true))
            ->groupBy([
                's.uuid', 's.hash', 's.category', 's.subcategory', 's.sample_error',
                's.is_known', 's.known_root_cause', 's.known_resolution',
                's.avg_resolution_seconds', 's.first_seen_at', 's.last_seen_at',
            ])
            ->selectRaw('s.uuid, s.hash, s.category, s.subcategory, s.sample_error,
                         s.is_known, s.known_root_cause, s.known_resolution,
                         s.avg_resolution_seconds, s.first_seen_at, s.last_seen_at,
                         count(f.id) as occurrences,
                         sum(case when f.resolved_at is not null then 1 else 0 end) as resolved_count,
                         max(f.uuid::text) as latest_failure_uuid')
            ->orderByRaw('count(f.id) desc')
            ->paginate(min($request->integer('per_page', 25), 100));

        return [
            'data' => collect($signatures->items())->map(fn ($row) => [
                'uuid' => $row->uuid,
                'hash' => substr($row->hash, 0, 12),
                'category' => $row->category,
                'subcategory' => $row->subcategory,
                'sample_error' => $row->sample_error,
                'occurrences' => (int) $row->occurrences,
                'resolved_count' => (int) $row->resolved_count,
                'is_known' => (bool) $row->is_known,
                'known_root_cause' => $row->known_root_cause,
                'known_resolution' => $row->known_resolution,
                'avg_resolution_seconds' => $row->avg_resolution_seconds,
                'first_seen_at' => $row->first_seen_at,
                'last_seen_at' => $row->last_seen_at,
                'latest_failure_uuid' => $row->latest_failure_uuid,
            ])->all(),
            'meta' => [
                'current_page' => $signatures->currentPage(),
                'last_page' => $signatures->lastPage(),
                'per_page' => $signatures->perPage(),
                'total' => $signatures->total(),
            ],
        ];
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
