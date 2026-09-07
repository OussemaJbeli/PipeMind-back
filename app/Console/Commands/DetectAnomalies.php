<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\Pipeline;
use App\Models\Project;
use App\Services\Anomalies\AnomalyDetector;
use App\Services\Anomalies\AnomalyRecorder;
use Illuminate\Console\Command;

class DetectAnomalies extends Command
{
    protected $signature = 'pipemind:detect-anomalies
                            {--project= : Limit to one project slug}
                            {--since=30 : Minutes of finished pipelines to examine}
                            {--all : Ignore the window and scan every terminal pipeline}';

    protected $description = 'Detect duration, rate and reliability anomalies';

    public function handle(AnomalyDetector $detector, AnomalyRecorder $recorder): int
    {
        $projects = Project::withoutGlobalScopes()
            ->when($this->option('project'), fn ($q) => $q->where('slug', $this->option('project')))
            ->where('is_active', true)
            // Eager: preventLazyLoading is on, and withTeam() needs the relation.
            ->with('team')
            ->get();

        $created = 0;
        $closed = 0;
        $scanned = 0;

        foreach ($projects as $project) {
            // withTeam so activity_log lands against the right workspace: this
            // runs from the scheduler, where no team is bound.
            withTeam($project->team, function () use (
                $project, $detector, $recorder, &$created, &$closed, &$scanned
            ): void {
                $pipelines = Pipeline::withoutGlobalScopes()
                    ->where('project_id', $project->id)
                    ->whereIn('status', ['success', 'failed'])
                    ->when(! $this->option('all'), fn ($q) => $q->where(
                        'finished_at', '>=', now()->subMinutes((int) $this->option('since')),
                    ))
                    ->with(['jobs', 'project'])
                    ->get();

                foreach ($pipelines as $pipeline) {
                    $scanned++;

                    foreach ($detector->forPipeline($pipeline) as $candidate) {
                        if ($recorder->record($project, $candidate)) {
                            $created++;
                        }
                    }
                }

                // Project-wide rates are computed once per run, not per pipeline:
                // they describe the project, and recomputing them per pipeline
                // would be the same answer many times over.
                foreach ($detector->forProject($project) as $candidate) {
                    if ($recorder->record($project, $candidate)) {
                        $created++;
                    }
                }

                $closed += $recorder->autoResolve($project);
            });
        }

        $open = Anomaly::withoutGlobalScopes()->where('status', 'open')->count();

        $this->info("Scanned {$scanned} pipeline(s): {$created} new, {$closed} auto-resolved, {$open} open.");

        return self::SUCCESS;
    }
}
