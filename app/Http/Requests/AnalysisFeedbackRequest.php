<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FailureCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalysisFeedbackRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'was_helpful' => ['required', 'boolean'],
            'root_cause_correct' => ['nullable', 'boolean'],

            // This field is the training set. `analysis_feedback.correct_category`
            // is where the supervised dataset for the ML classifier comes from —
            // which is why it is constrained to the same enum the classifier
            // predicts, rather than accepting free text.
            'correct_category' => ['nullable', Rule::enum(FailureCategory::class)],

            'actual_root_cause' => ['nullable', 'string', 'max:2000'],
            'actual_resolution' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
