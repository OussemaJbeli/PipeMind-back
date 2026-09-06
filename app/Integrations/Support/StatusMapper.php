<?php

declare(strict_types=1);

namespace App\Integrations\Support;

/**
 * Provider status vocabularies → ours.
 *
 * The most consequential table in the ingestion layer: get it wrong and every
 * chart, every success rate and every MTTR figure lies. Each mapping below has
 * a unit test.
 */
final class StatusMapper
{
    /** GitLab pipeline and job statuses. */
    private const GITLAB = [
        'created' => 'queued',
        'waiting_for_resource' => 'queued',
        'preparing' => 'queued',
        'pending' => 'queued',
        'running' => 'running',
        'canceling' => 'running',
        'success' => 'success',
        'failed' => 'failed',
        'canceled' => 'canceled',
        'cancelled' => 'canceled',
        'skipped' => 'skipped',
        'manual' => 'manual',
        'scheduled' => 'manual',
    ];

    public static function gitlab(?string $status, ?string $failureReason = null): string
    {
        $mapped = self::GITLAB[strtolower((string) $status)] ?? 'queued';

        // GitLab reports a timeout as a plain failure; failure_reason is the only
        // signal. Timeouts must stay distinguishable because they are usually
        // transient, and a transient failure should be retried rather than analysed.
        if ($mapped === 'failed' && $failureReason === 'job_execution_timeout') {
            return 'timeout';
        }

        return $mapped;
    }

    /**
     * GitHub Actions splits state across two fields: `status` while in flight,
     * `conclusion` once complete.
     */
    public static function github(?string $status, ?string $conclusion = null): string
    {
        $status = strtolower((string) $status);

        if ($status !== 'completed') {
            return match ($status) {
                'in_progress' => 'running',
                'queued', 'requested', 'waiting', 'pending' => 'queued',
                default => 'queued',
            };
        }

        return match (strtolower((string) $conclusion)) {
            'success' => 'success',
            'failure' => 'failed',
            'cancelled', 'canceled' => 'canceled',
            'timed_out' => 'timeout',
            'skipped', 'neutral' => 'skipped',
            'action_required' => 'manual',
            'stale' => 'skipped',
            // A completed run with an unrecognised conclusion is not a success.
            default => 'failed',
        };
    }

    public static function jenkins(?string $result, bool $building = false): string
    {
        if ($building) {
            return 'running';
        }

        if ($result === null || $result === '') {
            return 'queued';
        }

        return match (strtoupper($result)) {
            'SUCCESS' => 'success',
            // UNSTABLE means "built, but tests failed". Teams that treat it as
            // green are the ones with a broken suite nobody notices.
            'FAILURE', 'UNSTABLE' => 'failed',
            'ABORTED' => 'canceled',
            'NOT_BUILT' => 'skipped',
            default => 'failed',
        };
    }

    /** The generic webhook already speaks our vocabulary; validate rather than map. */
    public static function generic(?string $status): string
    {
        $status = strtolower((string) $status);

        return in_array($status, [
            'queued', 'running', 'success', 'failed',
            'canceled', 'skipped', 'manual', 'timeout',
        ], true) ? $status : 'queued';
    }

    /** GitLab pipeline `source` → ours. */
    public static function gitlabSource(?string $source): string
    {
        return match (strtolower((string) $source)) {
            'push' => 'push',
            'merge_request_event' => 'merge_request',
            'schedule' => 'schedule',
            'web' => 'web',
            'api' => 'api',
            'trigger', 'pipeline' => 'trigger',
            'chat', 'external' => 'api',
            default => 'unknown',
        };
    }

    /** GitHub workflow `event` → ours. */
    public static function githubSource(?string $event): string
    {
        return match (strtolower((string) $event)) {
            'push' => 'push',
            'pull_request', 'pull_request_target' => 'merge_request',
            'schedule' => 'schedule',
            'workflow_dispatch' => 'manual',
            'repository_dispatch' => 'api',
            'release', 'create' => 'tag',
            default => 'unknown',
        };
    }
}
