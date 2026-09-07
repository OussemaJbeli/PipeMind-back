<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\PipelineStatus;
use App\Integrations\Support\StatusMapper;

/**
 * Pipelines and jobs use different status vocabularies, and every provider
 * mapper speaks the pipeline one.
 *
 * A queued GitHub job therefore raised
 * `"queued" is not a valid backing value for enum JobStatus` and killed six of
 * eighteen real webhook deliveries — silently, in a queue, long after the
 * webhook returned 202.
 */
it('translates every pipeline status into something a job can hold', function () {
    foreach (PipelineStatus::cases() as $status) {
        $translated = StatusMapper::forJob($status->value);

        expect(JobStatus::tryFrom($translated))
            ->not->toBeNull("pipeline status '{$status->value}' has no job equivalent");
    }
});

it('maps queued to pending and leaves everything else alone', function () {
    expect(StatusMapper::forJob('queued'))->toBe('pending');

    foreach (['running', 'success', 'failed', 'canceled', 'skipped', 'manual', 'timeout'] as $status) {
        expect(StatusMapper::forJob($status))->toBe($status);
    }
});

it('produces a valid job status from every provider mapper', function () {
    // The real defect was a provider mapper feeding a job constructor directly.
    // These are the exact calls the providers now make.
    $cases = [
        StatusMapper::forJob(StatusMapper::github('queued', null)),
        StatusMapper::forJob(StatusMapper::github('in_progress', null)),
        StatusMapper::forJob(StatusMapper::github('completed', 'failure')),
        StatusMapper::forJob(StatusMapper::gitlab('created')),
        StatusMapper::forJob(StatusMapper::gitlab('pending')),
        StatusMapper::forJob(StatusMapper::jenkins(null, true)),
        StatusMapper::forJob(StatusMapper::generic('unknown-thing')),
    ];

    foreach ($cases as $status) {
        expect(JobStatus::tryFrom($status))->not->toBeNull("'{$status}' is not a JobStatus");
    }
});
