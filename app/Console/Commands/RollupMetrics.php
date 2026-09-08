<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `project_metrics_daily`, which every KPI tile and trend chart reads.
 *
 * The dashboards deliberately do not aggregate `pipelines` on each request: a
 * project with months of history would re-scan the same rows for every visitor,
 * and the sparklines need one row per day whether or not anything ran that day.
 *
 * Recomputed rather than incremented. Pipelines are mutable for a while after
 * they appear — a run finishes, a duration lands, a failure is resolved later —
 * so adding deltas would drift. Re-deriving a bounded window from the source
 * tables is cheap and always agrees with them.
 */
class RollupMetrics extends Command
{
    protected $signature = 'pipemind:rollup-metrics
                            {--project= : Limit to one project slug}
                            {--days=60 : How many days back to rebuild}';

    protected $description = 'Roll up daily project metrics';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days)->startOfDay();

        $projects = Project::withoutGlobalScopes()
            ->when($this->option('project'), fn ($q) => $q->where('slug', $this->option('project')))
            ->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects matched.');

            return self::SUCCESS;
        }

        $written = 0;

        foreach ($projects as $project) {
            $written += $this->rollUp($project, $since->toDateTimeString());
        }

        $this->info(sprintf('Rolled up %d day-rows across %d project(s).', $written, $projects->count()));

        return self::SUCCESS;
    }

    private function rollUp(Project $project, string $since): int
    {
        /*
         * One statement per project. Both halves are grouped by day and joined
         * on the date, so a day with pipelines but no failures still produces a
         * row — the sparklines need an unbroken series, and a missing day would
         * read as a gap in the chart rather than a quiet day.
         *
         * `started_at` is preferred over `created_at`: a pipeline ingested from
         * a backfill belongs to the day it ran, not the day we heard about it.
         */
        $rows = DB::select(<<<'SQL'
            WITH runs AS (
                SELECT date_trunc('day', COALESCE(p.started_at, p.created_at))::date AS day,
                       count(*)                                                       AS total,
                       count(*) FILTER (WHERE p.status = 'success')                   AS success,
                       count(*) FILTER (WHERE p.status IN ('failed', 'timeout'))       AS failed,
                       count(*) FILTER (WHERE p.status = 'canceled')                  AS canceled,
                       count(*) FILTER (WHERE p.status IN ('running', 'queued'))       AS running,
                       avg(p.duration_seconds)  FILTER (WHERE p.duration_seconds IS NOT NULL) AS avg_dur,
                       percentile_cont(0.5) WITHIN GROUP (ORDER BY p.duration_seconds)
                           FILTER (WHERE p.duration_seconds IS NOT NULL)              AS p50_dur,
                       percentile_cont(0.95) WITHIN GROUP (ORDER BY p.duration_seconds)
                           FILTER (WHERE p.duration_seconds IS NOT NULL)              AS p95_dur
                FROM pipelines p
                WHERE p.project_id = ?
                  AND COALESCE(p.started_at, p.created_at) >= ?
                GROUP BY 1
            ),
            fails AS (
                SELECT date_trunc('day', f.created_at)::date AS day,
                       count(*)                                        AS failures,
                       count(*) FILTER (WHERE f.resolved_at IS NOT NULL) AS resolved,
                       -- MTTR over failures RESOLVED that day, not raised that
                       -- day: a failure raised today and fixed tomorrow would
                       -- otherwise never contribute to any day's figure.
                       avg(EXTRACT(EPOCH FROM (f.resolved_at - f.created_at)))
                           FILTER (WHERE f.resolved_at IS NOT NULL)     AS mttr,
                       jsonb_object_agg(COALESCE(f.category, 'UNKNOWN'), c)
                           FILTER (WHERE f.category IS NOT NULL)        AS by_category
                FROM (
                    SELECT f.*, count(*) OVER (
                        PARTITION BY date_trunc('day', f.created_at), f.category
                    ) AS c
                    FROM failures f
                    WHERE f.project_id = ? AND f.created_at >= ?
                ) f
                GROUP BY 1
            ),
            spend AS (
                SELECT date_trunc('day', a.created_at)::date AS day,
                       count(*)               AS analyses,
                       COALESCE(sum(a.cost_usd), 0) AS cost
                FROM analyses a
                JOIN failures f2 ON f2.id = a.failure_id
                WHERE f2.project_id = ? AND a.created_at >= ?
                GROUP BY 1
            ),
            anomalies AS (
                SELECT date_trunc('day', an.detected_at)::date AS day, count(*) AS n
                FROM anomalies an
                WHERE an.project_id = ? AND an.detected_at >= ?
                GROUP BY 1
            )
            SELECT COALESCE(runs.day, fails.day, spend.day, anomalies.day) AS day,
                   COALESCE(runs.total, 0)     AS pipelines_total,
                   COALESCE(runs.success, 0)   AS pipelines_success,
                   COALESCE(runs.failed, 0)    AS pipelines_failed,
                   COALESCE(runs.canceled, 0)  AS pipelines_canceled,
                   COALESCE(runs.running, 0)   AS pipelines_running,
                   runs.avg_dur, runs.p50_dur, runs.p95_dur,
                   COALESCE(fails.failures, 0) AS failures_count,
                   COALESCE(fails.resolved, 0) AS failures_resolved,
                   fails.mttr,
                   fails.by_category,
                   COALESCE(spend.analyses, 0) AS analyses_count,
                   COALESCE(spend.cost, 0)     AS ai_cost_usd,
                   COALESCE(anomalies.n, 0)    AS anomalies_count
            FROM runs
            FULL OUTER JOIN fails     ON fails.day = runs.day
            FULL OUTER JOIN spend     ON spend.day = COALESCE(runs.day, fails.day)
            FULL OUTER JOIN anomalies ON anomalies.day = COALESCE(runs.day, fails.day, spend.day)
            ORDER BY 1
        SQL, [
            $project->id, $since,
            $project->id, $since,
            $project->id, $since,
            $project->id, $since,
        ]);

        foreach ($rows as $row) {
            $total = (int) $row->pipelines_total;
            $terminal = $total - (int) $row->pipelines_running;

            DB::table('project_metrics_daily')->updateOrInsert(
                ['project_id' => $project->id, 'date' => $row->day],
                [
                    'team_id' => $project->team_id,
                    'pipelines_total' => $total,
                    'pipelines_success' => (int) $row->pipelines_success,
                    'pipelines_failed' => (int) $row->pipelines_failed,
                    'pipelines_canceled' => (int) $row->pipelines_canceled,
                    'pipelines_running' => (int) $row->pipelines_running,
                    // Over runs that actually finished. Counting in-flight runs
                    // as failures would drop the rate every time a pipeline is
                    // mid-flight when the rollup happens.
                    'success_rate' => $terminal > 0
                        ? round((int) $row->pipelines_success / $terminal * 100, 2)
                        : 0,
                    // The duration columns are NOT NULL with a 0 default, and a
                    // day of failures with no recorded durations legitimately
                    // has nothing to average — so 0, not null.
                    'avg_duration_seconds' => (int) round((float) ($row->avg_dur ?? 0)),
                    'p50_duration_seconds' => (int) round((float) ($row->p50_dur ?? 0)),
                    'p95_duration_seconds' => (int) round((float) ($row->p95_dur ?? 0)),
                    'failures_count' => (int) $row->failures_count,
                    'failures_resolved' => (int) $row->failures_resolved,
                    'mttr_seconds' => $row->mttr !== null ? (int) round((float) $row->mttr) : null,
                    'failures_by_category' => $row->by_category ?? '{}',
                    'analyses_count' => (int) $row->analyses_count,
                    'ai_cost_usd' => (float) $row->ai_cost_usd,
                    'anomalies_count' => (int) $row->anomalies_count,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return count($rows);
    }
}
