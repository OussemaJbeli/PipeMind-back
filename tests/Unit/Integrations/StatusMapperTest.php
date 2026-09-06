<?php

declare(strict_types=1);

use App\Integrations\Support\StatusMapper;

describe('gitlab', function () {
    it('maps every documented status', function (string $input, string $expected) {
        expect(StatusMapper::gitlab($input))->toBe($expected);
    })->with([
        ['created', 'queued'],
        ['waiting_for_resource', 'queued'],
        ['preparing', 'queued'],
        ['pending', 'queued'],
        ['running', 'running'],
        ['canceling', 'running'],
        ['success', 'success'],
        ['failed', 'failed'],
        ['canceled', 'canceled'],
        ['skipped', 'skipped'],
        ['manual', 'manual'],
        ['scheduled', 'manual'],
    ]);

    it('distinguishes a timeout from a plain failure', function () {
        // GitLab reports both as "failed"; failure_reason is the only signal,
        // and timeouts matter because they are usually transient.
        expect(StatusMapper::gitlab('failed'))->toBe('failed')
            ->and(StatusMapper::gitlab('failed', 'job_execution_timeout'))->toBe('timeout')
            ->and(StatusMapper::gitlab('failed', 'script_failure'))->toBe('failed');
    });

    it('defaults an unknown status to queued rather than guessing', function () {
        expect(StatusMapper::gitlab('brand_new_status'))->toBe('queued');
    });
});

describe('github', function () {
    it('reads in-flight state from status', function (string $status, string $expected) {
        expect(StatusMapper::github($status, null))->toBe($expected);
    })->with([
        ['queued', 'queued'],
        ['requested', 'queued'],
        ['waiting', 'queued'],
        ['in_progress', 'running'],
    ]);

    it('reads terminal state from conclusion', function (string $conclusion, string $expected) {
        expect(StatusMapper::github('completed', $conclusion))->toBe($expected);
    })->with([
        ['success', 'success'],
        ['failure', 'failed'],
        ['cancelled', 'canceled'],
        ['timed_out', 'timeout'],
        ['skipped', 'skipped'],
        ['neutral', 'skipped'],
        ['action_required', 'manual'],
    ]);

    it('treats a completed run with an unknown conclusion as failed', function () {
        // Not success: an unrecognised outcome must never inflate the success rate.
        expect(StatusMapper::github('completed', 'something_new'))->toBe('failed');
    });
});

describe('jenkins', function () {
    it('maps results', function (?string $result, bool $building, string $expected) {
        expect(StatusMapper::jenkins($result, $building))->toBe($expected);
    })->with([
        ['SUCCESS', false, 'success'],
        ['FAILURE', false, 'failed'],
        ['ABORTED', false, 'canceled'],
        ['NOT_BUILT', false, 'skipped'],
        [null, true, 'running'],
        [null, false, 'queued'],
    ]);

    it('treats UNSTABLE as failed', function () {
        // "Built, but tests failed". Teams that count this as green are the ones
        // with a broken suite nobody notices.
        expect(StatusMapper::jenkins('UNSTABLE'))->toBe('failed');
    });
});

describe('generic', function () {
    it('accepts our vocabulary and rejects anything else', function () {
        expect(StatusMapper::generic('failed'))->toBe('failed')
            ->and(StatusMapper::generic('SUCCESS'))->toBe('success')
            ->and(StatusMapper::generic('bogus'))->toBe('queued');
    });
});
