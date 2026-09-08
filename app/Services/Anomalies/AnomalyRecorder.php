<?php

declare(strict_types=1);

namespace App\Services\Anomalies;

use App\Events\AnomalyDetected;
use App\Models\Anomaly;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Persists anomalies, and — more importantly — stops them piling up.
 *
 * A detector that fires once per pipeline produces forty rows for one slow job,
 * and an alert list nobody trusts is worse than no alert list at all. Everything
 * here exists to keep the list short enough that a human still reads it.
 */
class AnomalyRecorder
{
    /** How many consecutive healthy runs close an open anomaly by themselves. */
    private const RECOVERY_RUNS = 3;

    /** @param  array<string,mixed>  $candidate */
    public function record(Project $project, array $candidate): ?Anomaly
    {
        // One open anomaly per (project, job, type). The same slow job seen on
        // twenty pipelines is one problem, so the row is updated rather than
        // duplicated — and `detected_at` stays at first sighting, because that
        // is when it started.
        $existing = Anomaly::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('type', $candidate['type'])
            ->where('metric_name', $candidate['metric_name'])
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        if ($existing) {
            $existing->forceFill([
                'observed_value' => $candidate['observed_value'],
                'baseline_value' => $candidate['baseline_value'],
                'deviation_ratio' => $candidate['deviation_ratio'],
                'z_score' => $candidate['z_score'] ?? null,
                // Severity can rise on a recurrence but never falls silently:
                // a critical alert that quietly becomes "low" is how a real
                // problem disappears from the top of the list.
                'severity' => $this->higher($existing->severity->value, $candidate['severity']),
                'pipeline_id' => $candidate['pipeline_id'] ?? $existing->pipeline_id,
                'job_id' => $candidate['job_id'] ?? $existing->job_id,
            ])->save();

            return null;
        }

        $anomaly = Anomaly::create([
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'pipeline_id' => $candidate['pipeline_id'] ?? null,
            'job_id' => $candidate['job_id'] ?? null,
            'type' => $candidate['type'],
            'severity' => $candidate['severity'],
            'metric_name' => $candidate['metric_name'],
            'observed_value' => $candidate['observed_value'],
            'baseline_value' => $candidate['baseline_value'],
            'deviation_ratio' => $candidate['deviation_ratio'],
            'z_score' => $candidate['z_score'] ?? null,
            'detection_method' => $candidate['detection_method'] ?? 'mad',
            'title' => $candidate['title'],
            'description' => $candidate['description'] ?? null,
            'possible_causes' => $candidate['possible_causes'] ?? [],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        activity_log(
            $project,
            'anomaly.detected',
            $candidate['severity'] === 'critical' ? 'error' : 'warning',
            $project->name,
            $candidate['title'],
            $anomaly,
        );

        // Only reached for a NEW anomaly — the recurrence path above returns
        // early, so a slow job seen on twenty pipelines toasts once, not twenty
        // times.
        AnomalyDetected::dispatch($anomaly->setRelation('project', $project));

        return $anomaly;
    }

    /**
     * Closes anomalies whose metric has come back to normal.
     *
     * Nobody acknowledges a stale alert. Without this the list only ever grows,
     * and within a week it is ignored entirely — which costs more than never
     * having built the detector.
     *
     * @return int Number closed.
     */
    public function autoResolve(Project $project): int
    {
        $closed = 0;

        $open = Anomaly::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('status', 'open')
            ->whereIn('type', ['duration', 'memory'])
            ->get();

        foreach ($open as $anomaly) {
            if ($this->hasRecovered($anomaly)) {
                $anomaly->forceFill(['status' => 'resolved'])->save();
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Back within 2 MAD of the median for three consecutive runs.
     *
     * Checks the metric the anomaly is actually about. An earlier version always
     * compared durations, so a memory anomaly closed itself because the job's
     * *runtime* recovered — silently discarding a live alert on the evidence of
     * an unrelated measurement.
     */
    private function hasRecovered(Anomaly $anomaly): bool
    {
        $jobName = $this->jobNameFrom($anomaly->metric_name);

        if (! $jobName) {
            return false;
        }

        $memory = $anomaly->type === 'memory';
        $column = $memory ? 'peak_memory_mb' : 'duration_seconds';
        $medianColumn = $memory ? 'median_memory_mb' : 'median_duration_seconds';
        $madColumn = $memory ? 'mad_memory' : 'mad_duration';

        $baseline = DB::table('job_baselines')
            ->where('project_id', $anomaly->project_id)
            ->where('job_name', $jobName)
            ->orderByRaw("ref = '*'")
            ->first();

        if (! $baseline || ! $baseline->{$madColumn} || ! $baseline->{$medianColumn}) {
            return false;
        }

        $recent = DB::table('pipeline_jobs as j')
            ->join('pipelines as p', 'p.id', '=', 'j.pipeline_id')
            ->where('p.project_id', $anomaly->project_id)
            ->where('j.name', $jobName)
            ->where('j.status', 'success')
            ->whereNotNull("j.{$column}")
            ->where('j.id', '>', $anomaly->job_id ?? 0)
            ->orderByDesc('j.id')
            ->limit(self::RECOVERY_RUNS)
            ->pluck("j.{$column}");

        // Fewer than three runs since is not evidence of recovery — it is
        // absence of evidence, and closing on it would hide a live problem.
        if ($recent->count() < self::RECOVERY_RUNS) {
            return false;
        }

        $ceiling = (float) $baseline->{$medianColumn} + 2 * (float) $baseline->{$madColumn};

        return $recent->every(fn ($value) => (float) $value <= $ceiling);
    }

    /** `job.backend-tests.duration_seconds` → `backend-tests` */
    private function jobNameFrom(string $metric): ?string
    {
        if (! str_starts_with($metric, 'job.')) {
            return null;
        }

        $parts = explode('.', $metric);
        array_shift($parts);
        array_pop($parts);

        return $parts ? implode('.', $parts) : null;
    }

    private function higher(string $current, string $candidate): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }
}
