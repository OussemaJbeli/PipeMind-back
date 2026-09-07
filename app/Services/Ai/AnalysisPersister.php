<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\Ai\AiInvalidResponse;
use App\Models\Analysis;
use App\Models\AnalysisEvidence;
use App\Models\Failure;
use App\Models\Recommendation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an AI response into the database.
 *
 * Everything lands in one transaction: an analysis row with evidence but no
 * recommendations, or a failure marked `analyzed` with no analysis behind it,
 * would both be worse than no analysis at all.
 */
class AnalysisPersister
{
    public function __construct(
        private readonly AiRequestRecorder $recorder,
    ) {}

    /**
     * Fields the AI service guarantees. Checked before anything is written
     * because a half-populated analysis row is harder to notice, and harder to
     * recover from, than a call that failed outright.
     *
     * @var array<int,string>
     */
    private const REQUIRED = ['category', 'severity', 'confidence', 'summary', 'root_cause'];

    /** @param  array<string,mixed>  $result */
    public function persist(Analysis $analysis, Failure $failure, array $result): void
    {
        $this->assertShape($result);

        DB::transaction(function () use ($analysis, $failure, $result): void {
            $usage = $result['usage'] ?? [];

            $analysis->update([
                'status' => 'completed',
                'ai_service_version' => $result['service_version'] ?? null,
                'category' => $result['category'],
                'subcategory' => $result['subcategory'] ?? null,
                'severity' => $result['severity'],
                'confidence' => $result['confidence'],
                'summary' => $result['summary'],
                'root_cause' => $result['root_cause'],
                'explanation' => $result['explanation'] ?? null,
                'is_transient' => $result['is_transient'] ?? false,
                'retry_recommended' => $result['retry_recommended'] ?? false,
                'classification_source' => $result['classification_source'] ?? null,
                'classification_confidence' => $result['classification_confidence'] ?? null,
                'used_rag' => $result['used_rag'] ?? false,
                'similar_failures_count' => count($result['similar_failures'] ?? []),
                'model_provider' => $usage['provider'] ?? null,
                'model_name' => $usage['model'] ?? null,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'cost_usd' => $usage['cost_usd'] ?? 0,
                'latency_ms' => $usage['latency_ms'] ?? null,
                'cache_hit' => $usage['cache_hit'] ?? false,
                'raw_response' => $result,
                'completed_at' => now(),
            ]);

            $this->storeEvidence($analysis, $result['evidence'] ?? []);
            $this->storeRecommendations($analysis, $failure, $result['recommendations'] ?? []);

            // The analysis is the authority on category and severity: it saw the
            // full context, where the ingest-time classifier saw only the error
            // block.
            $failure->update([
                'status' => 'analyzed',
                'category' => $result['category'],
                'subcategory' => $result['subcategory'] ?? null,
                'severity' => $result['severity'],
                'is_transient' => $result['is_transient'] ?? false,
            ]);

            $failure->signature?->update([
                'category' => $result['category'],
                'subcategory' => $result['subcategory'] ?? null,
            ]);

            $this->recorder->record($analysis, $failure, $usage);
        });

        activity_log(
            $failure->project,
            'analysis.completed',
            'success',
            $failure->project->name,
            "{$result['category']} · ".round(((float) $result['confidence']) * 100).'% confidence',
            $failure,
        );
    }

    /**
     * A cache hit takes the identical path.
     *
     * Deliberately not a shortcut: a reused analysis must produce its own
     * evidence and recommendation rows, or the failure page would render empty
     * for exactly the failures the app handled best.
     *
     * @param  array<string,mixed>  $cached
     */
    public function persistCached(Analysis $analysis, Failure $failure, array $cached): void
    {
        $cached['usage'] = array_merge($cached['usage'] ?? [], [
            'cost_usd' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            // Latency is zeroed alongside cost. Carrying the original call's
            // figure forward would report a cache hit as taking as long as the
            // model call it replaced — which makes every "the cache saved us"
            // number in the reports say the opposite of the truth.
            'latency_ms' => 0,
            'cache_hit' => true,
        ]);

        $this->persist($analysis, $failure, $cached);
    }

    /**
     * @param  array<string,mixed>  $result
     *
     * @throws AiInvalidResponse
     */
    protected function assertShape(array $result): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED,
            fn (string $field) => ! isset($result[$field]) || $result[$field] === '',
        ));

        if ($missing) {
            throw new AiInvalidResponse(
                'The analysis service returned a response missing: '.implode(', ', $missing).'.'
            );
        }
    }

    /** @param  array<int,array<string,mixed>>  $items */
    protected function storeEvidence(Analysis $analysis, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            AnalysisEvidence::create([
                'analysis_id' => $analysis->id,
                'type' => $item['type'] ?? 'log_line',
                'content' => Str::limit((string) ($item['content'] ?? ''), 2000),
                'source_ref' => $item['source_ref'] ?? null,
                'line_number' => $item['line_number'] ?? null,
                'related_failure_id' => isset($item['related_failure_uuid'])
                    ? Failure::where('uuid', $item['related_failure_uuid'])->value('id')
                    : null,
                'weight' => min(1, max(0, (float) ($item['weight'] ?? 0.5))),
                'position' => $position,
            ]);
        }
    }

    /** @param  array<int,array<string,mixed>>  $items */
    protected function storeRecommendations(Analysis $analysis, Failure $failure, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            $recommendation = Recommendation::create([
                'analysis_id' => $analysis->id,
                'failure_id' => $failure->id,
                'title' => Str::limit((string) ($item['title'] ?? 'Investigate'), 250),
                'description' => $item['description'] ?? null,
                'rationale' => $item['rationale'] ?? null,
                'action_type' => $item['action_type'] ?? 'manual',
                // Assigned by the AI service from action_type, never by the model.
                'risk' => $item['risk'] ?? 'medium',
                'confidence' => $item['confidence'] ?? null,
                'affected_files' => $item['affected_files'] ?? [],
                'patch' => $item['patch'] ?? null,
                'position' => $position,
            ]);

            // Evaluating the policy now lets the UI show the gate immediately —
            // "Approve" versus "Not allowed" without a second round trip.
            // The evaluator arrives in roadmaps/18.
            if (class_exists(RemediationPolicyEvaluator::class)) {
                app(RemediationPolicyEvaluator::class)->annotate($recommendation);
            }
        }
    }
}
