<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;

/**
 * Every queued job must bind a team before touching team-scoped data.
 *
 * A queue worker has no session, so `TeamScope` is a no-op there — it filters on
 * `currentTeamId()` and finds nothing bound. A job that queries without
 * `withTeam()` therefore reads across EVERY workspace and silently writes rows
 * against the wrong one. That is the class of bug that ends projects, and it
 * cannot be caught by reading a diff.
 *
 * Asserted structurally rather than by grep: grep matches the word in a comment
 * and cannot tell a call from a mention.
 */
function jobFiles(): array
{
    return array_map(
        fn (string $path) => 'App\\Jobs\\'.basename($path, '.php'),
        glob(app_path('Jobs/*.php')) ?: [],
    );
}

it('finds the jobs to check', function () {
    // A rename that empties the glob would make every assertion below pass
    // vacuously, which is worse than no test at all.
    expect(jobFiles())->not->toBeEmpty();
});

it('binds a team in every job that reaches team-scoped data', function () {
    $offenders = [];

    foreach (jobFiles() as $class) {
        $file = new ReflectionClass($class);
        $source = file_get_contents($file->getFileName());

        // Strip comments first, so a job that merely MENTIONS withTeam in a
        // docblock cannot pass by talking about it.
        $code = implode('', array_map(
            fn (array $token) => in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : ($token[1] ?? ''),
            array_map(fn ($t) => is_array($t) ? $t : [null, $t], token_get_all($source)),
        ));

        $bindsTeam = str_contains($code, 'withTeam(');

        // A job that only ever queries withoutGlobalScopes() is explicitly
        // opting out of tenancy and states so at every call site.
        $alwaysUnscoped = ! preg_match(
            '/\b(Failure|Analysis|Anomaly|Project|Pipeline|AiRequest|FailureSignature|KnowledgeChunk)::(?!withoutGlobalScopes)\w/',
            $code,
        );

        if (! $bindsTeam && ! $alwaysUnscoped) {
            $offenders[] = class_basename($class);
        }
    }

    expect($offenders)->toBe([], 'these jobs query team-scoped models without withTeam(): '
        .implode(', ', $offenders));
});

it('proves the scope really is inert with no team bound', function () {
    // The premise the whole invariant rests on. If this ever stopped being true,
    // the test above would be guarding against nothing.
    $teams = Team::factory()->count(2)->create();

    foreach ($teams as $team) {
        Project::factory()->create(['team_id' => $team->id]);
    }

    // No team bound — exactly a queue worker's situation.
    expect(currentTeamId())->toBeNull()
        ->and(Project::count())->toBe(2);

    withTeam($teams->first(), function (): void {
        expect(Project::count())->toBe(1);
    });
});
