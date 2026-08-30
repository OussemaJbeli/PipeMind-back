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
            ->with('lastPipeline:id,uuid,iid,status,finished_at,duration_seconds')
            // Unhealthy projects first — the grid should lead with what needs attention.
            ->orderByRaw("CASE health_status
                WHEN 'failing' THEN 0
                WHEN 'degraded' THEN 1
                ELSE 2 END")
            ->orderByDesc('last_pipeline_at')
            ->get();

        return ['data' => ProjectCardResource::collection($projects)->resolve()];
    }

    public function activity(Request $request): array
    {
        $items = ActivityLog::query()
            ->with('project:id,uuid,name,slug')
            ->latest('created_at')
            ->limit(min($request->integer('limit', 10), 50))
            ->get();

        return ['data' => ActivityResource::collection($items)->resolve()];
    }
}
