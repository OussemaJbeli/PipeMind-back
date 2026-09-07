<?php

declare(strict_types=1);

namespace App\Services\Anomalies;

use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The eight detectors.
 *
 * Each returns candidate anomalies; persistence, deduplication and auto-resolve
 * are handled by the caller so a detector never has to know about the lifecycle
 * of an alert.
 */
class AnomalyDetector
{
    /** Nothing is flagged against a baseline thinner than this. */
    private const MIN_SAMPLES = 10;

    /** @return array<int,array<string,mixed>> */
    public function forPipeline(Pipeline $pipeline): array
    {
        $pipeline->loadMissing(['jobs', 'project']);

        $found = [];

        foreach ($pipeline->jobs as $job) {
            $baseline = $this->baselineFor($pipeline->project_id, $job->name, $pipeline->ref);

            if (! $baseline || $baseline->sample_count < self::MIN_SAMPLES) {
                continue;
            }

            foreach (['duration', 'memory'] as $metric) {
                $candidate = $this->{$metric.'Anomaly'}($pipeline, $job, $baseline);

                if ($candidate) {
                    $found[] = $candidate;
                }
            }
        }

        foreach (['queueTime', 'logSize', 'flakyTest'] as $detector) {
            $found = [...$found, ...$this->{$detector.'Anomalies'}($pipeline)];
        }

        return $found;
    }

    /** @return array<int,array<string,mixed>> Project-wide rates, which no single pipeline can reveal. */
    public function forProject(Project $project): array
    {
        return array_values(array_filter([
            $this->failureRateAnomaly($project),
            $this->retryRateAnomaly($project),
            $this->testCountAnomaly($project),
        ]));
    }

    /* ── per-job detectors ──────────────────────────────────────────────── */

    private function durationAnomaly(Pipeline $pipeline, $job, object $baseline): ?array
    {
        if ($job->duration_seconds === null || $job->status->value !== 'success') {
            // Only successful runs are compared: a job that failed fast is not
            // "unusually quick", it is broken, and that is a different alert.
            return null;
        }

        $score = Statistics::modifiedZScore(
            (float) $job->duration_seconds,
            (float) $baseline->median_duration_seconds,
            (float) $baseline->mad_duration,
        );

        // Only slower matters. A build that suddenly got faster is good news, and
        // alerting on it is how people learn to ignore the alert list.
        if ($score <= 0 || ! ($severity = Statistics::severityFor($score))) {
            return null;
        }

        $ratio = Statistics::deviationRatio((float) $job->duration_seconds, (float) $baseline->median_duration_seconds);

        return [
            'type' => 'duration',
            'severity' => $severity,
            'pipeline_id' => $pipeline->id,
            'job_id' => $job->id,
            'metric_name' => "job.{$job->name}.duration_seconds",
            'observed_value' => $job->duration_seconds,
            'baseline_value' => $baseline->median_duration_seconds,
            'deviation_ratio' => $ratio,
            'z_score' => round($score, 3),
            'title' => "{$job->name} took {$ratio}× longer than usual",
            'description' => sprintf(
                'Ran in %s against a median of %s across %d runs in the last %d days.',
                $this->duration((int) $job->duration_seconds),
                $this->duration((int) $baseline->median_duration_seconds),
                $baseline->sample_count,
                $baseline->window_days,
            ),
            'possible_causes' => [
                'Dependency download slow or retried',
                'Build cache miss',
                'Runner resource contention',
                'More work added to this job',
            ],
        ];
    }

