<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\FailureCategory;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The six analytics panels, in one query pass each.
 *
 * Assembled server-side rather than as six endpoints: the page shows one date
 * range, and six requests that could disagree about which rows they saw is a
 * dashboard that contradicts itself.
 */
class ProjectAnalyticsService
{
    /** @return array<string,mixed> */
    public function build(Project $project, int $days = 30): array
    {
        $since = now()->subDays($days);

        return [
            'range_days' => $days,
            'failure_trend' => $this->failureTrend($project, $since),
            'mttr_trend' => $this->mttrTrend($project, $since),
            'heatmap' => $this->heatmap($project, $since),
            'slowest_jobs' => $this->slowestJobs($project),
            'flakiest_jobs' => $this->flakiestJobs($project, $since),
            'top_signatures' => $this->topSignatures($project, $since),
            'ai_performance' => $this->aiPerformance($project, $since),
        ];
    }

    /** Stacked area: failures per day, split by category. */
    private function failureTrend(Project $project, \DateTimeInterface $since): array
    {
        $rows = DB::table('failures')
            ->where('project_id', $project->id)
            ->where('failed_at', '>=', $since)
            ->selectRaw("date_trunc('day', failed_at)::date as day, category, count(*) as total")
            ->groupBy('day', 'category')
            ->orderBy('day')
            ->get();

        $categories = $rows->pluck('category')->unique()->values();
        $days = $rows->pluck('day')->unique()->values();

        return [
            'days' => $days->all(),
            'series' => $categories->map(fn (string $category) => [
                'category' => $category,
                'label' => FailureCategory::tryFrom($category)?->label() ?? $category,
                'color' => FailureCategory::tryFrom($category)?->color(),
                'points' => $days->map(fn ($day) => (int) ($rows
                    ->firstWhere(fn ($r) => $r->day === $day && $r->category === $category)?->total ?? 0))->all(),
            ])->all(),
        ];
    }

    /** Mean time to resolution, per day. Falling is good — the UI must know that. */
    private function mttrTrend(Project $project, \DateTimeInterface $since): array
    {
        $rows = DB::table('failures')
            ->where('project_id', $project->id)
            ->whereNotNull('resolved_at')
            ->whereNotNull('time_to_resolution_seconds')
            ->where('resolved_at', '>=', $since)
            ->selectRaw("date_trunc('day', resolved_at)::date as day,
                         avg(time_to_resolution_seconds) as mean, count(*) as resolved")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return [
            'positive_direction' => 'down',
            'points' => $rows->map(fn ($r) => [
                'day' => $r->day,
                'minutes' => round(((float) $r->mean) / 60, 1),
                'resolved' => (int) $r->resolved,
            ])->all(),
        ];
    }

    /**
     * Day × hour failure density.
     *
     * Failures clustering at 09:00 Monday and 18:00 Friday is a story about
     * human behaviour — merge storms and end-of-week pushes — that no line chart
     * tells. Zero-filled so the grid is always complete rather than ragged.
     */
    private function heatmap(Project $project, \DateTimeInterface $since): array
    {
        $rows = DB::table('failures')
            ->where('project_id', $project->id)
            ->where('failed_at', '>=', $since)
            ->selectRaw('EXTRACT(ISODOW FROM failed_at)::int as dow,
                         EXTRACT(HOUR FROM failed_at)::int as hour, count(*) as total')
            ->groupBy('dow', 'hour')
            ->get();

        $grid = [];

        for ($day = 1; $day <= 7; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $grid[$day][$hour] = 0;
            }
        }

        foreach ($rows as $row) {
            $grid[$row->dow][$row->hour] = (int) $row->total;
        }

        return [
            'max' => (int) ($rows->max('total') ?? 0),
            'cells' => $grid,
        ];
    }

