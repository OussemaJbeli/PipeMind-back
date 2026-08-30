<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Http\Resources\MetricResource;
use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/** The four KPI tiles on ui/workspace.png. */
class WorkspaceSummaryService
{
    public function forTeam(Team $team): array
    {
        $projectIds = Project::where('team_id', $team->id)->pluck('id');

        return [
            'projects' => $this->projects($team),
            'pipelines_today' => $this->pipelinesToday($projectIds),
            'failures_today' => $this->failuresToday($team),
            'success_rate' => $this->successRate($team, $projectIds),
        ];
    }

    private function projects(Team $team): array
    {
        $total = Project::where('team_id', $team->id)->count();
        $thisWeek = Project::where('team_id', $team->id)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        return MetricResource::make(
            value: $total,
            delta: $thisWeek ?: null,
            deltaLabel: $thisWeek ? "+{$thisWeek} this week" : null,
        );
    }

    private function pipelinesToday($projectIds): array
    {
        $today = Pipeline::whereIn('project_id', $projectIds)
            ->whereDate('created_at', today())->count();

        $yesterday = Pipeline::whereIn('project_id', $projectIds)
            ->whereDate('created_at', today()->subDay())->count();

        $delta = $yesterday > 0 ? round(($today - $yesterday) / $yesterday * 100, 1) : null;

        return MetricResource::make(
            value: $today,
            delta: $delta,
            deltaLabel: $delta !== null
                ? sprintf('%+.1f%% vs yesterday', $delta)
                : null,
        );
    }

    private function failuresToday(Team $team): array
    {
        $today = Failure::where('team_id', $team->id)->whereDate('failed_at', today())->count();
        $yesterday = Failure::where('team_id', $team->id)
            ->whereDate('failed_at', today()->subDay())->count();

        $delta = $yesterday > 0 ? round(($today - $yesterday) / $yesterday * 100, 1) : null;

        return MetricResource::make(
            value: $today,
            delta: $delta,
            deltaLabel: $delta !== null ? sprintf('%+.0f%% vs yesterday', $delta) : null,
            // Fewer failures is an improvement: the UI colours the arrow from this,
            // so a downward trend renders green rather than red.
            positiveDirection: 'down',
        );
    }

    private function successRate(Team $team, $projectIds): array
    {
        $current = (float) Project::where('team_id', $team->id)->avg('success_rate');

        // Compare against last week from the rollup table rather than recomputing
        // over raw pipelines, which would scan a forever-growing table.
        $lastWeek = (float) DB::table('project_metrics_daily')
            ->whereIn('project_id', $projectIds)
            ->whereBetween('date', [now()->subDays(14)->toDateString(), now()->subDays(7)->toDateString()])
            ->avg('success_rate');

        $delta = $lastWeek > 0 ? round($current - $lastWeek, 1) : null;

        return MetricResource::make(
            value: round($current, 1),
            delta: $delta,
            deltaLabel: $delta !== null ? sprintf('%+.1f%% vs last week', $delta) : null,
            unit: '%',
        );
    }
}
