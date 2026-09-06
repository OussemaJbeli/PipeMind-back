<?php

declare(strict_types=1);

namespace App\Services\Logs;

/**
 * A deliberately simple PHP extractor used when the AI service is unreachable.
 *
 * The real processor lives in PipeMind-ai (roadmaps/07) and does redaction,
 * ecosystem-aware stack traces and signature normalisation. This exists so that
 * the AI service being down degrades the analysis rather than blocking ingestion:
 * the failure still gets a usable error message and the raw log is still stored.
 */
class FallbackLogExtractor
{
    private const ANSI = '/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/';

    private const ERROR_MARKERS = [
        '/^(PHP )?(Fatal error|Parse error|Uncaught \w+Exception|SQLSTATE\[\w+\])/mi',
        '/^(npm ERR!|yarn error|ERR_\w+|(Type|Reference|Range|Syntax)Error:|Cannot find module)/m',
        '/^(Traceback \(most recent call last\):|\w*Error: |\w*Exception: )/m',
        '/^(Exception in thread|Caused by:|\[ERROR\])/m',
        '/(no space left on device|failed to solve|pull access denied)/mi',
        '/(ECONNREFUSED|connection refused|could not connect to server)/mi',
        '/^(FAIL|FAILED|✕|✗|AssertionError)/m',
        '/(command not found|Permission denied|Killed|Segmentation fault)/mi',
        '/^(ERROR|FATAL|CRITICAL|error:|fatal:)\b/mi',
    ];

    /** @return array{excerpt:string,error_block:?string,error_message:?string,exit_code:?int,start_line:int,end_line:int} */
    public function extract(string $raw, int $context = 15, int $maxChars = 60_000): array
    {
        $cleaned = preg_replace(self::ANSI, '', $raw) ?? $raw;
        $cleaned = preg_replace('/section_(start|end):\d+:\S+\r?/m', '', $cleaned) ?? $cleaned;

        $lines = explode("\n", trim($cleaned));
        $hits = [];

        foreach (self::ERROR_MARKERS as $pattern) {
            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = $index;
                }
            }

            // First matching family wins: markers are ordered by specificity.
            if ($hits !== []) {
                break;
            }
        }

        if ($hits === []) {
            // No marker matched. The error is almost always at the end.
            $start = max(0, count($lines) - 120);
            $excerpt = implode("\n", array_slice($lines, $start));

            return [
                'excerpt' => mb_substr($excerpt, -$maxChars),
                'error_block' => null,
                'error_message' => $this->lastMeaningful($lines),
                'exit_code' => $this->exitCode($cleaned),
                'start_line' => $start + 1,
                'end_line' => count($lines),
            ];
        }

        // Window the FIRST error: later ones are usually cascading consequences,
        // and leading with the tail teaches the wrong cause.
        $first = $hits[0];
        $last = end($hits);
        $start = max(0, $first - $context);
        $end = min(count($lines), $last + $context + 1);

        if ($end - $start > 400) {
            $end = min(count($lines), $first + 200);
        }

        $excerpt = implode("\n", array_slice($lines, $start, $end - $start));
        $block = implode("\n", array_slice($lines, $first, min(30, count($lines) - $first)));

        return [
            'excerpt' => mb_substr($excerpt, 0, $maxChars),
            'error_block' => $block,
            'error_message' => trim((string) ($lines[$first] ?? '')) ?: null,
            'exit_code' => $this->exitCode($cleaned),
            'start_line' => $start + 1,
            'end_line' => $end,
        ];
    }

    /**
     * A degraded signature: normalises numbers, paths and hex so the same bug
     * still deduplicates. The AI service produces a better one in roadmaps/07.
     */
    public function signature(string $errorText): string
    {
        $normalized = strtolower(trim(mb_substr($errorText, 0, 2000)));

        $normalized = preg_replace('/\b[0-9a-f]{40}\b/i', '<sha>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b/', '<ip>', $normalized) ?? $normalized;
        $normalized = preg_replace('/(\/[\w.\-]+){2,}/', '<path>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{3,}\b/', '<num>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function exitCode(string $text): ?int
    {
        if (preg_match_all('/(?:exit(?:ed)?(?: with)?(?: code| status)?)\s*[:=]?\s*(\d{1,3})/i', $text, $m)) {
            return (int) end($m[1]);
        }

        return null;
    }

    /** @param  array<int,string>  $lines */
    protected function lastMeaningful(array $lines): ?string
    {
        foreach (array_reverse($lines) as $line) {
            $trimmed = trim($line);

            if (mb_strlen($trimmed) > 10 && ! str_starts_with($trimmed, '$') && ! str_starts_with($trimmed, '+')) {
                return mb_substr($trimmed, 0, 500);
            }
        }

        return null;
    }
}
