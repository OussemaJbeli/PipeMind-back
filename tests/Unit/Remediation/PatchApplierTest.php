<?php

declare(strict_types=1);

use App\Exceptions\Remediation\PatchDoesNotApply;
use App\Services\Remediation\PatchApplier;

function applier(): PatchApplier
{
    return new PatchApplier;
}

/** @param array<string,string> $files */
function applyTo(string $patch, array $files): array
{
    return applier()->applyAll($patch, fn (string $path) => $files[$path] ?? null);
}

it('applies a single-line replacement', function () {
    $original = "line one\nline two\nline three\n";
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    DIFF;

    expect(applyTo($patch, ['file.txt' => $original]))
        ->toBe(['file.txt' => "line one\nline TWO\nline three\n"]);
});

it('applies the real fix from the database runbook', function () {
    $original = <<<'YAML'
    services:
      postgres:
        image: postgres:16
      test:
        depends_on:
          - postgres
    YAML;

    $patch = <<<'DIFF'
    --- a/docker-compose.ci.yml
    +++ b/docker-compose.ci.yml
    @@ -1,6 +1,11 @@
     services:
       postgres:
         image: postgres:16
    +    healthcheck:
    +      test: ["CMD-SHELL", "pg_isready -U postgres"]
    +      interval: 2s
    +      retries: 15
       test:
         depends_on:
    -      - postgres
    +      postgres:
    +        condition: service_healthy
    DIFF;

    $result = applyTo($patch, ['docker-compose.ci.yml' => $original]);

    expect($result['docker-compose.ci.yml'])
        ->toContain('pg_isready -U postgres')
        ->toContain('condition: service_healthy')
        ->not->toContain('      - postgres');
});

it('refuses when the context no longer matches', function () {
    // The whole safety property. Somebody edited the same line between the
    // analysis and the approval; a fuzzy apply would silently commit code
    // nobody wrote.
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    DIFF;

    expect(fn () => applyTo($patch, ['file.txt' => "line one\nsomething else\nline three\n"]))
        ->toThrow(PatchDoesNotApply::class);
});

it('finds a hunk that has shifted position', function () {
    // An unrelated edit above pushed the target down four lines. The header's
    // numbers are stale but the content is untouched, so this must still apply —
    // refusing here would make the feature unusable on any active repository.
    $original = "new\nlines\nadded\nabove\nline one\nline two\nline three\n";
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -1,3 +1,3 @@
     line one
    -line two
    +line TWO
     line three
    DIFF;

    expect(applyTo($patch, ['file.txt' => $original])['file.txt'])
        ->toBe("new\nlines\nadded\nabove\nline one\nline TWO\nline three\n");
});

it('applies several hunks whose positions shift as it goes', function () {
    $original = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 20)))."\n";
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -2,3 +2,4 @@
     line 2
    -line 3
    +line THREE
    +line THREE AND A HALF
     line 4
    @@ -15,3 +16,3 @@
     line 15
    -line 16
    +line SIXTEEN
     line 17
    DIFF;

    $result = applyTo($patch, ['file.txt' => $original])['file.txt'];

    // The second hunk's old_start is stale by the first hunk's growth. Getting
    // this wrong corrupts the file rather than failing, so it is the case worth
    // pinning.
    expect($result)->toContain('line THREE AND A HALF')->toContain('line SIXTEEN')
        ->and($result)->not->toContain("line 16\n")
        ->and(substr_count($result, "\n"))->toBe(21);
});

it('refuses a patch touching a file it cannot read', function () {
    $patch = <<<'DIFF'
    --- a/missing.txt
    +++ b/missing.txt
    @@ -1,1 +1,1 @@
    -a
    +b
    DIFF;

    // Reachable: the file may be gitignored, generated, or deleted since.
    expect(fn () => applyTo($patch, []))
        ->toThrow(PatchDoesNotApply::class, 'could not be read');
});

it('refuses a patch with nothing recognisable in it', function () {
    expect(fn () => applyTo('I suggest you add a healthcheck to the compose file.', []))
        ->toThrow(PatchDoesNotApply::class);
});

it('reports which files a patch claims to touch', function () {
    $patch = <<<'DIFF'
    --- a/one.txt
    +++ b/one.txt
    @@ -1,1 +1,1 @@
    -a
    +b
    --- a/two.yml
    +++ b/two.yml
    @@ -1,1 +1,1 @@
    -c
    +d
    DIFF;

    // The registry checks these against the recommendation's declared files
    // before anything is written.
    expect(applier()->paths($patch))->toBe(['one.txt', 'two.yml']);
});

it('ignores a deletion target rather than writing an empty file', function () {
    $patch = <<<'DIFF'
    --- a/gone.txt
    +++ /dev/null
    @@ -1,1 +0,0 @@
    -a
    DIFF;

    // File deletion is not an action this executor performs, and treating
    // /dev/null as a path would create a file called "/dev/null".
    expect(applier()->paths($patch))->toBe([]);
});

it('preserves a file that had no trailing newline', function () {
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -1,2 +1,2 @@
     first
    -second
    +SECOND
    \ No newline at end of file
    DIFF;

    expect(applyTo($patch, ['file.txt' => "first\nsecond"])['file.txt'])
        ->toBe("first\nSECOND");
});

it('handles a pure addition with surrounding context', function () {
    $patch = <<<'DIFF'
    --- a/file.txt
    +++ b/file.txt
    @@ -1,2 +1,3 @@
     first
    +inserted
     second
    DIFF;

    expect(applyTo($patch, ['file.txt' => "first\nsecond\n"])['file.txt'])
        ->toBe("first\ninserted\nsecond\n");
});

it('applies a patch that ends with a newline, as every real one does', function () {
    // Regression. `preg_split` on a trailing newline yields an empty final
    // element, which the parser read as a blank context line and then demanded
    // of the file — so nothing applied. Every heredoc fixture above happens to
    // end without a newline, which is why they all passed while the real path
    // was broken. Built with an explicit "\n" for that reason.
    $patch = "--- a/file.txt\n+++ b/file.txt\n@@ -1,2 +1,2 @@\n first\n-second\n+SECOND\n";

    expect(applyTo($patch, ['file.txt' => "first\nsecond\n"])['file.txt'])
        ->toBe("first\nSECOND\n");
});

it('keeps a blank line that is genuine context', function () {
    // The trailing-newline fix must not swallow interior blank lines: a diff
    // through a paragraph break has an empty context line in the middle.
    $patch = "--- a/file.txt\n+++ b/file.txt\n@@ -1,4 +1,4 @@\n first\n\n-third\n+THIRD\n";

    expect(applyTo($patch, ['file.txt' => "first\n\nthird\n"])['file.txt'])
        ->toBe("first\n\nTHIRD\n");
});
