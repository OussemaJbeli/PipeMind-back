<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Failure;
use App\Models\FailureEmbedding;
use App\Services\Ai\AiGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes a failure's vector so future failures can find it.
 *
 * Runs after analysis rather than at ingestion, because the analysis is what
 * establishes the category — and the category is part of what gets embedded.
 * Embedding earlier would file the failure under whatever the rule classifier
 * guessed from the error block alone.
 */
class EmbedFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $failureId)
    {
        $this->onQueue('analysis');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping("embed:{$this->failureId}")];
    }

    public function handle(AiGateway $ai): void
    {
        $failure = Failure::withoutGlobalScopes()
            ->with('project.team')
            ->find($this->failureId);

        if (! $failure?->project?->team || ! $failure->error_message) {
            return;
        }

        withTeam($failure->project->team, function () use ($failure, $ai): void {
            $response = $ai->embed(
                text: $failure->error_message,
                category: $failure->category?->value,
                ecosystem: $failure->ecosystem,
                jobName: $failure->job_name,
            );

            if (empty($response['embedding'])) {
                return;
            }

            // updateOrCreate on (failure_id, model): re-embedding is how a
            // corrected category propagates into the vector.
            FailureEmbedding::updateOrCreate(
                [
                    'failure_id' => $failure->id,
                    'model' => $this->modelName($response['model'] ?? 'unknown'),
                ],
                [
                    'team_id' => $failure->team_id,
                    'signature_id' => $failure->signature_id,
                    'embedding' => $response['embedding'],
                    'dimensions' => $response['dimensions'] ?? count($response['embedding']),
                    'source_text' => $response['source_text'] ?? $failure->error_message,
                ],
            );
        });
    }

    /** `failure_embeddings.model` is 120 chars; the service returns a full HF path. */
    protected function modelName(string $model): string
    {
        return substr(basename($model), 0, 120);
    }

    public function failed(?Throwable $e): void
    {
        // A missing embedding degrades future retrieval; it does not invalidate
        // the analysis that has already been stored. Log it and move on.
        Log::warning('pipemind.embedding.failed', [
            'failure_id' => $this->failureId,
            'reason' => $e?->getMessage(),
        ]);
    }
}
