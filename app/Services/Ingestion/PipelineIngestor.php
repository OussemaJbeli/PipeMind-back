<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Integrations\Contracts\PipelineProvider;
use App\Integrations\DTO\NormalizedJob;
use App\Integrations\DTO\NormalizedPipeline;
use App\Jobs\DetectFailure;
use App\Jobs\FetchJobLog;
use App\Jobs\RefreshProjectStats;
use App\Jobs\SyncCommitChanges;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineEvent;
use App\Models\PipelineJob;
use App\Models\PipelineStage;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified webhook delivery into rows. Idempotent throughout: a
 * redelivered or out-of-order event must converge to the same state.
 */
class PipelineIngestor
{
    public function ingest(Integration $integration, PipelineProvider $adapter, PipelineEvent $event): ?Pipeline
    {
        $payload = $event->payload;

        $project = $this->resolveProject($integration, $adapter, $payload);

        if (! $project) {
            // A repository we do not monitor. Not an error — record and move on.
            $event->update([
                'processing_status' => 'skipped',
                'processing_error' => 'No monitored project matches this payload.',
                'processed_at' => now(),
            ]);

            return null;
        }

        $normalized = $adapter->normalizePipeline($payload);

        $pipeline = DB::transaction(function () use ($project, $normalized, $adapter, $payload, $event) {
            $pipeline = $this->upsertPipeline($project, $normalized);

            $jobs = $adapter->normalizeJobs($payload);

            if ($jobs !== []) {
                $this->upsertJobs($pipeline, $jobs);
            }

            $this->recomputeCounters($pipeline);

            $event->update([
                'project_id' => $project->id,
                'pipeline_id' => $pipeline->id,
            ]);

            return $pipeline;
        });

        $this->dispatchFollowUp($integration, $project, $pipeline, $normalized);

        return $pipeline;
    }

    protected function resolveProject(
        Integration $integration,
        PipelineProvider $adapter,
        array $payload,
    ): ?Project {
        $externalId = $adapter->externalProjectId($payload);

        if (! $externalId) {
            return null;
        }

        return Project::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('external_id', $externalId)
            ->where('is_active', true)
            ->first();
    }

    protected function upsertPipeline(Project $project, NormalizedPipeline $normalized): Pipeline
    {
        $attributes = $normalized->toAttributes();

        $pipeline = Pipeline::withoutGlobalScopes()->firstOrNew([
            'project_id' => $project->id,
            'external_id' => $normalized->externalId,
        ]);

        // Events can arrive out of order. A terminal pipeline must never be
        // dragged back to "running" by a late job event.
        if ($pipeline->exists && $pipeline->status->isTerminal() && ! $this->isTerminalStatus($normalized->status)) {
            unset($attributes['status'], $attributes['finished_at'], $attributes['duration_seconds']);
        }

        $pipeline->fill(array_filter(
            $attributes,
            fn ($value) => $value !== null,
        ));

        $pipeline->project_id = $project->id;
        $pipeline->save();

        return $pipeline;
    }

    protected function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['success', 'failed', 'canceled', 'skipped', 'timeout'], true);
    }

    /** @param  array<int,NormalizedJob>  $jobs */
    protected function upsertJobs(Pipeline $pipeline, array $jobs): void
    {
        $stages = [];

        foreach ($jobs as $normalizedJob) {
            $stageName = $normalizedJob->stageName;

            $stages[$stageName] ??= PipelineStage::firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'name' => $stageName],
                ['position' => count($stages)],
            );

            $job = PipelineJob::withoutGlobalScopes()->firstOrNew([
                'pipeline_id' => $pipeline->id,
                'external_id' => $normalizedJob->externalId,
            ]);

            $job->fill(array_filter(
                $normalizedJob->toAttributes(),
                fn ($value) => $value !== null,
            ));

            $job->pipeline_id = $pipeline->id;
            $job->stage_id = $stages[$stageName]->id;
            $job->save();
        }

        foreach ($stages as $name => $stage) {
            $stageJobs = $pipeline->jobs()->where('stage_name', $name)->get();

            $stage->update([
                'jobs_count' => $stageJobs->count(),
                'status' => $this->rollUpStatus($stageJobs->pluck('status')->map(fn ($s) => $s->value)->all()),
                'started_at' => $stageJobs->min('started_at'),
                'finished_at' => $stageJobs->contains(fn ($j) => $j->finished_at === null)
                    ? null
                    : $stageJobs->max('finished_at'),
            ]);
        }
    }

    /**
     * A stage is as bad as its worst job. Ordered by severity, not by count:
     * one failure in ten passing jobs is a failed stage.
     *
     * @param  array<int,string>  $statuses
     */
    protected function rollUpStatus(array $statuses): string
    {
        foreach (['failed', 'timeout', 'running', 'canceled', 'manual', 'queued'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status === 'queued' ? 'pending' : $status;
            }
        }

        return in_array('success', $statuses, true) ? 'success' : 'skipped';
    }

    protected function recomputeCounters(Pipeline $pipeline): void
    {
        // reorder(): the jobs() relation carries orderBy('position'), and
        // PostgreSQL rejects an ORDER BY column that is neither grouped nor
        // aggregated. Inheriting a relation's ordering into an aggregate is an
        // easy thing to miss on MySQL, which permits it.
        $counts = $pipeline->jobs()
            ->reorder()
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw("count(*) filter (where status = 'success') as succeeded")
            ->first();

        $pipeline->forceFill([
            'jobs_total' => (int) ($counts->total ?? 0),
            'jobs_failed' => (int) ($counts->failed ?? 0),
            'jobs_succeeded' => (int) ($counts->succeeded ?? 0),
            'has_failure' => (int) ($counts->failed ?? 0) > 0,
        ])->save();
    }

    protected function dispatchFollowUp(
        Integration $integration,
        Project $project,
        Pipeline $pipeline,
        NormalizedPipeline $normalized,
    ): void {
        if (! $pipeline->status->isTerminal()) {
            return;
        }

        if ($pipeline->commit_sha && $pipeline->changes()->doesntExist()) {
            SyncCommitChanges::dispatch($pipeline->id);
        }

        // Green-job logs are pure storage cost.
        $fetchFor = config('pipemind.ingestion.fetch_logs_for_statuses', ['failed', 'canceled']);

        $pipeline->jobs()
            ->whereIn('status', $fetchFor)
            ->where('log_fetched', false)
            ->get()
            ->each(fn (PipelineJob $job) => FetchJobLog::dispatch($job->id));

        // A failed pipeline with no failed job still needs a failure record —
        // an infrastructure failure produces exactly that shape.
        if ($pipeline->status->isFailure() && $pipeline->jobs_failed === 0) {
            DetectFailure::dispatch($pipeline->id, null);
        }

        RefreshProjectStats::dispatch($project->id);
    }
}
