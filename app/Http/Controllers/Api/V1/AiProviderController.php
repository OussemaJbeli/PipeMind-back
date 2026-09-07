<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiProviderRequest;
use App\Models\AiProvider;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AnalysisCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiProviderController extends Controller
{
    public function index(): array
    {
        return ['data' => AiProvider::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (AiProvider $p) => $this->present($p))->all()];
    }

    /**
     * Tests credentials the user has typed but not yet saved.
     *
     * The same contract as the integration wizard. A key that is only exercised
     * at analysis time fails an hour later, inside a queue, and what the user
     * sees is "analysis failed" rather than "your key is wrong".
     */
    public function testUnsaved(Request $request, AiGateway $ai): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:30'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ]);

        return $this->result($ai->testProvider($data));
    }

    /** Re-tests a saved provider, using the stored key. */
    public function test(AiProvider $provider, AiGateway $ai): JsonResponse
    {
        $result = $ai->testProvider([
            'provider' => $provider->provider,
            'api_key' => $provider->api_key,
            'model' => $provider->model,
            'base_url' => $provider->base_url,
        ]);

        $provider->forceFill([
            'status' => $result['ok'] ? 'active' : 'error',
            'last_tested_at' => now(),
            'last_error' => $result['ok'] ? null : ($result['message'] ?? null),
        ])->save();

        return $this->result($result);
    }

    public function store(AiProviderRequest $request, AiGateway $ai): JsonResponse
    {
        $data = $request->validated();
        $check = $ai->testProvider($data);

        // Refuse to save a provider that does not work. A broken row that looks
        // configured is worse than no row: auto-analysis will pick it up and
        // every failure silently stops being analysed.
        if (! ($check['ok'] ?? false)) {
            return response()->json([
                'message' => $check['message'] ?? 'The provider rejected these settings.',
                'error_code' => 'AI_PROVIDER_UNAUTHORIZED',
                'retryable' => false,
            ], 422);
        }

        $provider = DB::transaction(function () use ($data) {
            $provider = AiProvider::create([
                ...$data,
                'status' => 'active',
                'last_tested_at' => now(),
                'is_local' => $data['is_local'] ?? in_array($data['provider'], ['ollama'], true),
            ]);

            $this->enforceSingleDefault($provider);

            return $provider;
        });

        activity_log(null, 'ai.provider_added', 'success', $provider->name,
            "{$provider->provider} · {$provider->model}", $provider);

        return response()->json(['data' => $this->present($provider->fresh())], 201);
    }

    public function update(AiProviderRequest $request, AiProvider $provider, AiGateway $ai, AnalysisCache $cache): JsonResponse
    {
        $data = $request->validated();

        // The form cannot round-trip a key it never received, so an omitted key
        // means "keep the stored one".
        $data['api_key'] = $data['api_key'] ?? $provider->api_key;

        $check = $ai->testProvider($data);

        if (! ($check['ok'] ?? false)) {
            return response()->json([
                'message' => $check['message'] ?? 'The provider rejected these settings.',
                'error_code' => 'AI_PROVIDER_UNAUTHORIZED',
                'retryable' => false,
            ], 422);
        }

        $modelChanged = $provider->provider !== $data['provider'] || $provider->model !== $data['model'];

        DB::transaction(function () use ($provider, $data, $cache, $modelChanged): void {
            $provider->update([
                ...$data,
                'status' => 'active',
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            $this->enforceSingleDefault($provider);

            // A different model produces different analyses, so everything the
            // old one cached is now attributed to a model that did not write it.
            if ($modelChanged) {
                foreach ($provider->projects()->pluck('id') as $projectId) {
                    $cache->forgetProject($projectId);
                }
            }
        });

        return response()->json(['data' => $this->present($provider->fresh())]);
    }

    public function destroy(AiProvider $provider): JsonResponse
    {
        if ($provider->projects()->exists()) {
            return response()->json([
                'message' => 'This provider is still assigned to projects. Reassign them first.',
                'error_code' => 'AI_PROVIDER_IN_USE',
                'retryable' => false,
            ], 409);
        }

        $provider->delete();

        return response()->json(status: 204);
    }

    /** One default per team, enforced on write rather than hoped for. */
    protected function enforceSingleDefault(AiProvider $provider): void
    {
        if (! $provider->is_default) {
            return;
        }

        AiProvider::where('id', '!=', $provider->id)->update(['is_default' => false]);
    }

    /** @param  array<string,mixed>  $result */
    protected function result(array $result): JsonResponse
    {
        // Always 200: "these credentials are wrong" is a valid answer to "do
        // these credentials work", and the wizard needs the message either way.
        return response()->json(['data' => [
            'ok' => (bool) ($result['ok'] ?? false),
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
            'message' => $result['message'] ?? null,
            'models' => $result['models'] ?? [],
            'latency_ms' => $result['latency_ms'] ?? null,
        ]]);
    }

    /** @return array<string,mixed> */
    protected function present(AiProvider $provider): array
    {
        return [
            'uuid' => $provider->uuid,
            'name' => $provider->name,
            'provider' => $provider->provider,
            'model' => $provider->model,
            'base_url' => $provider->base_url,
            // api_key is never returned. It is $hidden on the model as well; this
            // is the second lock on the same door.
            'has_api_key' => filled($provider->api_key),
            'is_default' => (bool) $provider->is_default,
            'is_local' => (bool) $provider->is_local,
            'max_tokens' => (int) $provider->max_tokens,
            'temperature' => (float) $provider->temperature,
            'input_cost_per_1k' => (float) $provider->input_cost_per_1k,
            'output_cost_per_1k' => (float) $provider->output_cost_per_1k,
            'status' => $provider->status,
            'last_tested_at' => $provider->last_tested_at?->toIso8601String(),
            'last_error' => $provider->last_error,
            'projects_count' => $provider->projects()->count(),
        ];
    }
}
