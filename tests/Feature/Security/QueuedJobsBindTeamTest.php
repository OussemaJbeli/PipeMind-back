<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('binds a team in every queued job', function () {
    $jobsPath = app_path('Jobs');

    if (! File::isDirectory($jobsPath)) {
        expect(true)->toBeTrue();

        return;
    }

    $offenders = [];

    foreach (File::allFiles($jobsPath) as $file) {
        // A worker has no authenticated user, so TeamScope is inert without an
        // explicit binding. Without withTeam() the job silently operates across
        // every tenant — the most likely source of a cross-tenant leak.
        if (! str_contains(File::get($file->getPathname()), 'withTeam(')) {
            $offenders[] = $file->getFilename();
        }
    }

    // Naming the offenders in the failure beats a bare boolean.
    expect($offenders)->toBe([], 'These jobs do not bind a team: '.implode(', ', $offenders));
});
