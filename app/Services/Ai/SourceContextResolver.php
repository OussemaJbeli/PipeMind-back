<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Integrations\ProviderRegistry;
use App\Models\Failure;
use Illuminate\Support\Facades\Cache;

/**
 * Turns "DatabaseTest.php:42" into the lines around line 42.
 *
 * Without this the analyzer is told a file name and asked to explain a failure
 * inside it, which is why recommendations read like "check the recent changes".
 * A model that can see the offending line can name it.
 */
class SourceContextResolver
{
    /** Lines either side of the referenced one. Enough for a function, not a file. */
    private const CONTEXT_LINES = 12;

    /** More than this and the prompt cost outweighs the benefit. */
    private const MAX_FILES = 3;

    private const MAX_FILE_BYTES = 400_000;

    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function resolve(Failure $failure): array
    {
        $references = $this->references($failure);

        if ($references === []) {
            return [];
        }

        $project = $failure->project;
        $integration = $project?->integration;

        if (! $integration) {
            return [];
        }

        $adapter = $this->registry->for($integration);
        $ref = $failure->pipeline?->commit_sha;
        $resolved = [];

        foreach (array_slice($references, 0, self::MAX_FILES) as [$path, $line]) {
            // Keyed by commit, so a re-analysis of the same failure never pays
            // for the same file twice, and a later commit is never served a
            // stale snapshot of the code.
            $contents = Cache::remember(
                'pipemind:source:'.$project->id.':'.md5($path.'@'.$ref),
                now()->addHours(6),
                fn () => $adapter->fetchFileContents($integration, $project, $path, $ref),
            );

            if (! $contents || strlen($contents) > self::MAX_FILE_BYTES) {
                continue;
            }

            $window = $this->window($contents, $line);

            if ($window !== null) {
                $resolved[] = [
                    'path' => $path,
                    'line' => $line,
                    'start_line' => $window['start'],
                    'end_line' => $window['end'],
                    'content' => $window['content'],
                ];
            }
        }

        return $resolved;
    }

    /**
     * File references from the stack trace and error message.
     *
     * Matches `path/to/File.ext:123` and Python's `File "x.py", line 12` — the
     * two shapes that cover the ecosystems this project ingests.
     *
     * @return array<int,array{0:string,1:int}>
     */
    private function references(Failure $failure): array
    {
        $haystack = implode("\n", array_filter([
            $failure->job?->log?->stack_trace,
            $failure->job?->log?->error_block,
            $failure->error_message,
        ]));

        if ($haystack === '') {
            return [];
        }

        $found = [];

        preg_match_all(
            '/(?:File "([^"]+)", line (\d+))|([\w\/.\-]+\.(?:php|py|js|jsx|ts|tsx|vue|go|rb|java|kt|rs|cs|scala|swift)):(\d+)/i',
            $haystack,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $path = $match[1] !== '' ? $match[1] : ($match[3] ?? '');
            $line = (int) ($match[1] !== '' ? $match[2] : ($match[4] ?? 0));

            if ($path === '' || $line < 1) {
                continue;
            }

            // Vendor and dependency frames are noise: the bug is almost never in
            // a third-party package, and reading them wastes the file budget.
            if (preg_match('#(^|/)(vendor|node_modules|site-packages|\.venv)/#i', $path)) {
                continue;
            }

            $found[$path.':'.$line] = [$this->normalizePath($path), $line];
        }

        return array_values($found);
    }

    /** Stack traces carry absolute runner paths; the repository does not. */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#/(?:home|builds|github/workspace|app|src)/[^/]+/(.+)$#', $path, $m)) {
            return $m[1];
        }

        return ltrim($path, '/');
    }

    /** @return array{start:int,end:int,content:string}|null */
    private function window(string $contents, int $line): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        if ($lines === [] || $line > count($lines)) {
            return null;
        }

        $start = max(1, $line - self::CONTEXT_LINES);
        $end = min(count($lines), $line + self::CONTEXT_LINES);

        $numbered = [];

        for ($i = $start; $i <= $end; $i++) {
            // The marker matters: without it the model has to count lines to
            // find the one the stack trace named, and it counts badly.
            $marker = $i === $line ? '>' : ' ';
            $numbered[] = sprintf('%s %5d | %s', $marker, $i, $lines[$i - 1] ?? '');
        }

        return ['start' => $start, 'end' => $end, 'content' => implode("\n", $numbered)];
    }
}
