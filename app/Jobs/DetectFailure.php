<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Services\Failures\FailureDetectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DetectFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @param  array<string,mixed>  $context */
    public function __construct(
        public int $pipelineId,
        public ?int $jobId = null,
        public array $context = [],
    ) {
        $this->onQueue('ingestion');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("detect-failure:{$this->pipelineId}:".($this->jobId ?? 'none')))
                ->releaseAfter(5)->expireAfter(120),
        ];
    }

    public function handle(FailureDetectionService $detector): void
    {
        $pipeline = Pipeline::withoutGlobalScopes()
            ->with('project.team')
            ->find($this->pipelineId);

        if (! $pipeline?->project?->team) {
            return;
        }

        $job = $this->jobId
            ? PipelineJob::withoutGlobalScopes()->find($this->jobId)
            : null;

        withTeam($pipeline->project->team, function () use ($pipeline, $job, $detector): void {
            $failure = $detector->detect($pipeline, $job, $this->context);

            if (! $failure) {
                return;
            }

            activity_log(
                $pipeline->project,
                'failure.detected',
                'error',
                $pipeline->project->name,
                sprintf('Pipeline #%s failed on %s', $pipeline->iid, $job?->name ?? 'the pipeline'),
                $failure,
            );

            // Auto-analysis lands in roadmaps/10. Flaky failures are excluded
            // there: analysing the same flake twenty times is the fastest way to
            // exhaust a budget for zero insight.
            if (
                class_exists(AnalyzeFailure::class)
                && $pipeline->project->auto_analyze
                && ! $failure->is_flaky
                && $pipeline->project->shouldAnalyzeRef($pipeline->ref)
            ) {
                AnalyzeFailure::dispatch($failure->id);
            }
        });
    }
}
