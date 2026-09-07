<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Canned responses shaped exactly like the Python service's.
 *
 * Kept in one place so a contract change breaks every test at once rather than
 * leaving some suites asserting against a shape the service no longer returns.
 */
class AiFakes
{
    /** @return array<string,mixed> */
    public static function analyze(array $overrides = []): array
    {
        return array_replace_recursive([
            'contract_version' => 'v1',
            'service_version' => '0.1.0',
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
            'severity' => 'high',
            'confidence' => 0.91,
            'summary' => 'The test suite could not reach PostgreSQL.',
            'root_cause' => 'The database service was not linked to the job container.',
            'explanation' => null,
            'is_transient' => false,
            'retry_recommended' => false,
            'evidence' => [[
                'type' => 'log_line',
                'content' => 'SQLSTATE[HY000] [2002] Connection refused',
                'source_ref' => 'log:3',
                'line_number' => 3,
                'weight' => 0.95,
            ]],
            'recommendations' => [[
                'title' => 'Add the postgres service to the job',
                'description' => 'Declare postgres under services so the job can reach it.',
                'action_type' => 'update_config',
                'risk' => 'high',
                'confidence' => 0.88,
                'affected_files' => ['docker-compose.yml'],
            ]],
            'similar_failures' => [],
            'classification_source' => 'rules',
            'classification_confidence' => 0.98,
            'used_rag' => false,
            'usage' => [
                'provider' => 'stub',
                'model' => 'stub-v1',
                'prompt_tokens' => 411,
                'completion_tokens' => 180,
                'cost_usd' => 0.0,
                'latency_ms' => 15,
                'cache_hit' => false,
            ],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    public static function processLog(array $overrides = []): array
    {
        return array_replace_recursive([
            'excerpt' => "$ php artisan test\n   SQLSTATE[HY000] [2002] Connection refused\nERROR: Job failed: exit code 1",
            'excerpt_start_line' => 1,
            'excerpt_end_line' => 3,
            'error_block' => 'SQLSTATE[HY000] [2002] Connection refused',
            'stack_trace' => null,
            'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'ecosystem' => 'php',
            'exit_code' => 1,
            'matched_lines' => [2],
            'signature_hash' => str_repeat('a', 64),
            'normalized_error' => 'sqlstate[hy000] [2002] connection refused',
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
            'classification_confidence' => 0.98,
            'classification_source' => 'rules',
            'is_redacted' => false,
            'redaction_count' => 0,
            'redaction_types' => [],
        ], $overrides);
    }

    /** A deterministic unit vector, so similarity assertions are reproducible. */
    public static function embed(int $dimensions = 384): array
    {
        $vector = array_fill(0, $dimensions, 0.0);
        $vector[0] = 1.0;

        return [
            'embedding' => $vector,
            'dimensions' => $dimensions,
            'model' => 'sentence-transformers/all-MiniLM-L6-v2',
            'source_text' => 'test',
        ];
    }

    /** @return array<string,mixed> */
    public static function chunkEmbed(int $chunks = 3): array
    {
        $vector = array_fill(0, 384, 0.0);
        $vector[0] = 1.0;

        return [
            'chunks' => collect(range(0, $chunks - 1))->map(fn (int $i) => [
                'index' => $i,
                'content' => "Chunk {$i} of the runbook.",
                'token_count' => 120,
                'embedding' => $vector,
            ])->all(),
            'model' => 'sentence-transformers/all-MiniLM-L6-v2',
            'dimensions' => 384,
        ];
    }

    /** @return array<string,mixed> */
    public static function providerTest(bool $ok = true, string $message = 'Connected. 12 models available.'): array
    {
        return [
            'ok' => $ok,
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'message' => $message,
            'models' => $ok ? ['gemini-2.0-flash', 'gemini-2.5-flash'] : [],
            'latency_ms' => 940,
        ];
    }

    /**
     * Routes one fake to the right payload by path.
     *
     * The AI service lives behind a single base URL, so a per-path closure is
     * the only way to fake more than one of its endpoints at a time.
     */
    public static function router(): \Closure
    {
        return function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/v1/analyze') => Http::response(self::analyze()),
                str_contains($request->url(), '/v1/embed') => Http::response(self::embed()),
                str_contains($request->url(), '/v1/logs/process') => Http::response(self::processLog()),
                str_contains($request->url(), '/v1/knowledge/chunk-embed') => Http::response(self::chunkEmbed()),
                str_contains($request->url(), '/v1/providers/test') => Http::response(self::providerTest()),
                default => Http::response([], 200),
            };
        };
    }
}
