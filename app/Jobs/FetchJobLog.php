<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Integrations\LogNotAvailable;
use App\Integrations\ProviderRegistry;
use App\Models\PipelineJob;
use App\Services\Logs\LogStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchJobLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Large logs over a slow link. */
    public int $timeout = 300;

    /** @var array<int,int> */
    public array $backoff = [15, 90, 300];

    public function __construct(public int $jobId)
    {
        $this->onQueue('logs');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("job-log:{$this->jobId}"))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(ProviderRegistry $registry, LogStorage $storage): void
    {
        $job = PipelineJob::withoutGlobalScopes()
            ->with('pipeline.project.integration.team')
            ->find($this->jobId);

        $integration = $job?->pipeline?->project?->integration;

        if (! $job || ! $integration?->team || $job->log_fetched) {
            return;
        }

        withTeam($integration->team, function () use ($job, $integration, $registry, $storage): void {
            try {
                $contents = $registry->for($integration)
                    ->fetchJobLog($integration, $job->pipeline->project, $job);
            } catch (LogNotAvailable $e) {
                // An expired log is a fact about the provider, not a failure of
                // ours. Mark it so we stop retrying, and let the rest of the
                // chain proceed without it.
                Log::info('pipemind.log.unavailable', [
                    'job_id' => $job->id,
                    'reason' => $e->getMessage(),
                ]);

                $job->forceFill(['log_fetched' => true])->save();

                DetectFailure::dispatch($job->pipeline_id, $job->id);

                return;
            }

            $log = $storage->store($job, $contents);

            ProcessJobLog::dispatch($log->id);
        });
    }

    /**
     * The log never arrived — a provider outage, a revoked token, a network
     * partition. We still KNOW the job failed, and a failure with a weak error
     * message is far more useful than no failure at all: without this the
     * pipeline shows as failed on the board with nothing to click into.
     */
    public function failed(?Throwable $e): void
    {
        $job = PipelineJob::withoutGlobalScopes()->find($this->jobId);

        if (! $job) {
            return;
        }

        Log::warning('pipemind.log.fetch_failed', [
            'job_id' => $job->id,
            'reason' => $e?->getMessage(),
        ]);

        // Stop retrying the fetch, but record the failure so it is investigable.
        $job->forceFill(['log_fetched' => true])->save();

        DetectFailure::dispatch($job->pipeline_id, $job->id);
    }
}
