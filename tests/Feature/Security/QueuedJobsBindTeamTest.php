<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('binds a team in every queued job', function () {
    $jobsPath = app_path('Jobs');

    if (! File::isDirectory($jobsPath)) {
        expect(true)->toBeTrue();   // no jobs yet — file 05 adds them

        return;
    }

    foreach (File::allFiles($jobsPath) as $file) {
        $source = File::get($file->getPathname());

        // A worker has no authenticated user, so TeamScope is inert without an
        // explicit binding. Without withTeam() the job silently operates across
        // every tenant — the most likely source of a cross-tenant leak.
        expect($source)->toContain(
            'withTeam(',
            "{$file->getFilename()} does not bind a team"
        );
    }
});
