<?php

declare(strict_types=1);

it('is not running against a cached config', function () {
    // bootstrap/cache/config.php overrides every <env> in phpunit.xml, so a
    // stale one silently sends queued jobs to Redis instead of running them
    // inline — tests then fail in ways that look like application bugs.
    expect(file_exists(base_path('bootstrap/cache/config.php')))
        ->toBeFalse('Run `php artisan config:clear` — a cached config overrides the test environment.');
});

it('runs queued jobs inline', function () {
    expect(config('queue.default'))->toBe('sync');
});

it('uses the dedicated test database', function () {
    expect(config('database.connections.pgsql.database'))->toBe('pipemind_test');
});
