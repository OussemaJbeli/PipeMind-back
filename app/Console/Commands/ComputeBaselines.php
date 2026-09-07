<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes what "normal" means, per project, per job, per branch.
 *
 * A ten-minute build is fine for one project and alarming for another, so there
 * is no global notion of slow. Everything downstream — every anomaly, every
 * "4.1× slower than usual" — is measured against these rows.
 */
class ComputeBaselines extends Command
{
    protected $signature = 'pipemind:compute-baselines
                            {--project= : Limit to one project slug}
                            {--days=30 : Rolling window}';

    protected $description = 'Recompute per-job duration baselines';

    /**
     * Below this, spread is meaningless.
     *
     * Flagging a job as anomalous against a baseline of three runs produces
     * confident nonsense, and an alert list nobody trusts is worse than none.
     */
    private const MIN_SAMPLES = 10;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);

        $projects = Project::withoutGlobalScopes()
            ->when($this->option('project'), fn ($q) => $q->where('slug', $this->option('project')))
            ->where('is_active', true)
            ->get(['id', 'slug']);

        $written = 0;

        foreach ($projects as $project) {
            $written += $this->forProject($project->id, $since, $days, groupByRef: true);
            // A wildcard rollup so a brand-new branch still has something to be
            // measured against on its first run.
            $written += $this->forProject($project->id, $since, $days, groupByRef: false);
        }

        $this->info("{$written} baseline(s) written across {$projects->count()} project(s).");

        return self::SUCCESS;
    }

    private function forProject(int $projectId, \DateTimeInterface $since, int $days, bool $groupByRef): int
    {
        $refSelect = $groupByRef ? 'p.ref' : "'*'";
        $refGroup = $groupByRef ? ', p.ref' : '';

        /*
         * All statistics computed in one pass in the database.
         *
         * Duration statistics use SUCCESSFUL runs only: a job that died after
         * four seconds would drag the mean down and make every slow-but-passing
         * run look perfectly normal. Failure and retry rates deliberately use
         * ALL runs — those rates are about failure, so excluding failures would
         * make them structurally zero.
         */
        $rows = DB::select(<<<SQL
            WITH runs AS (
                SELECT j.name AS job_name, {$refSelect} AS ref,
                       j.status, j.duration_seconds, j.peak_memory_mb, j.attempt
                FROM pipeline_jobs j
                JOIN pipelines p ON p.id = j.pipeline_id
                WHERE p.project_id = ?
                  AND j.created_at >= ?
                  AND j.name IS NOT NULL
            ),
            successful AS (
                SELECT job_name, ref, duration_seconds, peak_memory_mb
                FROM runs
                WHERE status = 'success' AND duration_seconds IS NOT NULL
            ),
            stats AS (
                SELECT job_name, ref,
                       count(*)                                                      AS sample_count,
                       avg(duration_seconds)                                         AS mean_duration,
                       stddev_samp(duration_seconds)                                 AS stddev_duration,
                       percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_seconds) AS median_duration,
                       percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_seconds) AS p95_duration,
                       avg(peak_memory_mb)                                           AS mean_memory,
                       percentile_cont(0.5) WITHIN GROUP (ORDER BY peak_memory_mb)   AS median_memory
                FROM successful
                GROUP BY job_name, ref
            ),
            -- MAD needs the median, so it is a second pass over the same rows.
            spread AS (
                SELECT s.job_name, s.ref,
                       percentile_cont(0.5) WITHIN GROUP (
                           ORDER BY abs(s.duration_seconds - st.median_duration)
                       ) AS mad_duration,
                       percentile_cont(0.5) WITHIN GROUP (
                           ORDER BY abs(s.peak_memory_mb - st.median_memory)
                       ) AS mad_memory
                FROM successful s
                JOIN stats st ON st.job_name = s.job_name AND st.ref = s.ref
                GROUP BY s.job_name, s.ref
            ),
            rates AS (
                SELECT job_name, ref,
                       count(*) FILTER (WHERE status = 'failed')::numeric
                           / NULLIF(count(*), 0)                       AS failure_rate,
                       count(*) FILTER (WHERE attempt > 1)::numeric
                           / NULLIF(count(*), 0)                       AS retry_rate
                FROM runs
                GROUP BY job_name, ref
            )
            SELECT stats.job_name, stats.ref, stats.sample_count,
                   stats.mean_duration, stats.stddev_duration, stats.median_duration,
                   stats.p95_duration, stats.mean_memory, stats.median_memory,
                   spread.mad_duration, spread.mad_memory,
                   COALESCE(rates.failure_rate, 0) AS failure_rate,
                   COALESCE(rates.retry_rate, 0)   AS retry_rate
            FROM stats
            LEFT JOIN spread ON spread.job_name = stats.job_name AND spread.ref = stats.ref
            LEFT JOIN rates  ON rates.job_name  = stats.job_name AND rates.ref  = stats.ref
            WHERE stats.sample_count >= ?
        SQL, [$projectId, $since, self::MIN_SAMPLES]);

        foreach ($rows as $row) {
            DB::table('job_baselines')->updateOrInsert(
                ['project_id' => $projectId, 'job_name' => $row->job_name, 'ref' => $row->ref],
                [
                    'sample_count' => (int) $row->sample_count,
                    'window_days' => $days,
                    'mean_duration_seconds' => $this->round($row->mean_duration),
                    'stddev_duration' => $this->round($row->stddev_duration),
                    'median_duration_seconds' => $this->round($row->median_duration),
                    'mad_duration' => $this->round($row->mad_duration),
                    'p95_duration_seconds' => $this->round($row->p95_duration),
                    'mean_memory_mb' => $this->round($row->mean_memory),
                    'median_memory_mb' => $this->round($row->median_memory),
                    'mad_memory' => $this->round($row->mad_memory),
                    'failure_rate' => round((float) $row->failure_rate, 4),
                    'retry_rate' => round((float) $row->retry_rate, 4),
                    'last_computed_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        unset($refGroup);

        return count($rows);
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
