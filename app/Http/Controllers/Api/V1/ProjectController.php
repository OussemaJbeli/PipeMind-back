<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCardResource;
use App\Models\Project;
use App\Services\Projects\ProjectOverviewService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(): array
    {
        $projects = Project::with('lastPipeline:id,uuid,iid,status,finished_at,duration_seconds')
            ->orderBy('name')
            ->get();

        return ['data' => ProjectCardResource::collection($projects)->resolve()];
    }

    public function show(Project $project): array
    {
        $project->load(['integration:id,provider,name', 'aiProvider:id,name,provider,model']);

        return ['data' => [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'initials' => $project->initials(),
            'color' => $project->color,
            'icon' => $project->icon,
            'tech_stack' => $project->tech_stack ?? [],
            'default_branch' => $project->default_branch,
            'repository_url' => $project->repository_url,
            'web_url' => $project->web_url,
            'provider' => $project->integration?->provider,
            'is_active' => $project->is_active,
            'auto_analyze' => $project->auto_analyze,
            'analyze_on_branches' => $project->analyze_on_branches,
            'health_status' => $project->health_status,
            'success_rate' => (float) $project->success_rate,
            'pipelines_count' => (int) $project->pipelines_count,
            'failures_today' => (int) $project->failures_today,
        ]];
    }

    /** The whole board in one request — see ProjectOverviewService. */
    public function overview(Request $request, Project $project, ProjectOverviewService $service): array
    {
        $range = $request->string('range', '7d')->toString();

        return ['data' => $service->build($project, $range)];
    }

    public function insights(Request $request, Project $project, ProjectOverviewService $service): array
    {
        $data = $service->build($project, $request->string('range', '7d')->toString());

        return ['data' => $data['insight']];
    }
}
