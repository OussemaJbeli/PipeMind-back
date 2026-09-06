<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Analysis;
use App\Models\Failure;
use Illuminate\Support\Facades\Cache;

/**
 * Reuses a previous analysis of the same signature in the same project.
 *
 * Keyed by (project_id, signature_hash), never by signature alone: a
 * "Connection refused" in a Laravel API and in a React build have identical
 * error text and completely different causes. Cross-project cache sharing is
 * how you ship a confidently wrong analysis.
 */
class AnalysisCache
{
    /** @return array<string,mixed>|null */
    public function get(Failure $failure): ?array
    {
        if (! $hash = $failure->signature?->hash) {
            return null;
        }

        // 1. Hot path: Redis.
        if ($cached = Cache::get($this->key($failure->project_id, $hash))) {
            return $cached;
        }

        // 2. Warm path: an existing analysis of the same signature in the same
        //    project. Only confident ones — reusing a 0.4-confidence guess just
        //    propagates it.
        $previous = Analysis::query()
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->where('failures.project_id', $failure->project_id)
            ->where('failures.signature_id', $failure->signature_id)
            ->where('analyses.status', 'completed')
            ->where('analyses.confidence', '>=', 0.80)
            // Without this, forget() achieves nothing: the Redis key goes, and
            // the next lookup resurrects the very analysis a developer rejected
            // straight back out of the database.
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('analysis_feedback')
                ->whereColumn('analysis_feedback.analysis_id', 'analyses.id')
                ->where(fn ($q) => $q
                    ->where('analysis_feedback.was_helpful', false)
                    ->orWhere('analysis_feedback.root_cause_correct', false)))
            ->where('analyses.created_at', '>=', now()->subHours(
                (int) config('pipemind.analysis.cache_ttl_hours')
            ))
            ->reorder('analyses.confidence', 'desc')
            ->select('analyses.*')
            ->first();

        if (! $previous) {
            return null;
        }

        $payload = $this->toPayload($previous);

        Cache::put($this->key($failure->project_id, $hash), $payload, now()->addHours(24));

        return $payload;
    }

    /** @param  array<string,mixed>  $result */
    public function put(Failure $failure, array $result): void
    {
        if ($hash = $failure->signature?->hash) {
            Cache::put($this->key($failure->project_id, $hash), $result, now()->addHours(24));
        }
    }

    /**
     * Called when feedback marks an analysis wrong, or when a signature gains a
     * confirmed resolution. Serving a cached analysis a human has already
     * rejected is worse than paying for a new one.
     */
    public function forget(Failure $failure): void
    {
        if ($hash = $failure->signature?->hash) {
            Cache::forget($this->key($failure->project_id, $hash));
        }
    }

    /**
     * A changed provider or model invalidates everything the old one produced.
     *
     * Cache tags need a taggable store, and the file/database drivers used in
     * testing are not. Falling back to per-signature deletion keeps this correct
     * on every driver rather than only on Redis.
     */
    public function forgetProject(int $projectId): void
    {
        $hashes = Analysis::query()
            ->join('failures', 'failures.id', '=', 'analyses.failure_id')
            ->join('failure_signatures', 'failure_signatures.id', '=', 'failures.signature_id')
            ->where('failures.project_id', $projectId)
            ->distinct()
            ->pluck('failure_signatures.hash');

        foreach ($hashes as $hash) {
            Cache::forget($this->key($projectId, $hash));
        }
    }

    /**
     * Rebuilds the AI service's response shape from a stored analysis, so a
     * cached hit and a fresh call are indistinguishable to the persister.
     *
     * `raw_response` is preferred when present: it is exactly what the service
     * returned, including evidence and recommendations that the columns do not
     * carry.
     *
     * @return array<string,mixed>
     */
    protected function toPayload(Analysis $analysis): array
    {
        $payload = is_array($analysis->raw_response) ? $analysis->raw_response : [
            'category' => $analysis->category?->value ?? $analysis->category,
            'subcategory' => $analysis->subcategory,
            'severity' => $analysis->severity?->value ?? $analysis->severity,
            'confidence' => (float) $analysis->confidence,
            'summary' => $analysis->summary,
            'root_cause' => $analysis->root_cause,
            'explanation' => $analysis->explanation,
            'is_transient' => (bool) $analysis->is_transient,
            'retry_recommended' => (bool) $analysis->retry_recommended,
            'classification_source' => $analysis->classification_source,
            'classification_confidence' => (float) $analysis->classification_confidence,
            'evidence' => [],
            'recommendations' => [],
            'similar_failures' => [],
        ];

        // The reused analysis cost nothing this time round, whatever the
        // original cost. Overwriting usage is what makes the saving measurable.
        $payload['usage'] = [
            'provider' => $analysis->model_provider ?? 'cache',
            'model' => $analysis->model_name ?? 'cache',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_usd' => 0,
            'latency_ms' => 0,
            'cache_hit' => true,
        ];

        $payload['used_rag'] = (bool) $analysis->used_rag;
        $payload['reused_from_analysis_uuid'] = $analysis->uuid;

        return $payload;
    }

    protected function key(int $projectId, string $hash): string
    {
        return "pipemind:analysis:{$projectId}:{$hash}";
    }
}
