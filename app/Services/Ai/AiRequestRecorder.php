<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequest;
use App\Models\Analysis;
use App\Models\Failure;
use Illuminate\Support\Str;

/**
 * Every call to the AI service leaves a row here — successes, cache hits and
 * failures alike.
 *
 * `ai_requests` is what answers "what did this cost, where did the time go, how
 * often did the cache save us". A cache hit with `cost_usd = 0` is the row that
 * proves the caching works, so recording only the expensive calls would hide
 * exactly the number worth showing.
 */
class AiRequestRecorder
{
    /** @param  array<string,mixed>  $usage */
    public function record(Analysis $analysis, Failure $failure, array $usage, string $status = 'success'): void
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);

        AiRequest::create([
            'team_id' => $failure->team_id,
            'project_id' => $failure->project_id,
            'failure_id' => $failure->id,
            'analysis_id' => $analysis->id,
            'ai_provider_id' => $failure->project->ai_provider_id,
            'operation' => 'analyze',
            'provider' => $usage['provider'] ?? 'unknown',
            'model' => $usage['model'] ?? 'unknown',
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost_usd' => $usage['cost_usd'] ?? 0,
            'latency_ms' => $usage['latency_ms'] ?? null,
            'cache_hit' => (bool) ($usage['cache_hit'] ?? false),
            'status' => $status,
        ]);
    }

    /**
     * A call that cost time but produced nothing still belongs in the ledger —
     * otherwise the error rate is invisible and latency looks better than it is.
     */
    public function recordFailure(Analysis $analysis, Failure $failure, string $status, string $error): void
    {
        AiRequest::create([
            'team_id' => $failure->team_id,
            'project_id' => $failure->project_id,
            'failure_id' => $failure->id,
            'analysis_id' => $analysis->id,
            'ai_provider_id' => $failure->project->ai_provider_id,
            'operation' => 'analyze',
            'provider' => 'unknown',
            'model' => 'unknown',
            'status' => $status,
            'error' => Str::limit($error, 500),
        ]);
    }
}
