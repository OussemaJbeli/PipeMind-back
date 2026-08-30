<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\FailureCategory;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\MetricResource;
use App\Http\Resources\PipelineListResource;
use App\Models\Project;
use App\Models\ProjectMetricDaily;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * The whole project board in one response.
 *
 * Eleven data regions. Eleven round trips would mean eleven spinners and a page
 * that assembles itself visibly. Composed server-side from project_metrics_daily,
 * which is a handful of indexed reads against a rollup and stays fast at any
 * history depth.
 */
class ProjectOverviewService
{
    private const RANGES = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public function build(Project $project, string $range = '7d'): array
    {
        $days = self::RANGES[$range] ?? 7;

        $metrics = $this->metrics($project, $days);
        $previous = $this->metrics($project, $days, offset: $days);

        return [
            'project' => $this->project($project),
            'kpis' => $this->kpis($metrics, $previous),
            'activity_chart' => $this->activityChart($metrics),
            'failure_breakdown' => $this->failureBreakdown($metrics),
            'top_categories' => $this->topCategories($metrics),
            'insight' => app(ProjectInsightService::class)->build($project, $metrics),
            'recent_pipelines' => $this->recentPipelines($project),
            'success_rate_chart' => $this->successRateChart($project),
            'recent_activity' => $this->recentActivity($project),
        ];
    }

    /** @return Collection<int,ProjectMetricDaily> */
    private function metrics(Project $project, int $days, int $offset = 0): Collection
    {
        return ProjectMetricDaily::query()
            ->where('project_id', $project->id)
            ->whereBetween('date', [
                now()->subDays($days + $offset)->toDateString(),
                now()->subDays($offset)->toDateString(),
            ])
            ->orderBy('date')
            ->get();
    }

    private function project(Project $project): array
    {
        return [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'slug' => $project->slug,
            'initials' => $project->initials(),
            'color' => $project->color,
            'icon' => $project->icon,
            'tech_stack' => $project->tech_stack ?? [],
            'provider' => $project->integration?->provider,
            'default_branch' => $project->default_branch,
            'web_url' => $project->web_url,
            'health_status' => $project->health_status,
        ];
    }

    /** The five KPI tiles, each with a sparkline series. */
    private function kpis(Collection $now, Collection $prev): array
    {
        $successRate = $this->weightedSuccessRate($now);
        $prevRate = $this->weightedSuccessRate($prev);

        $pipelines = (int) $now->sum('pipelines_total');
        $prevPipelines = (int) $prev->sum('pipelines_total');

        $failures = (int) $now->sum('failures_count');
        $prevFailures = (int) $prev->sum('failures_count');

        $avgDuration = (int) round($now->avg('avg_duration_seconds') ?? 0);
        $prevDuration = (int) round($prev->avg('avg_duration_seconds') ?? 0);

        $mttr = (int) round($now->whereNotNull('mttr_seconds')->avg('mttr_seconds') ?? 0);
        $prevMttr = (int) round($prev->whereNotNull('mttr_seconds')->avg('mttr_seconds') ?? 0);

        return [
            'pipeline_health' => MetricResource::make(
                value: round($successRate, 1),
                delta: $prevRate > 0 ? round($successRate - $prevRate, 1) : null,
                deltaLabel: $prevRate > 0
                    ? sprintf('%+.1f%% vs last %dd', $successRate - $prevRate, $now->count()) : null,
                unit: '%',
                spark: $now->pluck('success_rate')->map(fn ($v) => (float) $v)->values()->all(),
            ),

            'pipelines' => MetricResource::make(
                value: $pipelines,
                delta: $prevPipelines > 0 ? $pipelines - $prevPipelines : null,
                deltaLabel: $prevPipelines > 0
                    ? Format::deltaLabel($pipelines - $prevPipelines, 'vs previous') : null,
                spark: $now->pluck('pipelines_total')->map(fn ($v) => (int) $v)->values()->all(),
            ),

            'failures' => MetricResource::make(
                value: $failures,
                delta: $failures - $prevFailures,
                deltaLabel: Format::deltaLabel($failures - $prevFailures, 'vs previous'),
                // Fewer failures is good — a down arrow here must render green.
                positiveDirection: 'down',
                spark: $now->pluck('failures_count')->map(fn ($v) => (int) $v)->values()->all(),
            ),

            'avg_duration' => MetricResource::make(
                value: $avgDuration,
                delta: $prevDuration > 0 ? $avgDuration - $prevDuration : null,
                deltaLabel: $prevDuration > 0
                    ? Format::deltaLabel($avgDuration - $prevDuration, 's vs previous') : null,
                unit: 's',
                display: Format::duration($avgDuration),
                positiveDirection: 'down',
                spark: $now->pluck('avg_duration_seconds')->map(fn ($v) => (int) $v)->values()->all(),
            ),

            'mttr' => MetricResource::make(
                value: $mttr,
                delta: $prevMttr > 0 ? $mttr - $prevMttr : null,
                deltaLabel: $prevMttr > 0
                    ? Format::deltaLabel(round(($mttr - $prevMttr) / 60), 'm vs previous') : null,
                unit: 's',
                display: Format::duration($mttr),
                positiveDirection: 'down',
                spark: $now->pluck('mttr_seconds')->map(fn ($v) => (int) $v)->values()->all(),
            ),
        ];
    }

