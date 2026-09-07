<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\Ai\AiBudgetExceeded;
use App\Exceptions\Ai\AiInvalidResponse;
use App\Exceptions\Ai\AiProviderRateLimited;
use App\Exceptions\Ai\AiProviderUnauthorized;
use App\Exceptions\Ai\AiServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The only thing in Laravel that speaks HTTP to the Python service.
 *
 * No retry logic here on purpose: the AI service already retries the model with
 * backoff, and the queued job retries the whole call. Retrying at three layers
 * turns one transient blip into nine model calls and nine times the cost.
 */
class AiGateway
{
    protected function client(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('pipemind.ai.url'), '/'))
            ->withHeaders([
                'X-PipeMind-Token' => (string) config('pipemind.ai.token'),
                'X-Contract' => (string) config('pipemind.ai.contract_version'),
            ])
            ->timeout($timeout ?? (int) config('pipemind.ai.timeout'))
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();
    }

    /** @return array<string,mixed> */
    public function processLog(string $rawLog, array $context = []): array
    {
        return $this->call('POST', '/v1/logs/process', [
            'raw_log' => $rawLog,
            'job_name' => $context['job_name'] ?? null,
            'stage_name' => $context['stage_name'] ?? null,
            'exit_code' => $context['exit_code'] ?? null,
            'strict_redaction' => $context['strict'] ?? true,
        ], timeout: 60);
    }

    /** @return array<string,mixed> */
    public function classify(string $text, ?string $ecosystem = null): array
    {
        return $this->call('POST', '/v1/classify', compact('text', 'ecosystem'), timeout: 20);
    }

    /** @return array<string,mixed> */
    public function analyze(array $payload): array
    {
        return $this->call('POST', '/v1/analyze', $payload);
    }

    /**
     * Composes the vector the same way retrieval does, so a text embedded here
     * is comparable with one embedded during analysis.
     *
     * @return array<string,mixed>
     */
    public function embed(
        string $text,
        ?string $category = null,
        ?string $ecosystem = null,
        ?string $jobName = null,
    ): array {
        return $this->call('POST', '/v1/embed', [
            'text' => $text,
            'category' => $category,
            'ecosystem' => $ecosystem,
            'job_name' => $jobName,
        ], timeout: 30);
    }

    /**
     * Splits a document and embeds every chunk in one call.
     *
     * Batched deliberately: a 40-chunk runbook would otherwise be 40 round trips.
     *
     * @return array<string,mixed>
     */
    public function chunkEmbed(string $text, ?string $title = null): array
    {
        return $this->call('POST', '/v1/knowledge/chunk-embed', [
            'text' => $text,
            'title' => $title,
        ], timeout: 120);
    }

    /**
     * Tests credentials before they are saved.
     *
     * The payload is the same shape as `llm_override`, so what the wizard tests
     * is exactly what analysis will later send.
     *
     * @return array<string,mixed>
     */
    public function testProvider(array $config): array
    {
        return $this->call('POST', '/v1/providers/test', [
            'provider' => $config['provider'],
            'api_key' => $config['api_key'] ?? null,
            'model' => $config['model'] ?? null,
            'base_url' => $config['base_url'] ?? null,
        ], timeout: 30);
    }

    /** @return array<string,mixed> */
    public function info(): array
    {
        return $this->call('GET', '/v1/info', timeout: 5);
    }

    public function isReachable(): bool
    {
        try {
            return Http::baseUrl(rtrim((string) config('pipemind.ai.url'), '/'))
                ->timeout(3)->connectTimeout(2)->get('/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    protected function call(string $method, string $path, array $body = [], ?int $timeout = null): array
    {
        try {
            $response = $method === 'GET'
                ? $this->client($timeout)->get($path, $body)
                : $this->client($timeout)->send($method, $path, ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new AiServiceUnavailable('The analysis service is unreachable.', previous: $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        // The AI service returns Laravel's own error shape, so the code and the
        // message can be forwarded to the browser unchanged.
        $code = (string) $response->json('error_code', 'AI_SERVICE_ERROR');
        $message = (string) $response->json('message', 'Analysis failed.');

        throw match ($code) {
            'AI_BUDGET_EXCEEDED' => new AiBudgetExceeded($message),
            // A rejected key is a configuration mistake, not an outage. Routing
            // it to AiServiceUnavailable would make the queue retry a typo.
            'AI_PROVIDER_UNAUTHORIZED' => new AiProviderUnauthorized($message),
            // Not an outage. Reporting a spent quota as "service unreachable"
            // sends the user to check containers for a problem that isn't there.
            'AI_PROVIDER_RATE_LIMITED' => new AiProviderRateLimited($message),
            'AI_INVALID_RESPONSE', 'AI_REDACTION_FAILED' => new AiInvalidResponse($message),
            default => new AiServiceUnavailable($message),
        };
    }
}
