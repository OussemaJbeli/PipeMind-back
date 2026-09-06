<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveFailureRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            // The vocabulary matches the failures.resolution_type CHECK constraint.
            // 'unresolved' is absent deliberately: this endpoint resolves.
            'resolution_type' => ['required', Rule::in(['fixed', 'retried', 'ignored', 'auto_remediated', 'flaky'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'resolution_commit_sha' => ['nullable', 'string', 'max:64'],
        ];
    }
}