    /**
     * Weight by pipeline volume rather than averaging daily rates: a quiet day with
     * one failed pipeline would otherwise count as heavily as a busy day with fifty.
     */
    private function weightedSuccessRate(Collection $metrics): float
    {
        $total = (int) $metrics->sum('pipelines_total');

        return $total > 0
            ? round($metrics->sum('pipelines_success') / $total * 100, 2)
            : 0.0;
    }

    private function activityChart(Collection $metrics): array
    {
        $point = fn (string $field) => $metrics->map(fn ($m) => [
            'x' => $m->date->toDateString(),
            'y' => (int) $m->{$field},
        ])->values()->all();

        return [
            'granularity' => 'daily',
            'series' => [
                ['key' => 'success', 'label' => 'Success', 'color' => '#A9E831', 'points' => $point('pipelines_success')],
                ['key' => 'failed', 'label' => 'Failed', 'color' => '#F04438', 'points' => $point('pipelines_failed')],
                ['key' => 'running', 'label' => 'Running', 'color' => '#6366F1', 'points' => $point('pipelines_running')],
            ],
        ];
    }

    /** @return array<string,int> */
    private function categoryTotals(Collection $metrics): array
    {
        $totals = [];

        foreach ($metrics as $day) {
            foreach ($day->failures_by_category ?? [] as $category => $count) {
                $totals[$category] = ($totals[$category] ?? 0) + $count;
            }
        }

        arsort($totals);

        return $totals;
    }

    private function failureBreakdown(Collection $metrics): array
    {
        $totals = $this->categoryTotals($metrics);
        $sum = array_sum($totals);

        // Top three plus an "Others" bucket, matching the mockup's legend.
        $top = array_slice($totals, 0, 3, true);
        $others = $sum - array_sum($top);

        $items = [];
        foreach ($top as $category => $count) {
            $enum = FailureCategory::tryFrom($category) ?? FailureCategory::UNKNOWN;
            $items[] = [
                'category' => $category,
                'label' => $enum->label(),
                'count' => $count,
                'percentage' => $sum > 0 ? round($count / $sum * 100, 1) : 0.0,
                'color' => $enum->color(),
            ];
        }

        $items[] = [
            'category' => 'OTHER',
            'label' => 'Others',
            'count' => $others,
            'percentage' => $sum > 0 ? round($others / $sum * 100, 1) : 0.0,
            'color' => '#5C6472',
        ];

        return ['total' => $sum, 'items' => $items];
    }

    private function topCategories(Collection $metrics): array
    {
        $totals = $this->categoryTotals($metrics);
        $sum = array_sum($totals);

        return collect($totals)->take(5)->map(function ($count, $category) use ($sum) {
            $enum = FailureCategory::tryFrom($category) ?? FailureCategory::UNKNOWN;

            return [
                'category' => $category,
                'label' => $enum->label(),
                'icon' => $enum->icon(),
                'count' => $count,
                'percentage' => $sum > 0 ? round($count / $sum * 100, 1) : 0.0,
                'color' => $enum->color(),
            ];
        })->values()->all();
    }

    private function recentPipelines(Project $project): array
    {
        $pipelines = $project->pipelines()
            ->with(['failures:id,pipeline_id,uuid'])
            ->latest('created_at')
            ->limit(5)
            ->get();

        return PipelineListResource::collection($pipelines)->resolve();
    }

    private function successRateChart(Project $project): array
    {
        $bars = ProjectMetricDaily::query()
            ->where('project_id', $project->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn ($m) => [
                'date' => $m->date->toDateString(),
                'success_rate' => (float) $m->success_rate,
                'total' => (int) $m->pipelines_total,
                'failed' => (int) $m->pipelines_failed,
            ])->values()->all();

        $last7 = $this->weightedSuccessRate($this->metrics($project, 7));
        $prev7 = $this->weightedSuccessRate($this->metrics($project, 7, offset: 7));

        return [
            'value' => round((float) $project->success_rate, 1),
            'delta' => $prev7 > 0 ? round($last7 - $prev7, 1) : 0.0,
            'range' => '30d',
            'bars' => $bars,
        ];
    }

    private function recentActivity(Project $project): array
    {
        return ActivityResource::collection(
            $project->activityLogs()->latest('created_at')->limit(6)->get()
        )->resolve();
    }
}
