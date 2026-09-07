<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiProviderRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Matches the ai_providers.provider CHECK constraint.
            'provider' => ['required', Rule::in([
                'gemini', 'openai', 'anthropic', 'ollama',
                'openai_compatible', 'azure_openai', 'stub',
            ])],
            'model' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],

            // Local providers need no key; cloud ones do. On update the key may
            // be omitted to keep the stored one — the API never returns it, so
            // the form cannot round-trip it.
            'api_key' => [
                Rule::requiredIf(fn () => $this->isMethod('POST')
                    && ! in_array($this->input('provider'), ['ollama', 'stub'], true)),
                'nullable', 'string', 'max:500',
            ],

            'is_default' => ['boolean'],
            'is_local' => ['boolean'],
            'max_tokens' => ['integer', 'min:256', 'max:200000'],
            'temperature' => ['numeric', 'min:0', 'max:2'],

            // Pricing lives in the database, not in code: rates change, and a
            // hardcoded number becomes a lie that quietly corrupts every cost
            // figure in the reports.
            'input_cost_per_1k' => ['numeric', 'min:0'],
            'output_cost_per_1k' => ['numeric', 'min:0'],
        ];
    }
}
