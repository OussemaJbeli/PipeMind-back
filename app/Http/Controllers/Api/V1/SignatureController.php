<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FailureSignature;
use App\Services\Ai\AnalysisCache;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    public function show(FailureSignature $signature): array
    {
        return ['data' => $this->present($signature)];
    }

    /**
     * Confirms — or withdraws — a known resolution for an error signature.
     *
     * The highest-leverage write in the application. Once `is_known` is set with
     * a resolution, the analyzer short-circuits every future occurrence of this
     * exact error to that answer: no model call, no cost, no latency, and an
     * answer a human wrote rather than one a model guessed.
     */
    public function update(Request $request, FailureSignature $signature, AnalysisCache $cache): array
    {
        $data = $request->validate([
            'is_known' => ['required', 'boolean'],
            'known_root_cause' => ['nullable', 'string', 'max:4000'],
            'known_resolution' => ['required_if:is_known,true', 'nullable', 'string', 'max:4000'],
        ]);

        $confirming = $data['is_known'] && filled($data['known_resolution'] ?? null);

        $signature->forceFill([
            // Both, together. `is_known` without a resolution short-circuits
            // future failures to nothing, which is worse than not
            // short-circuiting at all — the analyzer requires both for exactly
            // this reason, and so does this endpoint.
            'is_known' => $confirming,
            'known_root_cause' => $data['known_root_cause'] ?? $signature->known_root_cause,
            'known_resolution' => $confirming ? $data['known_resolution'] : null,
            'resolution_confirmed_by' => $confirming ? $request->user()->id : null,
            'resolution_confirmed_at' => $confirming ? now() : null,
        ])->save();

        // Every cached analysis for this signature predates the knowledge just
        // recorded, so serving one would ignore what a human just taught us.
        foreach ($signature->failures()->with('project', 'signature')->get() as $failure) {
            $cache->forget($failure);
        }

        $project = $signature->failures()->with('project')->first()?->project;

        activity_log(
            $project,
            $confirming ? 'signature.resolution_confirmed' : 'signature.resolution_withdrawn',
            $confirming ? 'success' : 'info',
            $project?->name ?? 'PipeMind',
            $confirming
                ? "Known fix recorded: {$data['known_resolution']}"
                : 'Known fix withdrawn',
            $signature,
        );

        return ['data' => $this->present($signature->fresh())];
    }

    /** @return array<string,mixed> */
    private function present(FailureSignature $signature): array
    {
        return [
            'uuid' => $signature->uuid,
            'hash' => substr($signature->hash, 0, 12),
            'category' => $signature->category->value ?? $signature->category,
            'subcategory' => $signature->subcategory,
            'sample_error' => $signature->sample_error,
            'normalized_error' => $signature->normalized_error,
            'occurrence_count' => (int) $signature->occurrence_count,
            'projects_affected' => (int) $signature->projects_affected,
            'is_known' => (bool) $signature->is_known,
            'known_root_cause' => $signature->known_root_cause,
            'known_resolution' => $signature->known_resolution,
            'avg_resolution_seconds' => $signature->avg_resolution_seconds,
            'first_seen_at' => $signature->first_seen_at?->toIso8601String(),
            'last_seen_at' => $signature->last_seen_at?->toIso8601String(),
            'confirmed_at' => $signature->resolution_confirmed_at?->toIso8601String(),
        ];
    }
}
