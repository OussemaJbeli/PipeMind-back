<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\JobLog;
use App\Services\Ai\AiGateway;
use App\Services\Logs\FallbackLogExtractor;
use App\Services\Logs\LogStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redact, clean, extract, and produce a failure signature.
 *
 * Delegates to the AI service when it is reachable; falls back to a PHP
 * extractor when it is not. An unreachable analysis service must degrade the
 * quality of ingestion, never stop it.
 */
class ProcessJobLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public int $jobLogId)
    {
        $this->onQueue('logs');
    }

    public function handle(LogStorage $storage, FallbackLogExtractor $fallback): void
    {
        $log = JobLog::withoutGlobalScopes()
            ->with(['job', 'project.team'])
            ->find($this->jobLogId);

        if (! $log?->project?->team) {
            return;
        }

        withTeam($log->project->team, function () use ($log, $storage, $fallback): void {
            $raw = $storage->read($log);

            $processed = $this->viaAiService($log, $raw) ?? $this->viaFallback($fallback, $raw);

            $log->update([
                'excerpt' => $processed['excerpt'],
                'excerpt_start_line' => $processed['start_line'],
                'excerpt_end_line' => $processed['end_line'],
                'error_block' => $processed['error_block'],
                'stack_trace' => $processed['stack_trace'] ?? null,
                'is_redacted' => $processed['is_redacted'],
                'redaction_count' => $processed['redaction_count'],
                'redaction_types' => $processed['redaction_types'],
                'processed_at' => now(),
            ]);

            DetectFailure::dispatch($log->pipeline_id, $log->job_id, [
                'error_message' => $processed['error_message'],
                'exit_code' => $processed['exit_code'],
                'signature_hash' => $processed['signature_hash'],
                'normalized_error' => $processed['normalized_error'],
                'category' => $processed['category'] ?? null,
                'subcategory' => $processed['subcategory'] ?? null,
                'ecosystem' => $processed['ecosystem'] ?? null,
            ]);
        });
    }

    /** @return array<string,mixed>|null Null when the AI service is unavailable. */
    protected function viaAiService(JobLog $log, string $raw): ?array
    {
        // Wired up in roadmaps/10, once PipeMind-ai exposes /v1/logs/process.
        if (! class_exists(AiGateway::class)) {
            return null;
        }

        try {
            $response = app(AiGateway::class)->processLog($raw, [
                'job_name' => $log->job?->name,
                'stage_name' => $log->job?->stage_name,
                'exit_code' => $log->job?->exit_code,
            ]);

            return [
                'excerpt' => $response['excerpt'],
                'start_line' => $response['excerpt_start_line'] ?? null,
                'end_line' => $response['excerpt_end_line'] ?? null,
                'error_block' => $response['error_block'] ?? null,
                'stack_trace' => $response['stack_trace'] ?? null,
                'error_message' => $response['error_message'] ?? null,
                'exit_code' => $response['exit_code'] ?? null,
                'signature_hash' => $response['signature_hash'],
                'normalized_error' => $response['normalized_error'],
                'ecosystem' => $response['ecosystem'] ?? null,
                // Classified by the AI service in the same call.
                'category' => $response['category'] ?? null,
                'subcategory' => $response['subcategory'] ?? null,
                'is_redacted' => (bool) ($response['is_redacted'] ?? false),
                'redaction_count' => (int) ($response['redaction_count'] ?? 0),
                'redaction_types' => $response['redaction_types'] ?? [],
            ];
        } catch (Throwable $e) {
            Log::warning('pipemind.log.ai_unavailable', [
                'job_log_id' => $log->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @return array<string,mixed> */
    protected function viaFallback(FallbackLogExtractor $fallback, string $raw): array
    {
        $extracted = $fallback->extract($raw);
        $basis = $extracted['error_block'] ?? $extracted['error_message'] ?? '';
        $normalized = $fallback->signature($basis);

        return [
            ...$extracted,
            'signature_hash' => hash('sha256', 'unknown::'.$normalized),
            'normalized_error' => $normalized,
            'stack_trace' => null,
            // The fallback does NOT redact. The excerpt therefore stays inside
            // PipeMind and is never forwarded to an external model until the AI
            // service — which redacts first — is available.
            'is_redacted' => false,
            'redaction_count' => 0,
            'redaction_types' => [],
        ];
    }
}
