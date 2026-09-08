<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\RollupMetrics;
use App\Events\ProjectStatsUpdated;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the denormalised counters on `projects`.
 *
 * The workspace grid renders N cards, each needing four aggregates over a
 * forever-growing table. Caching them on the row is what keeps that page fast.
 */
class RefreshProjectStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $projectId)
    {
        $this->onQueue('metrics');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("project-stats:{$this->projectId}"))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(): void
    {
        $project = Project::withoutGlobalScopes()->with('team')->find($this->projectId);

        if (! $project?->team) {
            return;
        }

        withTeam($project->team, function () use ($project): void {
            $stats = DB::table('pipelines')
                ->where('project_id', $project->id)
                ->selectRaw('count(*) as total')
                ->selectRaw("count(*) filter (where status = 'success') as succeeded")
                ->selectRaw("count(*) filter (where status in ('success','failed','timeout')) as decided")
                ->first();

            $decided = (int) ($stats->decided ?? 0);

            $failuresToday = DB::table('failures')
                ->where('project_id', $project->id)
                ->whereDate('failed_at', today())
                ->count();

            $last = DB::table('pipelines')
                ->where('project_id', $project->id)
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->first(['id', 'status', 'finished_at']);

            $successRate = $decided > 0
                ? round(((int) $stats->succeeded / $decided) * 100, 2)
                : 0.0;

            $project->forceFill([
                'pipelines_count' => (int) ($stats->total ?? 0),
                'success_rate' => $successRate,
                'failures_today' => $failuresToday,
                'last_pipeline_id' => $last->id ?? null,
                'last_pipeline_at' => $last->finished_at ?? null,
                // "Right now", deliberately distinct from the rolling success rate.
                'health_status' => match (true) {
                    $last === null => 'unknown',
                    in_array($last->status, ['failed', 'timeout'], true) => 'failing',
                    $successRate < (float) config('pipemind.health.degraded_below_success_rate') => 'degraded',
                    default => 'healthy',
                },
            ])->save();

            /*
             * Roll up today's metrics row too.
             *
             * The KPI tiles and every trend chart read `project_metrics_daily`,
             * which the hourly `pipemind:rollup-metrics` schedule maintains. A
             * project whose pipelines all arrived since the last run therefore
             * shows five zeros — indistinguishable from a broken dashboard, and
             * exactly what a brand-new project sees for its first hour.
             *
             * Recomputing one project's single current day is a bounded query,
             * and it makes the board correct the moment a run lands rather than
             * whenever cron next fires.
             */
            Artisan::call(RollupMetrics::class, [
                '--project' => $project->slug,
                '--days' => 1,
            ]);

            // The header counters are recomputed here, after ingestion settles —
            // broadcasting them with the pipeline event would send figures that
            // are one run out of date.
            ProjectStatsUpdated::dispatch($project);
        });
    }
}
