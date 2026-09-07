<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ProjectCardResource;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\Workspace\WorkspaceSummaryService;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function summary(WorkspaceSummaryService $service): array
    {
        return ['data' => $service->forTeam(currentTeam())];
    }

    public function projects(): array
    {
        $projects = Project::query()
            ->where('is_active', true)
            // Eager-loaded: the card needs the last pipeline's status and time, and
            // N cards would otherwise be N+1 queries.
            ->with(['lastPipeline:id,uuid,iid,status,finished_at,duration_seconds', 'integration:id,provider'])
            // Unhealthy projects first — the grid should lead with what needs attention.
            ->orderByRaw("CASE health_status
                WHEN 'failing' THEN 0
                WHEN 'degraded' THEN 1
                ELSE 2 END")
            ->orderByDesc('last_pipeline_at')
            ->get();

        return ['data' => ProjectCardResource::collection($projects)->resolve()];
    }

    /**
     * The workspace feed.
     *
     * Cursor-paginated rather than offset-paginated: the feed grows while it is
     * being read, and an offset would show the same row twice or skip one as new
     * entries push the window down.
     */
    public function activity(Request $request): array
    {
        $items = ActivityLog::query()
            ->with('project:id,uuid,name,slug')
            ->when($request->filled('project'), fn ($q) => $q->whereHas(
                'project', fn ($p) => $p->where('slug', $request->string('project')),
            ))
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->string('level')))
            // Prefix match, so `analysis` covers analysis.completed, .feedback
            // and anything added later without touching the client.
            ->when($request->filled('action'), fn ($q) => $q->where(
                'action', 'like', $request->string('action').'%',
            ))
            ->latest('id')
            ->cursorPaginate(min($request->integer('limit', 25), 100));

        return [
            'data' => ActivityResource::collection($items->items())->resolve(),
            'meta' => [
                'next_cursor' => $items->nextCursor()?->encode(),
                'has_more' => $items->hasMorePages(),
            ],
            // Built from what is actually present, so the filter never offers an
            // action type this workspace has never produced.
            'filters' => [
                'actions' => ActivityLog::query()
                    ->distinct()
                    ->reorder()
                    ->pluck('action')
                    ->map(fn (string $action) => explode('.', $action)[0])
                    ->unique()->sort()->values()->all(),
            ],
        ];
    }
}
