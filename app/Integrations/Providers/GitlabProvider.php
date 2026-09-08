<?php

declare(strict_types=1);

namespace App\Integrations\Providers;

use App\Exceptions\Integrations\IntegrationUnauthorized;
use App\Exceptions\Integrations\IntegrationUnreachable;
use App\Exceptions\Integrations\LogNotAvailable;
use App\Exceptions\Integrations\ProviderRateLimited;
use App\Integrations\Contracts\PipelineProvider;
use App\Integrations\DTO\NormalizedJob;
use App\Integrations\DTO\NormalizedPipeline;
use App\Integrations\DTO\ProviderIdentity;
use App\Integrations\DTO\RemoteProject;
use App\Integrations\Support\StatusMapper;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitlabProvider implements PipelineProvider
{
    public static function key(): string
    {
        return 'gitlab';
    }

    protected function http(Integration $integration): PendingRequest
    {
        $base = rtrim($integration->base_url ?: 'https://gitlab.com', '/');

        return Http::baseUrl($base.'/api/v4')
            ->withHeaders(['PRIVATE-TOKEN' => (string) $integration->token()])
            ->timeout(30)
            ->connectTimeout(8)
            ->retry(2, 400, throw: false)
            ->acceptJson();
    }

    /** Translate provider HTTP failures into our typed exceptions. */
    protected function guard(Response $response, string $context): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw match ($response->status()) {
            401, 403 => new IntegrationUnauthorized("GitLab rejected the stored credentials ({$context})."),
            429 => new ProviderRateLimited("GitLab is rate limiting requests ({$context})."),
            404 => new IntegrationUnreachable("GitLab returned 404 for {$context}."),
            default => new IntegrationUnreachable("GitLab request failed ({$context}, HTTP {$response->status()})."),
        };
    }

    public function verify(Integration $integration): ProviderIdentity
    {
        try {
            $user = $this->guard($this->http($integration)->get('/user'), 'user')->json();
        } catch (ConnectionException $e) {
            throw new IntegrationUnreachable('Could not reach the GitLab instance.', previous: $e);
        }

        // Scopes are not exposed by /user, so probe capability instead of guessing.
        $projects = $this->http($integration)->get('/projects', ['membership' => true, 'per_page' => 1]);
        $canRead = $projects->successful();
        $total = (int) $projects->header('X-Total') ?: null;

        return new ProviderIdentity(
            username: (string) ($user['username'] ?? 'unknown'),
            name: $user['name'] ?? null,
            avatarUrl: $user['avatar_url'] ?? null,
            scopes: [],
            canReadProjects: $canRead,
            // A read-only `read_api` token can monitor but cannot retry a job.
            // Surfacing this at connect time avoids a confusing failure later.
            canRetryJobs: $this->probeWriteScope($integration),
            canWriteIssues: $this->probeWriteScope($integration),
            projectCount: $total,
        );
    }

    protected function probeWriteScope(Integration $integration): bool
    {
        // GitLab exposes token metadata on /personal_access_tokens/self for PATs.
        $response = $this->http($integration)->get('/personal_access_tokens/self');

        if (! $response->successful()) {
            return false;
        }

        return in_array('api', (array) $response->json('scopes', []), true);
    }

    public function remoteProjects(Integration $integration, ?string $search = null, int $page = 1): array
    {
        $response = $this->guard(
            $this->http($integration)->get('/projects', array_filter([
                'membership' => true,
                'order_by' => 'last_activity_at',
                'per_page' => 50,
                'page' => $page,
                'search' => $search,
            ])),
            'projects',
        );

        return collect($response->json())->map(fn (array $p) => new RemoteProject(
            externalId: (string) $p['id'],
            name: $p['name'],
            path: $p['path_with_namespace'],
            description: $p['description'] ?? null,
            webUrl: $p['web_url'] ?? null,
            repositoryUrl: $p['http_url_to_repo'] ?? null,
            defaultBranch: $p['default_branch'] ?? 'main',
            lastActivityAt: $p['last_activity_at'] ?? null,
        ))->all();
    }

    public function registerWebhook(Integration $integration, Project $project, string $url, string $secret): string
    {
        $response = $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/hooks", [
                'url' => $url,
                'token' => $secret,
                'pipeline_events' => true,
                'job_events' => true,
                // Everything else is noise: we react to pipelines, not pushes.
                'push_events' => false,
                'merge_requests_events' => false,
                'enable_ssl_verification' => ! app()->isLocal(),
            ]),
            'register webhook',
        );

        return (string) $response->json('id');
    }

    public function removeWebhook(Integration $integration, Project $project, string $hookId): void
    {
        $this->http($integration)->delete("/projects/{$project->external_id}/hooks/{$hookId}");
    }

    public function verifySignature(Integration $integration, string $rawBody, array $headers): bool
    {
        $token = $this->header($headers, 'x-gitlab-token');

        // hash_equals, never ==: a timing-safe comparison on a shared secret.
        return $token !== null && hash_equals($integration->webhook_secret, $token);
    }

    public function eventType(array $payload, array $headers): ?string
    {
        return match ($payload['object_kind'] ?? null) {
            'pipeline' => 'pipeline',
            'build' => 'job',
            // Ignore push, issue, note and everything else outright.
            default => null,
        };
    }

    public function externalPipelineId(array $payload): ?string
    {
        return match ($payload['object_kind'] ?? null) {
            'pipeline' => isset($payload['object_attributes']['id'])
                ? (string) $payload['object_attributes']['id'] : null,
            'build' => isset($payload['pipeline_id']) ? (string) $payload['pipeline_id'] : null,
            default => null,
        };
    }

    public function externalProjectId(array $payload): ?string
    {
        return isset($payload['project']['id'])
            ? (string) $payload['project']['id']
            : (isset($payload['project_id']) ? (string) $payload['project_id'] : null);
    }

    public function normalizePipeline(array $payload): NormalizedPipeline
    {
        $attributes = $payload['object_attributes'] ?? $payload;
        $commit = $payload['commit'] ?? [];

        return new NormalizedPipeline(
            externalId: (string) $attributes['id'],
            iid: isset($attributes['iid']) ? (int) $attributes['iid'] : null,
            provider: 'gitlab',
            status: StatusMapper::gitlab($attributes['status'] ?? null),
            source: StatusMapper::gitlabSource($attributes['source'] ?? 'push'),
            ref: (string) ($attributes['ref'] ?? 'unknown'),
            isTag: (bool) ($attributes['tag'] ?? false),
            commitSha: $attributes['sha'] ?? ($commit['id'] ?? null),
            commitMessage: $commit['message'] ?? null,
            commitAuthorName: $commit['author']['name'] ?? null,
            commitAuthorEmail: $commit['author']['email'] ?? null,
            commitUrl: $commit['url'] ?? null,
            webUrl: $attributes['url'] ?? null,
            triggeredBy: $payload['user']['name'] ?? null,
            queuedAt: $attributes['created_at'] ?? null,
            startedAt: $attributes['started_at'] ?? null,
            finishedAt: $attributes['finished_at'] ?? null,
            durationSeconds: isset($attributes['duration']) ? (int) $attributes['duration'] : null,
            queueSeconds: isset($attributes['queued_duration']) ? (int) $attributes['queued_duration'] : null,
            raw: $payload,
        );
    }

    public function normalizeJobs(array $payload): array
    {
        $builds = $payload['builds'] ?? [];

        return collect($builds)->values()->map(fn (array $build, int $i) => new NormalizedJob(
            externalId: (string) $build['id'],
            name: (string) $build['name'],
            stageName: (string) ($build['stage'] ?? 'unknown'),
            status: StatusMapper::forJob(StatusMapper::gitlab($build['status'] ?? null, $build['failure_reason'] ?? null)),
            position: $i,
            failureReason: $build['failure_reason'] ?? null,
            allowFailure: (bool) ($build['allow_failure'] ?? false),
            runnerName: $build['runner']['description'] ?? null,
            runnerTags: (array) ($build['runner']['tags'] ?? []),
            startedAt: $build['started_at'] ?? null,
            finishedAt: $build['finished_at'] ?? null,
            durationSeconds: isset($build['duration']) ? (int) round((float) $build['duration']) : null,
            queueSeconds: isset($build['queued_duration']) ? (int) round((float) $build['queued_duration']) : null,
            raw: $build,
        ))->all();
    }

    public function fetchPipeline(Integration $integration, Project $project, string $externalId): NormalizedPipeline
    {
        $pipeline = $this->guard(
            $this->http($integration)->get("/projects/{$project->external_id}/pipelines/{$externalId}"),
            "pipeline {$externalId}",
        )->json();

        return new NormalizedPipeline(
            externalId: (string) $pipeline['id'],
            iid: isset($pipeline['iid']) ? (int) $pipeline['iid'] : null,
            provider: 'gitlab',
            status: StatusMapper::gitlab($pipeline['status'] ?? null),
            source: StatusMapper::gitlabSource($pipeline['source'] ?? 'push'),
            ref: (string) ($pipeline['ref'] ?? 'unknown'),
            commitSha: $pipeline['sha'] ?? null,
            webUrl: $pipeline['web_url'] ?? null,
            triggeredBy: $pipeline['user']['name'] ?? null,
            queuedAt: $pipeline['created_at'] ?? null,
            startedAt: $pipeline['started_at'] ?? null,
            finishedAt: $pipeline['finished_at'] ?? null,
            durationSeconds: isset($pipeline['duration']) ? (int) $pipeline['duration'] : null,
            queueSeconds: isset($pipeline['queued_duration']) ? (int) $pipeline['queued_duration'] : null,
            raw: $pipeline,
        );
    }

    public function fetchJobs(Integration $integration, Project $project, string $externalId): array
    {
        $jobs = $this->guard(
            $this->http($integration)->get("/projects/{$project->external_id}/pipelines/{$externalId}/jobs", [
                'per_page' => 100,
                'include_retried' => false,
            ]),
            "jobs for pipeline {$externalId}",
        )->json();

        return collect($jobs)->values()->map(fn (array $job, int $i) => new NormalizedJob(
            externalId: (string) $job['id'],
            name: (string) $job['name'],
            stageName: (string) ($job['stage'] ?? 'unknown'),
            status: StatusMapper::forJob(StatusMapper::gitlab($job['status'] ?? null, $job['failure_reason'] ?? null)),
            position: $i,
            failureReason: $job['failure_reason'] ?? null,
            allowFailure: (bool) ($job['allow_failure'] ?? false),
            runnerName: $job['runner']['description'] ?? null,
            runnerTags: (array) ($job['tag_list'] ?? []),
            startedAt: $job['started_at'] ?? null,
            finishedAt: $job['finished_at'] ?? null,
            durationSeconds: isset($job['duration']) ? (int) round((float) $job['duration']) : null,
            queueSeconds: isset($job['queued_duration']) ? (int) round((float) $job['queued_duration']) : null,
            webUrl: $job['web_url'] ?? null,
            raw: $job,
        ))->all();
    }

    public function fetchJobLog(Integration $integration, Project $project, PipelineJob $job): string
    {
        // Plain text, one request. This is why GitLab is the right first integration.
        $response = $this->http($integration)
            ->get("/projects/{$project->external_id}/jobs/{$job->external_id}/trace");

        if ($response->status() === 404) {
            throw new LogNotAvailable('The job log has expired or the job never ran.');
        }

        return $this->guard($response, "log for job {$job->external_id}")->body();
    }

    public function fetchCommitChanges(Integration $integration, Project $project, string $sha): array
    {
        $response = $this->http($integration)
            ->get("/projects/{$project->external_id}/repository/commits/{$sha}/diff", ['per_page' => 100]);

        if (! $response->successful()) {
            // A missing diff must not fail the whole ingest — the pipeline and its
            // logs are far more valuable than the changed-file list.
            return [];
        }

        return collect($response->json())->map(fn (array $diff) => [
            'file_path' => $diff['new_path'],
            'old_path' => ($diff['old_path'] ?? null) !== $diff['new_path'] ? ($diff['old_path'] ?? null) : null,
            'change_type' => match (true) {
                (bool) ($diff['new_file'] ?? false) => 'added',
                (bool) ($diff['deleted_file'] ?? false) => 'deleted',
                (bool) ($diff['renamed_file'] ?? false) => 'renamed',
                default => 'modified',
            },
            'additions' => substr_count((string) ($diff['diff'] ?? ''), "\n+"),
            'deletions' => substr_count((string) ($diff['diff'] ?? ''), "\n-"),
            // GitLab already hands us the hunk the counts above were derived
            // from; keeping it costs nothing and is the difference between
            // "check this file" and naming the offending line.
            'patch' => $diff['diff'] ?? null,
        ])->all();
    }

    public function fetchFileContents(
        Integration $integration,
        Project $project,
        string $path,
        ?string $ref = null,
    ): ?string {
        $response = $this->http($integration)->get(
            "/projects/{$project->external_id}/repository/files/".rawurlencode(ltrim($path, '/')).'/raw',
            ['ref' => $ref ?: $project->default_branch ?: 'main'],
        );

        return $response->successful() ? $response->body() : null;
    }

    public function retryJob(Integration $integration, Project $project, PipelineJob $job): array
    {
        return $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/jobs/{$job->external_id}/retry"),
            "retry job {$job->external_id}",
        )->json();
    }

    public function retryPipeline(Integration $integration, Project $project, Pipeline $pipeline): array
    {
        return $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/pipelines/{$pipeline->external_id}/retry"),
            "retry pipeline {$pipeline->external_id}",
        )->json();
    }

    public function cancelPipeline(Integration $integration, Project $project, Pipeline $pipeline): void
    {
        $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/pipelines/{$pipeline->external_id}/cancel"),
            "cancel pipeline {$pipeline->external_id}",
        );
    }

    public function createIssue(Integration $integration, Project $project, string $title, string $body): array
    {
        return $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/issues", [
                'title' => $title,
                'description' => $body,
                'labels' => 'pipemind',
            ]),
            'create issue',
        )->json();
    }

    public function supportsMergeRequests(): bool
    {
        return true;
    }

    public function createBranch(Integration $integration, Project $project, string $branch, string $fromSha): void
    {
        $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/repository/branches", [
                'branch' => $branch,
                'ref' => $fromSha,
            ]),
            "create branch {$branch}",
        );
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
        // GitLab takes every file in one commit, which is what we actually want:
        // a fix spanning two files is one change, and splitting it would leave
        // an intermediate commit that does not build.
        $actions = [];

        foreach ($files as $path => $contents) {
            $actions[] = [
                'action' => 'update',
                'file_path' => ltrim($path, '/'),
                'content' => $contents,
            ];
        }

        return $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/repository/commits", [
                'branch' => $branch,
                'commit_message' => $message,
                'actions' => $actions,
            ]),
            'commit files',
        )->json() ?? [];
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
        return $this->guard(
            $this->http($integration)->post("/projects/{$project->external_id}/merge_requests", [
                'source_branch' => $head,
                'target_branch' => $base,
                'title' => $title,
                'description' => $body,
                'remove_source_branch' => true,
            ]),
            'open merge request',
        )->json() ?? [];
    }

    /** Header names arrive with inconsistent casing depending on the server. */
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
