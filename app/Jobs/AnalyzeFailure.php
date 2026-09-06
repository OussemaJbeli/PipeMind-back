<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Ai\AiBudgetExceeded;
use App\Exceptions\Ai\AiServiceUnavailable;
use App\Exceptions\Ai\NoLocalProviderConfigured;
use App\Models\Analysis;
use App\Models\Failure;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequestRecorder;
use App\Services\Ai\AnalysisCache;
use App\Services\Ai\AnalysisContextBuilder;
use App\Services\Ai\AnalysisPersister;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /** @var array<int,int> */
    public array $backoff = [30, 180];

    public function __construct(
        public int $failureId,
        public bool $force = false,
    ) {
        $this->onQueue('analysis');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("analyze:{$this->failureId}"),
            new RateLimited('ai-analysis'),
        ];
    }

    public function handle(
        AiGateway $ai,
        AnalysisContextBuilder $builder,
        AnalysisCache $cache,
        AnalysisPersister $persister,
        AiRequestRecorder $recorder,
    ): void {
        $failure = Failure::withoutGlobalScopes()
            ->with('project.team', 'signature')
            ->find($this->failureId);

        if (! $failure?->project?->team) {
            return;
        }

        withTeam($failure->project->team, function () use (
            $failure, $ai, $builder, $cache, $persister, $recorder
        ): void {
            if ($failure->status === 'analyzed' && ! $this->force) {
                return;
            }

            // Flaky failures are noise. Analysing the same flake twenty times is
            // the fastest way to exhaust a budget for zero insight.
            if ($failure->is_flaky && ! $this->force) {
                $failure->update(['status' => 'analyzed']);

                return;
            }

            $failure->update(['status' => 'analyzing']);

            $analysis = Analysis::create([
                'failure_id' => $failure->id,
                'team_id' => $failure->team_id,
                'status' => 'running',
                'contract_version' => config('pipemind.ai.contract_version'),
                'started_at' => now(),
            ]);

            try {
                // Same signature, same project, recent → reuse. Most of the cost
                // saving lives here once a team has any history at all.
                if (! $this->force && $cached = $cache->get($failure)) {
                    $persister->persistCached($analysis, $failure, $cached);

                    return;
                }

                $result = $ai->analyze($builder->build($failure));

                $persister->persist($analysis, $failure, $result);
                $cache->put($failure, $result);

                EmbedFailure::dispatch($failure->id);
            } catch (AiBudgetExceeded $e) {
                // Not retryable. Stop, surface it, do not burn attempts — the
                // point of a budget is that it stops you.
                $recorder->recordFailure($analysis, $failure, 'budget_exceeded', $e->getMessage());
                $this->markFailed($analysis, $failure, $e->getMessage(), retry: false);
                $this->fail($e);
            } catch (NoLocalProviderConfigured $e) {
                // A configuration problem no retry can fix, and one the user must
                // see rather than have silently resolved against their privacy
                // setting.
                $recorder->recordFailure($analysis, $failure, 'error', $e->getMessage());
                $this->markFailed($analysis, $failure, $e->getMessage(), retry: false);
                $this->fail($e);
            } catch (AiServiceUnavailable $e) {
                $recorder->recordFailure($analysis, $failure, 'error', $e->getMessage());
                $this->markFailed($analysis, $failure, $e->getMessage(), retry: true);

                throw $e;   // let the queue retry
            } catch (Throwable $e) {
                $recorder->recordFailure($analysis, $failure, 'error', $e->getMessage());
                $this->markFailed($analysis, $failure, $e->getMessage(), retry: false);

                throw $e;
            }
        });
    }

    protected function markFailed(Analysis $analysis, Failure $failure, string $message, bool $retry): void
    {
        $analysis->update([
            'status' => 'failed',
            'error' => Str::limit($message, 1000),
            'completed_at' => now(),
        ]);

        // On a retryable error the failure stays `analyzing`: the next attempt is
        // still coming, and flipping it to failed would flicker in the UI.
        if (! $retry) {
            $failure->update(['status' => 'analysis_failed']);
        }
    }

    /**
     * Last attempt exhausted.
     *
     * Without this a failure whose analysis died on the final retry would sit at
     * `analyzing` forever, showing a spinner nothing will ever resolve.
     */
    public function failed(?Throwable $e): void
    {
        Failure::withoutGlobalScopes()
            ->where('id', $this->failureId)
            ->where('status', '!=', 'analyzed')
            ->update(['status' => 'analysis_failed']);
    }
}
