<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\FailureCategory;
use App\Models\Anomaly;
use App\Models\Failure;
use App\Models\Project;
use App\Models\ProjectMetricDaily;
use Illuminate\Support\Collection;

/**
 * The AI Insight card on ui/project.png.
 *
 * Deliberately rule-based, not an LLM call. These are aggregate facts already sitting
 * in project_metrics_daily; a model would be slower, cost money, and occasionally be
 * wrong about arithmetic. Save the model for failures where reasoning is required.
 *
 * Rules are ordered by usefulness — the first match wins.
 */
class ProjectInsightService
{
    public function build(Project $project, Collection $metrics): ?array
    {
        foreach ([
            'anomalyInsight',
            'categorySpikeInsight',
            'repeatSignatureInsight',
            'dominantCategoryInsight',
            'successRateDropInsight',
            'flakyInsight',
        ] as $rule) {
            if ($insight = $this->{$rule}($project, $metrics)) {
                return $insight;
            }
        }

        return null;
    }

    /** An open duration anomaly is the most actionable thing we can surface. */
    private function anomalyInsight(Project $project, Collection $metrics): ?array
    {
        $anomaly = Anomaly::where('project_id', $project->id)
            ->where('status', 'open')
            ->orderByDesc('deviation_ratio')
            ->first();

        if (! $anomaly || (float) $anomaly->deviation_ratio < 2.0) {
            return null;
        }

        return [
            'type' => 'anomaly',
            'headline' => $anomaly->title,
            'detail' => $anomaly->description,
            'severity' => $anomaly->severity->value === 'critical' ? 'critical' : 'warning',
            'confidence' => 0.95,
            'action' => [
                'label' => 'Investigate',
                'route' => 'analytics',
                'params' => ['anomaly' => $anomaly->uuid],
            ],
        ];
    }

    /** A category running well above its own 30-day baseline. */
    private function categorySpikeInsight(Project $project, Collection $metrics): ?array
    {
        $recent = $this->categoryTotals(
            ProjectMetricDaily::where('project_id', $project->id)
                ->where('date', '>=', now()->subDay()->toDateString())->get()
        );

        if (! $recent) {
            return null;
        }

        $baseline = $this->categoryTotals(
            ProjectMetricDaily::where('project_id', $project->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())->get()
        );

        arsort($recent);
        $category = array_key_first($recent);
        $count = $recent[$category];

        if ($count < 2) {
            return null;
        }

        $dailyBaseline = ($baseline[$category] ?? 0) / 30;
        if ($dailyBaseline <= 0) {
            return null;
        }

        $increase = round(($count - $dailyBaseline) / $dailyBaseline * 100);
        if ($increase < 25) {
            return null;
        }

        $enum = FailureCategory::tryFrom($category) ?? FailureCategory::UNKNOWN;

        return [
            'type' => 'pattern',
            'headline' => sprintf(
                '%s issues detected in %d pipelines in the last 24h.',
                $enum->label(), $count
            ),
            'detail' => sprintf('This is %d%% higher than your normal baseline.', $increase),
            'severity' => 'warning',
            'confidence' => 0.87,
            'action' => [
                'label' => 'Investigate',
                'route' => 'failures',
                'params' => ['category' => $category, 'range' => '24h'],
            ],
        ];
    }

    /** The same error signature failing repeatedly is the clearest waste signal. */
    private function repeatSignatureInsight(Project $project, Collection $metrics): ?array
    {
        $repeat = Failure::query()
            ->where('project_id', $project->id)
            ->where('failed_at', '>=', now()->subDays(7))
            ->whereNotNull('signature_id')
            ->selectRaw('signature_id, count(*) as occurrences')
            ->groupBy('signature_id')
            ->havingRaw('count(*) >= 3')
            ->orderByDesc('occurrences')
            ->first();

        if (! $repeat) {
            return null;
        }

        $failure = Failure::where('signature_id', $repeat->signature_id)
            ->where('project_id', $project->id)
            ->with('signature')
            ->latest('failed_at')
            ->first();

        $enum = $failure->category;

        return [
            'type' => 'recurrence',
            'headline' => sprintf(
                'The same %s failure has broken %d pipelines this week.',
                strtolower($enum->label()), $repeat->occurrences
            ),
            'detail' => $failure->signature?->is_known
                ? 'A confirmed resolution exists for this error.'
                : 'This error has no confirmed resolution yet.',
            'severity' => 'warning',
            'confidence' => 1.0,
            'action' => [
                'label' => 'View failure',
                'route' => 'failure',
                'params' => ['uuid' => $failure->uuid],
            ],
        ];
    }

    private function dominantCategoryInsight(Project $project, Collection $metrics): ?array
    {
        $totals = $this->categoryTotals($metrics);
        $sum = array_sum($totals);

        if ($sum < 3) {
            return null;
        }

        arsort($totals);
        $category = array_key_first($totals);
        $share = round($totals[$category] / $sum * 100);

        if ($share < 40) {
            return null;
        }

        $enum = FailureCategory::tryFrom($category) ?? FailureCategory::UNKNOWN;

        return [
            'type' => 'concentration',
            'headline' => sprintf(
                '%s issues account for %d%% of failures in this range.',
                $enum->label(), $share
            ),
            'detail' => 'Fixing this category would remove most of your pipeline failures.',
            'severity' => 'info',
            'confidence' => 1.0,
            'action' => [
                'label' => 'Investigate',
                'route' => 'failures',
                'params' => ['category' => $category],
            ],
        ];
    }

    private function successRateDropInsight(Project $project, Collection $metrics): ?array
    {
        if ($metrics->count() < 6) {
            return null;
        }

        $half = (int) floor($metrics->count() / 2);
        $earlier = $metrics->take($half);
        $later = $metrics->skip($half);

        $rate = function (Collection $c): float {
            $total = (int) $c->sum('pipelines_total');

            return $total > 0 ? $c->sum('pipelines_success') / $total * 100 : 0.0;
        };

        $before = $rate($earlier);
        $after = $rate($later);

        if ($before <= 0 || $before - $after < 10) {
            return null;
        }

        return [
            'type' => 'trend',
            'headline' => sprintf(
                'Success rate dropped from %d%% to %d%% in this range.',
                round($before), round($after)
            ),
            'detail' => 'Something changed partway through the period.',
            'severity' => 'warning',
            'confidence' => 0.9,
            'action' => ['label' => 'View pipelines', 'route' => 'pipelines', 'params' => []],
        ];
    }

    private function flakyInsight(Project $project, Collection $metrics): ?array
    {
        $flaky = Failure::where('project_id', $project->id)
            ->where('is_flaky', true)
            ->where('failed_at', '>=', now()->subDays(7))
            ->count();

        if ($flaky < 2) {
            return null;
        }

        return [
            'type' => 'flaky',
            'headline' => sprintf('%d failures this week passed and failed on the same commit.', $flaky),
            'detail' => 'These are likely flaky tests rather than real regressions.',
            'severity' => 'info',
            'confidence' => 0.85,
            'action' => ['label' => 'View failures', 'route' => 'failures', 'params' => ['flaky' => 1]],
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

        return $totals;
    }
}
