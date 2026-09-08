<?php

declare(strict_types=1);

namespace App\Services\Remediation;

use App\Exceptions\Remediation\PatchDoesNotApply;

/**
 * Applies a unified diff to file contents, or refuses.
 *
 * Deliberately strict. `patch(1)` will fuzz — shifting hunks and dropping
 * context to make something apply — which is the right trade for a human at a
 * terminal who can read the result, and the wrong one for an automated commit
 * nobody has looked at yet. Context here must match exactly; anything else
 * throws, and the refusal is shown to the user as "the file has changed".
 *
 * The alternative was cloning the repository and shelling out to git. That means
 * credentials on disk, a working tree per remediation, and `git apply`'s own
 * fuzz behaviour. Parsing the diff keeps the whole operation to two API calls
 * and makes the failure mode inspectable.
 */
class PatchApplier
{
    /**
     * Hunk headers carry line numbers that were true when the diff was written.
     * Earlier hunks change the file's length, so later ones need an offset — and
     * unrelated edits above may have shifted things further. A bounded search
     * finds the real position; matching stays exact once found.
     */
    private const SEARCH_WINDOW = 40;

    /**
     * @return array<string,string> path => new contents
     *
     * @throws PatchDoesNotApply
     */
    public function applyAll(string $patch, callable $readFile): array
    {
        $files = $this->parse($patch);

        if ($files === []) {
            throw new PatchDoesNotApply('The patch contains no recognisable file changes.');
        }

        $result = [];

        foreach ($files as $path => $hunks) {
            $original = $readFile($path);

            if ($original === null) {
                throw new PatchDoesNotApply("`{$path}` could not be read from the repository.");
            }

            $result[$path] = $this->applyHunks($path, $original, $hunks);
        }

        return $result;
    }

    /** @return array<int,string> the paths a patch claims to modify */
    public function paths(string $patch): array
    {
        return array_keys($this->parse($patch));
    }

    /**
     * @return array<string,array<int,array{old_start:int,lines:array<int,string>}>>
     */
    private function parse(string $patch): array
    {
        $files = [];
        $path = null;
        $hunkIndex = -1;

        $lines = preg_split('/\R/', $patch) ?: [];

        // Every real patch ends with a newline, which splits into a trailing
        // empty element. Left in, it becomes a phantom blank context line the
        // hunk then demands of the file — so nothing applies. Interior blank
        // lines are meaningful and stay.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        foreach ($lines as $line) {
            // `+++ b/path` names the file after the change, which is the one we
            // are writing. A `/dev/null` target means a deletion, which this
            // executor does not perform.
            if (str_starts_with($line, '+++ ')) {
                $target = trim(substr($line, 4));
                $target = preg_replace('/\t.*$/', '', $target) ?? $target;

                if ($target === '/dev/null') {
                    $path = null;

                    continue;
                }

                $path = preg_replace('#^[ab]/#', '', $target) ?? $target;
                $files[$path] ??= [];
                $hunkIndex = count($files[$path]) - 1;

                continue;
            }

            if ($path === null) {
                continue;
            }

            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m) === 1) {
                $files[$path][] = ['old_start' => (int) $m[1], 'lines' => []];
                $hunkIndex = count($files[$path]) - 1;

                continue;
            }

            if ($hunkIndex < 0 || ! isset($files[$path][$hunkIndex])) {
                continue;
            }

            // A hunk body line is context, removal, addition, or the
            // "\ No newline at end of file" marker.
            if ($line === '' || in_array($line[0], [' ', '+', '-', '\\'], true)) {
                $files[$path][$hunkIndex]['lines'][] = $line;
            }
        }

        return array_filter($files, fn (array $hunks) => $hunks !== []);
    }

    /**
     * @param  array<int,array{old_start:int,lines:array<int,string>}>  $hunks
     *
     * @throws PatchDoesNotApply
     */
    private function applyHunks(string $path, string $original, array $hunks): string
    {
        $endsWithNewline = $original === '' || str_ends_with($original, "\n");
        $lines = preg_split('/\R/', rtrim($original, "\n")) ?: [];

        if ($original === '') {
            $lines = [];
        }

        $offset = 0;

        foreach ($hunks as $number => $hunk) {
            // Diff line numbers are 1-based.
            $expected = $hunk['old_start'] - 1 + $offset;
            $at = $this->locate($lines, $hunk['lines'], $expected);

            if ($at === null) {
                throw new PatchDoesNotApply(sprintf(
                    'Hunk %d of `%s` does not match the file: the lines it expects are no longer there.',
                    $number + 1, $path,
                ));
            }

            [$replacement, $consumed] = $this->rewrite($hunk['lines']);

            array_splice($lines, $at, $consumed, $replacement);
            $offset += count($replacement) - $consumed;
        }

        return implode("\n", $lines).($endsWithNewline ? "\n" : '');
    }

    /**
     * Finds where a hunk's "old" side actually sits, searching outward from the
     * position the header claims.
     *
     * @param  array<int,string>  $lines
     * @param  array<int,string>  $hunkLines
     */
    private function locate(array $lines, array $hunkLines, int $expected): ?int
    {
        $needle = [];

        foreach ($hunkLines as $line) {
            if ($line === '' || $line[0] === ' ') {
                $needle[] = mb_substr($line, 1);
            } elseif ($line[0] === '-') {
                $needle[] = mb_substr($line, 1);
            }
        }

        if ($needle === []) {
            // A pure insertion with no context: nothing to verify against, so
            // trust the stated position only if it exists.
            return $expected >= 0 && $expected <= count($lines) ? $expected : null;
        }

        for ($distance = 0; $distance <= self::SEARCH_WINDOW; $distance++) {
            foreach ($distance === 0 ? [0] : [-$distance, $distance] as $direction) {
                $candidate = $expected + $direction;

                if ($candidate >= 0 && $this->matchesAt($lines, $needle, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $lines
     * @param  array<int,string>  $needle
     */
    private function matchesAt(array $lines, array $needle, int $at): bool
    {
        foreach ($needle as $index => $line) {
            if (($lines[$at + $index] ?? null) !== $line) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,string>  $hunkLines
     * @return array{0:array<int,string>,1:int} the new lines, and how many old ones they replace
     */
    private function rewrite(array $hunkLines): array
    {
        $replacement = [];
        $consumed = 0;

        foreach ($hunkLines as $line) {
            if ($line !== '' && $line[0] === '\\') {
                continue;
            }

            $content = $line === '' ? '' : mb_substr($line, 1);
            $marker = $line === '' ? ' ' : $line[0];

            match ($marker) {
                '+' => $replacement[] = $content,
                '-' => $consumed++,
                default => [$replacement[] = $content, $consumed++],
            };
        }

        return [$replacement, $consumed];
    }
}