    private function memoryAnomaly(Pipeline $pipeline, $job, object $baseline): ?array
    {
        // Most providers never report this. Absence is normal, not a finding.
        if ($job->peak_memory_mb === null || ! $baseline->median_memory_mb) {
            return null;
        }

        // The same robust z-score duration uses. An earlier version compared
        // against a hardcoded 1.5x ratio and flagged 22 of 24 seeded jobs —
        // a threshold that fires on almost everything is noise with a severity
        // label attached.
        $score = Statistics::modifiedZScore(
            (float) $job->peak_memory_mb,
            (float) $baseline->median_memory_mb,
            (float) $baseline->mad_memory,
        );

        // Only upward. Using less memory is not a problem.
        if ($score <= 0 || ! ($severity = Statistics::severityFor($score))) {
            return null;
        }

        $ratio = Statistics::deviationRatio((float) $job->peak_memory_mb, (float) $baseline->median_memory_mb);

        return [
            'type' => 'memory',
            'severity' => $severity,
            'pipeline_id' => $pipeline->id,
            'job_id' => $job->id,
            'metric_name' => "job.{$job->name}.peak_memory_mb",
            'observed_value' => $job->peak_memory_mb,
            'baseline_value' => $baseline->median_memory_mb,
            'deviation_ratio' => $ratio,
            'z_score' => round($score, 3),
            'title' => "{$job->name} used {$ratio}x its usual memory",
            'description' => sprintf(
                'Peaked at %s MB against a median of %s MB across %d runs.',
                $job->peak_memory_mb, round((float) $baseline->median_memory_mb), $baseline->sample_count,
            ),
            'possible_causes' => ['Larger dataset or fixture', 'Memory leak', 'Increased parallelism'],
        ];
    }

    /* ── per-pipeline detectors ─────────────────────────────────────────── */