    /** Slowest jobs, with the trend against their own baseline. */
    private function slowestJobs(Project $project): array
    {
        return DB::table('job_baselines')
            ->where('project_id', $project->id)
            ->where('ref', '*')
            ->whereNotNull('median_duration_seconds')
            ->orderByDesc('median_duration_seconds')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'job_name' => $row->job_name,
                'median_seconds' => (float) $row->median_duration_seconds,
                'p95_seconds' => $row->p95_duration_seconds !== null ? (float) $row->p95_duration_seconds : null,
                'sample_count' => (int) $row->sample_count,
                'failure_rate' => (float) $row->failure_rate,
            ])->all();
    }

    /**
     * Jobs that both passed and failed on the same commit.
     *
     * The code did not change between those runs, so the job is unreliable —
     * a direct contradiction, no statistics required.
     */
    private function flakiestJobs(Project $project, \DateTimeInterface $since): array
    {
        return DB::table('pipeline_jobs as j')
            ->join('pipelines as p', 'p.id', '=', 'j.pipeline_id')
            ->where('p.project_id', $project->id)
            ->where('j.created_at', '>=', $since)
            ->whereNotNull('p.commit_sha')
            ->groupBy('j.name')
            ->havingRaw("count(DISTINCT p.commit_sha) FILTER (WHERE j.status = 'success') > 0")
            ->havingRaw("count(DISTINCT p.commit_sha) FILTER (WHERE j.status = 'failed') > 0")
            ->selectRaw("j.name,
                         count(*) FILTER (WHERE j.status = 'failed') as failures,
                         count(*) as runs,
                         count(*) FILTER (WHERE j.status = 'failed')::numeric
                             / NULLIF(count(*), 0) as flip_rate")
            ->orderByRaw('count(*) FILTER (WHERE j.status = \'failed\') DESC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'job_name' => $row->name,
                'failures' => (int) $row->failures,
                'runs' => (int) $row->runs,
                'flip_rate' => round((float) $row->flip_rate, 3),
            ])->all();
    }

    private function topSignatures(Project $project, \DateTimeInterface $since): array
    {
        return DB::table('failure_signatures as s')
            ->join('failures as f', 'f.signature_id', '=', 's.id')
            ->where('f.project_id', $project->id)
            ->where('f.failed_at', '>=', $since)
            ->groupBy('s.uuid', 's.category', 's.subcategory', 's.sample_error', 's.is_known')
            ->selectRaw('s.uuid, s.category, s.subcategory, s.sample_error, s.is_known,
                         count(f.id) as occurrences')
            ->orderByRaw('count(f.id) desc')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'category' => $row->category,
                'label' => FailureCategory::tryFrom($row->category)?->label() ?? $row->category,
                'color' => FailureCategory::tryFrom($row->category)?->color(),
                'subcategory' => $row->subcategory,
                'sample_error' => $row->sample_error,
                'occurrences' => (int) $row->occurrences,
                'is_known' => (bool) $row->is_known,
            ])->all();
    }

    /**
     * The strip that answers "is the AI any good, and what does it cost".
     *
     * Deliberately on the analytics page rather than in a separate script: these
     * are the numbers the evaluation report needs, and a number you have to run
     * a script to see is a number nobody checks.
     */
    private function aiPerformance(Project $project, \DateTimeInterface $since): array
    {
        $stats = DB::table('analyses')
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->where('failures.project_id', $project->id)
            ->where('analyses.created_at', '>=', $since)
            ->selectRaw('count(*) as total,
                         coalesce(avg(confidence), 0) as confidence,
                         coalesce(sum(cost_usd), 0) as cost,
                         coalesce(avg(latency_ms), 0) as latency,
                         count(*) FILTER (WHERE cache_hit) as cached,
                         count(*) FILTER (WHERE used_rag) as with_rag')
            ->first();

        $sources = DB::table('analyses')
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->where('failures.project_id', $project->id)
            ->where('analyses.created_at', '>=', $since)
            ->whereNotNull('classification_source')
            ->groupBy('classification_source')
            ->selectRaw('classification_source, count(*) as total')
            ->pluck('total', 'classification_source');

        $feedback = DB::table('analysis_feedback')
            ->join('analyses', 'analyses.id', '=', 'analysis_feedback.analysis_id')
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->where('failures.project_id', $project->id)
            ->selectRaw('count(*) as total, count(*) FILTER (WHERE was_helpful) as helpful')
            ->first();

        $total = (int) ($stats->total ?? 0);

        return [
            'analyses' => $total,
            'avg_confidence' => round((float) ($stats->confidence ?? 0), 3),
            'cost_usd' => round((float) ($stats->cost ?? 0), 6),
            'avg_latency_ms' => (int) ($stats->latency ?? 0),
            'cache_hit_rate' => $total ? round(((int) $stats->cached) / $total, 3) : 0.0,
            'rag_rate' => $total ? round(((int) $stats->with_rag) / $total, 3) : 0.0,
            // Null, not zero, when nobody has judged anything: "0% helpful" and
            // "nobody has said" are opposite conclusions.
            'helpful_rate' => ($feedback?->total ?? 0) > 0
                ? round(((int) $feedback->helpful) / (int) $feedback->total, 3)
                : null,
            'feedback_count' => (int) ($feedback?->total ?? 0),
            'classification_sources' => $sources->all(),
        ];
    }
}
