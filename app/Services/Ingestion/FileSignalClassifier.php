<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use Illuminate\Support\Str;

/**
 * "The pipeline broke and docker-compose.yml changed" is most of the diagnosis.
 * These two flags are the highest-signal features available at ingest time.
 */
class FileSignalClassifier
{
    public function isConfig(string $path): bool
    {
        return $this->matchesAny($path, config('pipemind.file_signals.config', []));
    }

    public function isDependency(string $path): bool
    {
        return $this->matchesAny($path, config('pipemind.file_signals.dependency', []));
    }

    public function language(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'PHP',
            'js', 'mjs', 'cjs' => 'JavaScript',
            'ts', 'mts', 'cts' => 'TypeScript',
            'vue' => 'Vue',
            'py' => 'Python',
            'go' => 'Go',
            'rb' => 'Ruby',
            'java' => 'Java',
            'kt' => 'Kotlin',
            'rs' => 'Rust',
            'cs' => 'C#',
            'sql' => 'SQL',
            'yml', 'yaml' => 'YAML',
            'json' => 'JSON',
            'sh', 'bash' => 'Shell',
            default => null,
        };
    }

    /** @param  array<int,string>  $patterns */
    protected function matchesAny(string $path, array $patterns): bool
    {
        $basename = basename($path);

        foreach ($patterns as $pattern) {
            // Match against both the full path and the basename: patterns like
            // "package.json" should hit "frontend/package.json" too, while
            // ".github/workflows/*" must match on the path.
            if (Str::is($pattern, $path) || Str::is($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }
}
