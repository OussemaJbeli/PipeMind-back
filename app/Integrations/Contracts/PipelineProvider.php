<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Exceptions\Integrations\IntegrationUnauthorized;
use App\Integrations\DTO\NormalizedJob;
use App\Integrations\DTO\NormalizedPipeline;
use App\Integrations\DTO\ProviderIdentity;
use App\Integrations\DTO\RemoteProject;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;

/**
 * Every provider difference lives behind this interface. Nothing outside
 * App\Integrations may know what GitLab is.
 */
interface PipelineProvider
{
    public static function key(): string;

    /** Verify credentials and report what the token can actually do. */
    public function verify(Integration $integration): ProviderIdentity;

    /** @return array<int,RemoteProject> */
    public function remoteProjects(Integration $integration, ?string $search = null, int $page = 1): array;

    /** Register or refresh our webhook. Returns the provider's hook id. */
    public function registerWebhook(Integration $integration, Project $project, string $url, string $secret): string;

    public function removeWebhook(Integration $integration, Project $project, string $hookId): void;

    /** Did this request actually come from the provider? */
    public function verifySignature(Integration $integration, string $rawBody, array $headers): bool;

    /** Our internal event type, or null to ignore this delivery entirely. */
    public function eventType(array $payload, array $headers): ?string;

    /** Provider identifier for the pipeline this payload concerns. */
    public function externalPipelineId(array $payload): ?string;

    /** Provider identifier for the project this payload concerns. */
    public function externalProjectId(array $payload): ?string;

    /** Pure transformation. No database writes. */
    public function normalizePipeline(array $payload): NormalizedPipeline;

    /** @return array<int,NormalizedJob> */
    public function normalizeJobs(array $payload): array;

    /*
     * Authoritative fetches. Webhooks are hints; the API is truth.
     */

    public function fetchPipeline(Integration $integration, Project $project, string $externalId): NormalizedPipeline;

    /** @return array<int,NormalizedJob> */
    public function fetchJobs(Integration $integration, Project $project, string $externalId): array;

    public function fetchJobLog(Integration $integration, Project $project, PipelineJob $job): string;

    /** @return array<int,array<string,mixed>> */
    public function fetchCommitChanges(Integration $integration, Project $project, string $sha): array;

    /*
     * Actions. Used by remediation in roadmaps/18 — always through Laravel,
     * never by the AI service.
     */

    /**
     * Raw file contents at a commit, or null when unavailable.
     *
     * Needed so an analysis can quote the line a stack trace points at instead of
     * describing the file it lives in. Returning null is a normal outcome: the
     * file may be gitignored, binary, too large, or generated at build time.
     */
    public function fetchFileContents(
        Integration $integration,
        Project $project,
        string $path,
        ?string $ref = null,
    ): ?string;

    public function retryJob(Integration $integration, Project $project, PipelineJob $job): array;

    public function retryPipeline(Integration $integration, Project $project, Pipeline $pipeline): array;

    public function cancelPipeline(Integration $integration, Project $project, Pipeline $pipeline): void;

    public function createIssue(Integration $integration, Project $project, string $title, string $body): array;

    /*
     * Repository writes. Only reached by CreateMergeRequestExecutor, only after
     * the policy gate and a human approval, and never against the default
     * branch — the whole point of proposing a change as a pull request is that
     * somebody still reviews it.
     */

    /** Does this provider host a repository we can commit to at all? */
    public function supportsMergeRequests(): bool;

    /**
     * Creates a branch at $fromSha.
     *
     * @throws IntegrationUnauthorized when the
     *                                 token cannot write — a read-only token is the common case and the error
     *                                 has to say so rather than surface as a generic failure.
     */
    public function createBranch(Integration $integration, Project $project, string $branch, string $fromSha): void;

    /**
     * Commits file contents to an existing branch.
     *
     * @param  array<string,string>  $files  path => complete new contents
     * @return array<string,mixed> the provider's commit payload
     */
    public function commitFiles(
        Integration $integration,
        Project $project,
        string $branch,
        array $files,
        string $message,
    ): array;

    /** @return array<string,mixed> the provider's merge/pull request payload */
    public function openMergeRequest(
        Integration $integration,
        Project $project,
        string $head,
        string $base,
        string $title,
        string $body,
    ): array;
}
