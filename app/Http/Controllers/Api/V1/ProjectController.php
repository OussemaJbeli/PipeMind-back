<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCardResource;
use App\Models\AiProvider;
use App\Models\Project;
use App\Services\Projects\ProjectAnalyticsService;
use App\Services\Projects\ProjectOverviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Active projects, matching the workspace.
     *
     * The workspace filtered on `is_active` and this did not, so the two pages
     * disagreed: the workspace showed one project while the list showed four,
     * three of them dead import artefacts with no pipelines. A user who reached
     * a project through the list landed on an empty board and concluded that
     * ingestion was broken.
     *
     * Deactivated projects are still reachable with `?include_inactive=1` —
     * they are not deleted, because their failure history is the knowledge base.
     */
    public function index(Request $request): array
    {
        $projects = Project::with(['lastPipeline:id,uuid,iid,status,finished_at,duration_seconds', 'integration:id,provider'])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
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

    /**
     * Everything the project settings page edits, plus what its integration
     * panel reports.
     *
     * One request: the two panels sit on one page and are two views of the same
     * project row. Splitting them would let the page contradict itself.
     */
    public function settings(Project $project): array
    {
        $project->load(['integration', 'aiProvider:id,uuid,name,provider,model']);

        return ['data' => [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'color' => $project->color,
            'icon' => $project->icon,
            'tech_stack' => $project->tech_stack ?? [],
            'default_branch' => $project->default_branch,
            'repository_url' => $project->repository_url,
            'web_url' => $project->web_url,
            'is_active' => (bool) $project->is_active,
            'auto_analyze' => (bool) $project->auto_analyze,
            'analyze_on_branches' => $project->analyze_on_branches ?: ['*'],
            'ai_provider' => $project->aiProvider ? [
                'uuid' => $project->aiProvider->uuid,
                'name' => $project->aiProvider->name,
                'model' => $project->aiProvider->model,
            ] : null,

            // The integration panel. A project imported without a working hook
            // looks perfectly fine and does nothing, so its health belongs on
            // screen rather than in a log.
            'integration' => $project->integration ? [
                'uuid' => $project->integration->uuid,
                'name' => $project->integration->name,
                'provider' => $project->integration->provider,
                'status' => $project->integration->status,
                'base_url' => $project->integration->base_url,
                'last_event_at' => $project->integration->last_event_at?->toIso8601String(),
                'last_error' => $project->integration->last_error,
                'external_path' => $project->external_path,
                'external_id' => $project->external_id,
            ] : null,

            'stats' => [
                'pipelines' => (int) $project->pipelines_count,
                'failures' => $project->failures()->count(),
                'last_pipeline_at' => $project->last_pipeline_at?->toIso8601String(),
            ],
        ]];
    }

    public function update(Request $request, Project $project): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'string', 'max:20'],
            'icon' => ['sometimes', 'string', 'max:60'],
            'tech_stack' => ['sometimes', 'array', 'max:20'],
            'tech_stack.*' => ['string', 'max:40'],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'auto_analyze' => ['sometimes', 'boolean'],
            'analyze_on_branches' => ['sometimes', 'array', 'max:20'],
            'analyze_on_branches.*' => ['string', 'max:255'],
            // Existence is checked against this team's providers, not just the
            // table. Without the rule an unknown or foreign uuid resolved to
            // null and was written as null — detaching whichever provider the
            // project was already using, and returning 200 for it. `nullable`
            // short-circuits the rest, so an explicit null still clears the
            // provider and falls back to the team default.
            'ai_provider_uuid' => ['sometimes', 'nullable', 'string',
                Rule::exists('ai_providers', 'uuid')->where('team_id', $project->team_id)],
        ]);

        if (array_key_exists('ai_provider_uuid', $data)) {
            // Safe to trust now that validation has proven the uuid belongs to
            // this team: accepting a bare id would let one workspace point a
            // project at another's credentials.
            $data['ai_provider_id'] = $data['ai_provider_uuid']
                ? AiProvider::where('uuid', $data['ai_provider_uuid'])->value('id')
                : null;

            unset($data['ai_provider_uuid']);
        }

        $project->fill($data)->save();

        activity_log($project, 'project.updated', 'info', $project->name,
            'Project settings changed: '.implode(', ', array_keys($data)), $project);

        return $this->settings($project->fresh());
    }

    /** Six analytics panels in one request — see ProjectAnalyticsService. */
    public function analytics(Request $request, Project $project, ProjectAnalyticsService $service): array
    {
        return ['data' => $service->build($project, min($request->integer('days', 30), 365))];
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
