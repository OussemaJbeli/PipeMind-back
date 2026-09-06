<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalysisFeedbackRequest;
use App\Models\Analysis;
use App\Models\AnalysisFeedback;
use App\Services\Ai\AnalysisCache;

class AnalysisController extends Controller
{
    /**
     * Developer feedback on an analysis.
     *
     * This is the most valuable endpoint in the application and the least
     * impressive-looking. `correct_category` is the label column of the training
     * set for the ML classifier — the dataset does not come from raw logs, it
     * comes from people correcting the model here. Nothing else in PipeMind
     * generates supervised labels.
     */
    public function feedback(
        AnalysisFeedbackRequest $request,
        Analysis $analysis,
        AnalysisCache $cache,
    ): array {
        $feedback = AnalysisFeedback::updateOrCreate(
            ['analysis_id' => $analysis->id, 'user_id' => $request->user()->id],
            $request->safe()->only([
                'was_helpful', 'root_cause_correct', 'correct_category',
                'actual_root_cause', 'actual_resolution', 'comment',
            ]),
        );

        // An analysis a human has rejected must not be served again from cache.
        // Serving it would repeat the mistake and, worse, look like the app never
        // heard the correction.
        if (! $feedback->was_helpful || $feedback->root_cause_correct === false) {
            $analysis->loadMissing('failure.signature');

            if ($analysis->failure) {
                $cache->forget($analysis->failure);
            }
        }

        activity_log(
            $analysis->failure?->project,
            'analysis.feedback',
            $feedback->was_helpful ? 'success' : 'warning',
            $analysis->failure?->project?->name ?? 'PipeMind',
            $feedback->was_helpful ? 'Analysis marked helpful' : 'Analysis marked unhelpful',
            $analysis,
        );

        return ['data' => [
            'given' => true,
            'was_helpful' => (bool) $feedback->was_helpful,
        ]];
    }
}
