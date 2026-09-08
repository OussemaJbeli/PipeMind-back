<?php

declare(strict_types=1);

namespace App\Integrations\Providers;

use App\Exceptions\Integrations\LogNotAvailable;
use App\Integrations\Contracts\PipelineProvider;
use App\Integrations\DTO\NormalizedJob;
use App\Integrations\DTO\NormalizedPipeline;
use App\Integrations\DTO\ProviderIdentity;
use App\Integrations\Support\StatusMapper;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use RuntimeException;

/**
 * Accepts the documented PipeMind envelope so any platform can integrate without
 * a bespoke adapter. Push-only: there is no API to read back from, so everything
 * has to arrive in the payload — including inline logs.
 *
 * Envelope: PipeMind-data/contracts/v1/generic-webhook.md
 */
class GenericProvider implements PipelineProvider
{
    public static function key(): string
    {
        return 'generic';
    }

    public function verify(Integration $integration): ProviderIdentity
    {
        // Nothing to call. The integration is valid as soon as it exists.
        return new ProviderIdentity(
            username: 'webhook',
            name: $integration->name,
            canReadProjects: false,
            canRetryJobs: false,
            canWriteIssues: false,
        );
    }

    public function remoteProjects(Integration $integration, ?string $search = null, int $page = 1): array
    {
        // Push-only: projects are created when their first event arrives.
        return [];
    }

    public function registerWebhook(Integration $integration, Project $project, string $url, string $secret): string
    {
        // The user wires this up on their side; we have nothing to register.
        return '';
    }

    public function removeWebhook(Integration $integration, Project $project, string $hookId): void
    {
        //
    }

    public function verifySignature(Integration $integration, string $rawBody, array $headers): bool
    {
        $token = $this->header($headers, 'x-pipemind-token');

        if ($token === null || ! hash_equals($integration->webhook_secret, $token)) {
            return false;
        }

        // Replay protection: without an HMAC over the body, a captured request
        // could otherwise be resent indefinitely.
        $timestamp = $this->header($headers, 'x-pipemind-timestamp');

        if ($timestamp === null) {
            return false;
        }

        $sent = (int) $timestamp;
        $sent = $sent > 9_999_999_999 ? intdiv($sent, 1000) : $sent;   // accept ms or s
        $tolerance = (int) config('pipemind.ingestion.webhook_tolerance_seconds');

        return abs(time() - $sent) <= $tolerance;
    }

    public function eventType(array $payload, array $headers): ?string
    {
        return match ($payload['event'] ?? null) {
            'pipeline' => 'pipeline',
            'job' => 'job',
            default => null,
        };
    }

    public function externalPipelineId(array $payload): ?string
    {
        return isset($payload['external_id']) ? (string) $payload['external_id'] : null;
    }

    public function externalProjectId(array $payload): ?string
    {
        return isset($payload['project']) ? (string) $payload['project'] : null;
    }

    public function normalizePipeline(array $payload): NormalizedPipeline
    {
        $commit = $payload['commit'] ?? [];

        return new NormalizedPipeline(
            externalId: (string) ($payload['external_id'] ?? ''),
            iid: isset($payload['iid']) ? (int) $payload['iid'] : null,
            provider: (string) ($payload['provider'] ?? 'custom'),
            status: StatusMapper::generic($payload['status'] ?? null),
            source: (string) ($payload['source'] ?? 'unknown'),
            ref: (string) ($payload['ref'] ?? 'unknown'),
            commitSha: $commit['sha'] ?? null,
            commitMessage: $commit['message'] ?? null,
            commitAuthorName: $commit['author_name'] ?? null,
            commitAuthorEmail: $commit['author_email'] ?? null,
            webUrl: $payload['web_url'] ?? null,
            startedAt: $payload['started_at'] ?? null,
            finishedAt: $payload['finished_at'] ?? null,
            durationSeconds: isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : null,
            raw: $payload,
        );
    }

    public function normalizeJobs(array $payload): array
    {
        return collect($payload['jobs'] ?? [])->values()->map(
            fn (array $job, int $i) => new NormalizedJob(
                externalId: (string) ($job['external_id'] ?? $i),
                name: (string) ($job['name'] ?? "job-{$i}"),
                stageName: (string) ($job['stage'] ?? 'unknown'),
                status: StatusMapper::forJob(StatusMapper::generic($job['status'] ?? null)),
                position: $i,
                exitCode: isset($job['exit_code']) ? (int) $job['exit_code'] : null,
                startedAt: $job['started_at'] ?? null,
                finishedAt: $job['finished_at'] ?? null,
                durationSeconds: isset($job['duration_seconds']) ? (int) $job['duration_seconds'] : null,
                raw: $job,
            ),
        )->all();
    }

    public function fetchPipeline(Integration $integration, Project $project, string $externalId): NormalizedPipeline
    {
        throw new RuntimeException('The generic provider is push-only and cannot be polled.');
    }

    public function fetchJobs(Integration $integration, Project $project, string $externalId): array
    {
        throw new RuntimeException('The generic provider is push-only and cannot be polled.');
    }

    public function fetchJobLog(Integration $integration, Project $project, PipelineJob $job): string
    {
        // The log has to arrive inline; there is nowhere to fetch it from.
        $log = $job->raw_payload['log'] ?? null;

        if (! is_string($log) || $log === '') {
            throw new LogNotAvailable(
                'No log was included in the webhook payload, and this provider cannot be polled.',
            );
        }

        return $log;
    }

    public function fetchCommitChanges(Integration $integration, Project $project, string $sha): array
    {
        return [];
    }

    /** A generic webhook source exposes no repository to read from. */
    public function fetchFileContents(
        Integration $integration,
        Project $project,
        string $path,
        ?string $ref = null,
    ): ?string {
        return null;
    }

    public function retryJob(Integration $integration, Project $project, PipelineJob $job): array
    {
        throw new RuntimeException('The generic provider cannot trigger actions.');
    }

    public function retryPipeline(Integration $integration, Project $project, Pipeline $pipeline): array
    {
        throw new RuntimeException('The generic provider cannot trigger actions.');
    }

    public function cancelPipeline(Integration $integration, Project $project, Pipeline $pipeline): void
    {
        throw new RuntimeException('The generic provider cannot trigger actions.');
    }

    public function createIssue(Integration $integration, Project $project, string $title, string $body): array
    {
        throw new RuntimeException('The generic provider cannot trigger actions.');
    }

    public function supportsMergeRequests(): bool
    {
        return false;
    }

    public function createBranch(Integration $integration, Project $project, string $branch, string $fromSha): void
    {
        throw new RuntimeException(static::key().' hosts no repository, so it cannot create branches.');
    }

    /**
     * @param  array<string,string>  $files
     * @return array<string,mixed>
     */
    public function commitFiles(
        Integration $integration,
        Project $project,
        string $branch,
        array $files,
        string $message,
    ): array {
        throw new RuntimeException(static::key().' hosts no repository, so it cannot commit files.');
    }

    /** @return array<string,mixed> */
    public function openMergeRequest(
        Integration $integration,
        Project $project,
        string $head,
        string $base,
        string $title,
        string $body,
    ): array {
        throw new RuntimeException(static::key().' cannot open merge requests.');
    }

    protected function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