    /** @return array<int,array<string,mixed>> */
    private function queueTimeAnomalies(Pipeline $pipeline): array
    {
        // Queue time is a runner-capacity signal, not a code signal — which is
        // why it is worth separating from duration.
        if (! $pipeline->queue_seconds || $pipeline->queue_seconds < 120) {
            return [];
        }

        // percentile_cont, not avg: queue time is long-tailed, and one blocked
        // pipeline would drag a mean far enough to hide the next one.
        $median = DB::table(DB::raw('(
            SELECT queue_seconds FROM pipelines
            WHERE project_id = ? AND id < ? AND queue_seconds IS NOT NULL
            ORDER BY id DESC LIMIT 50
        ) recent'))
            ->setBindings([$pipeline->project_id, $pipeline->id])
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY queue_seconds) as median')
            ->value('median');

        $ratio = Statistics::deviationRatio((float) $pipeline->queue_seconds, (float) ($median ?? 0));

        if (! $ratio || $ratio < 3) {
            return [];
        }

        return [[
            'type' => 'queue_time',
            'severity' => $ratio >= 6 ? 'high' : 'medium',
            'pipeline_id' => $pipeline->id,
            'job_id' => null,
            'metric_name' => 'pipeline.queue_seconds',
            'observed_value' => $pipeline->queue_seconds,
            'baseline_value' => $median,
            'deviation_ratio' => $ratio,
            'z_score' => null,
            'detection_method' => 'rule',
            'title' => "Pipelines are waiting {$ratio}× longer to start",
            'description' => sprintf('Waited %s before starting, against a usual %s.',
                $this->duration((int) $pipeline->queue_seconds), $this->duration((int) $median)),
            'possible_causes' => ['Runner capacity exhausted', 'A long queue of concurrent pipelines', 'Runner offline'],
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private function logSizeAnomalies(Pipeline $pipeline): array
    {
        $found = [];

        $logs = DB::table('job_logs')
            ->join('pipeline_jobs', 'pipeline_jobs.id', '=', 'job_logs.job_id')
            ->where('job_logs.pipeline_id', $pipeline->id)
            ->select('job_logs.line_count', 'pipeline_jobs.id as job_id', 'pipeline_jobs.name')
            ->get();

        foreach ($logs as $log) {
            // A runaway log is almost always a retry loop or a warning printed
            // once per file — cheap to detect and easy to miss by eye.
            if ($log->line_count < 50_000) {
                continue;
            }

            $found[] = [
                'type' => 'log_size',
                'severity' => $log->line_count >= 200_000 ? 'high' : 'medium',
                'pipeline_id' => $pipeline->id,
                'job_id' => $log->job_id,
                'metric_name' => "job.{$log->name}.log_lines",
                'observed_value' => $log->line_count,
                'baseline_value' => 50_000,
                'deviation_ratio' => round($log->line_count / 50_000, 3),
                'z_score' => null,
                'detection_method' => 'rule',
                'title' => "{$log->name} produced an unusually large log",
                'description' => number_format($log->line_count).' lines. Often a retry loop or a repeated warning.',
                'possible_causes' => ['A retry loop', 'Verbose logging left enabled', 'A warning printed per file'],
            ];
        }

        return $found;
    }

    /**
     * The same test passing and failing on the same commit.
     *
     * No statistics needed — it is a direct contradiction, and the strongest
     * possible evidence that a test is unreliable rather than the code broken.
     *
     * @return array<int,array<string,mixed>>
     */
    private function flakyTestAnomalies(Pipeline $pipeline): array
    {
        if (! $pipeline->commit_sha) {
            return [];
        }

        $conflicting = DB::table('pipeline_jobs as j')
            ->join('pipelines as p', 'p.id', '=', 'j.pipeline_id')
            ->where('p.project_id', $pipeline->project_id)
            ->where('p.commit_sha', $pipeline->commit_sha)
            ->groupBy('j.name')
            ->havingRaw("count(*) FILTER (WHERE j.status = 'success') > 0")
            ->havingRaw("count(*) FILTER (WHERE j.status = 'failed') > 0")
            ->pluck('j.name');

        return $conflicting->map(fn (string $name) => [
            'type' => 'flaky_test',
            'severity' => 'medium',
            'pipeline_id' => $pipeline->id,
            'job_id' => null,
            'metric_name' => "job.{$name}.flaky",
            'observed_value' => 1,
            'baseline_value' => 0,
            'deviation_ratio' => null,
            'z_score' => null,
            'detection_method' => 'rule',
            'title' => "{$name} both passed and failed on the same commit",
            'description' => sprintf(
                'Commit %s produced both outcomes for this job. The code did not change between runs, so the test is unreliable.',
                substr($pipeline->commit_sha, 0, 8),
            ),
            'possible_causes' => ['Timing or race condition', 'Shared state between tests', 'External service dependency'],
        ])->all();
    }

    /* ── project-wide detectors ─────────────────────────────────────────── */

    private function failureRateAnomaly(Project $project): ?array
    {
        $recent = $this->rateWindow($project->id, 7);
        $base = $this->rateWindow($project->id, 30);

        if ($recent->total < 10 || $base->total < 20) {
            return null;
        }

        $score = Statistics::proportionZ($recent->failed, $recent->total, $base->failed, $base->total);

        if ($score <= 0 || ! ($severity = Statistics::severityFor($score * 2))) {
            return null;
        }

        $recentRate = $recent->failed / $recent->total;
        $baseRate = $base->failed / $base->total;

        return [
            'type' => 'failure_rate',
            'severity' => $severity,
            'pipeline_id' => null,
            'job_id' => null,
            'metric_name' => 'project.failure_rate',
            'observed_value' => round($recentRate, 4),
            'baseline_value' => round($baseRate, 4),
            'deviation_ratio' => Statistics::deviationRatio($recentRate, $baseRate),
            'z_score' => round($score, 3),
            'detection_method' => 'zscore',
            'title' => sprintf('Failure rate rose to %d%% this week', round($recentRate * 100)),
            'description' => sprintf(
                '%d of %d runs failed in the last 7 days, against %d%% over 30 days.',
                $recent->failed, $recent->total, round($baseRate * 100),
            ),
            'possible_causes' => ['A regression merged recently', 'A flaky test becoming more frequent', 'Infrastructure degradation'],
        ];
    }

    private function retryRateAnomaly(Project $project): ?array
    {
        $retried = DB::table('pipeline_jobs as j')
            ->join('pipelines as p', 'p.id', '=', 'j.pipeline_id')
            ->where('p.project_id', $project->id)
            ->where('j.created_at', '>=', now()->subDay())
            ->selectRaw('count(*) FILTER (WHERE j.attempt > 1) as retried, count(*) as total')
            ->first();

        if (! $retried || $retried->total < 10) {
            return null;
        }

        $baseline = (float) (DB::table('job_baselines')
            ->where('project_id', $project->id)->where('ref', '*')->avg('retry_rate') ?? 0);

        $rate = $retried->retried / $retried->total;

        // A stable job that starts retrying is the signal; a project that always
        // retries is a known cost, not news.
        if ($rate < 0.15 || $rate <= $baseline * 2) {
            return null;
        }

        return [
            'type' => 'retry_rate',
            'severity' => $rate >= 0.4 ? 'high' : 'medium',
            'pipeline_id' => null,
            'job_id' => null,
            'metric_name' => 'project.retry_rate',
            'observed_value' => round($rate, 4),
            'baseline_value' => round($baseline, 4),
            'deviation_ratio' => Statistics::deviationRatio($rate, $baseline ?: null),
            'z_score' => null,
            'detection_method' => 'rule',
            'title' => sprintf('%d%% of jobs were retried in the last 24 hours', round($rate * 100)),
            'description' => sprintf('%d of %d jobs needed more than one attempt.', $retried->retried, $retried->total),
            'possible_causes' => ['Transient infrastructure failures', 'A newly flaky test', 'Network instability'],
        ];
    }

    /**
     * Tests silently disappearing.
     *
     * A real and easily missed regression: a build config change can skip an
     * entire suite, everything goes green, and coverage quietly drops to nothing.
     */
    private function testCountAnomaly(Project $project): ?array
    {
        $recent = DB::table('failures')
            ->where('project_id', $project->id)
            ->where('category', 'TEST')
            ->where('failed_at', '>=', now()->subDays(7))
            ->count();

        $previous = DB::table('failures')
            ->where('project_id', $project->id)
            ->where('category', 'TEST')
            ->whereBetween('failed_at', [now()->subDays(30), now()->subDays(7)])
            ->count();

        // Only meaningful when there was a real test-failure history to vanish.
        if ($previous < 10 || $recent > 0) {
            return null;
        }

        return [
            'type' => 'test_count',
            'severity' => 'medium',
            'pipeline_id' => null,
            'job_id' => null,
            'metric_name' => 'project.test_failures',
            'observed_value' => 0,
            'baseline_value' => $previous,
            'deviation_ratio' => 0.0,
            'z_score' => null,
            'detection_method' => 'rule',
            'title' => 'Test failures stopped appearing entirely',
            'description' => sprintf(
                '%d test failures in the previous three weeks, none in the last seven days. Either the suite was fixed, or it stopped running.',
                $previous,
            ),
            'possible_causes' => ['Tests skipped by a config change', 'The test job was removed', 'Genuinely fixed'],
        ];
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    private function rateWindow(int $projectId, int $days): object
    {
        return DB::table('pipelines')
            ->where('project_id', $projectId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereIn('status', ['success', 'failed'])
            ->selectRaw("count(*) FILTER (WHERE status = 'failed') as failed, count(*) as total")
            ->first() ?? (object) ['failed' => 0, 'total' => 0];
    }

    private function baselineFor(int $projectId, ?string $jobName, string $ref): ?object
    {
        if (! $jobName) {
            return null;
        }

        return DB::table('job_baselines')
            ->where('project_id', $projectId)
            ->where('job_name', $jobName)
            ->whereIn('ref', [$ref, '*'])
            // An exact-branch baseline beats the wildcard rollup.
            ->orderByRaw("ref = '*'")
            ->first();
    }

    private function duration(int $seconds): string
    {
        return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }
}
