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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class GithubProvider implements PipelineProvider
{
    public static function key(): string
    {
        return 'github';
    }

    protected function http(Integration $integration): PendingRequest
    {
        $base = rtrim($integration->base_url ?: 'https://api.github.com', '/');

        return Http::baseUrl($base)
            ->withToken((string) $integration->token())
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(30)
            ->connectTimeout(8)
            ->retry(2, 400, throw: false);
    }

    protected function guard(Response $response, string $context): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $detail = $this->explain($response);

        throw match ($response->status()) {
            401 => new IntegrationUnauthorized("GitHub rejected the token ({$context}). {$detail}"),
            // The header must be PRESENT and zero. `(int) null === 0` is also
            // true, so treating a missing header as exhaustion reported every
            // permission error as a rate limit — and a read-only token is the
            // most common 403 here, which sent people away to wait instead of
            // widening the token's scope.
            403 => $response->header('X-RateLimit-Remaining') !== ''
                && (int) $response->header('X-RateLimit-Remaining') === 0
                ? new ProviderRateLimited("GitHub rate limit exhausted ({$context}).")
                : new IntegrationUnauthorized("GitHub denied access ({$context}). {$detail}"),
            429 => new ProviderRateLimited("GitHub is rate limiting requests ({$context})."),
            default => new IntegrationUnreachable("GitHub rejected the request ({$context}): {$detail}"),
        };
    }

    /**
     * GitHub's 422 body carries the precise reason — "Invalid HTTP(S) URL",
     * "Hook already exists", a missing field. Collapsing that to a status code
     * turns a solvable problem into a mystery.
     */
    protected function explain(Response $response): string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return trim(mb_substr($response->body(), 0, 200)) ?: 'no detail provided.';
        }

        $messages = collect($body['errors'] ?? [])
            ->map(fn ($error) => is_array($error)
                ? ($error['message'] ?? ($error['field'] ?? null).' '.($error['code'] ?? ''))
                : (string) $error)
            ->map(fn ($m) => trim((string) $m))
            ->filter()
            ->all();

        $summary = $body['message'] ?? 'Request failed';

        return $messages
            ? $summary.' — '.implode('; ', $messages)
            : $summary;
    }

    public function verify(Integration $integration): ProviderIdentity
    {
        $user = $this->guard($this->http($integration)->get('/user'), 'user')->json();

        // Classic PATs report scopes in a header; fine-grained tokens do not.
        $scopes = array_filter(array_map(
            'trim',
            explode(',', (string) $this->http($integration)->get('/user')->header('X-OAuth-Scopes')),
        ));

        return new ProviderIdentity(
            username: (string) ($user['login'] ?? 'unknown'),
            name: $user['name'] ?? null,
            avatarUrl: $user['avatar_url'] ?? null,
            scopes: $scopes,
            canReadProjects: true,
            // Retrying a workflow run needs write access to Actions.
            canRetryJobs: $scopes === [] || in_array('repo', $scopes, true),
            canWriteIssues: $scopes === [] || in_array('repo', $scopes, true),
            projectCount: null,
        );
    }

    public function remoteProjects(Integration $integration, ?string $search = null, int $page = 1): array
    {
        $response = $this->guard(
            $this->http($integration)->get('/user/repos', [
                'sort' => 'pushed',
                'per_page' => 50,
                'page' => $page,
            ]),
            'repos',
        );

        return collect($response->json())
            ->when($search, fn ($c) => $c->filter(
                fn (array $r) => str_contains(strtolower($r['full_name']), strtolower((string) $search)),
            ))
            ->map(fn (array $r) => new RemoteProject(
                // GitHub's numeric id is stable across renames; owner/repo is not.
                externalId: (string) $r['id'],
                name: $r['name'],
                path: $r['full_name'],
                description: $r['description'] ?? null,
                webUrl: $r['html_url'] ?? null,
                repositoryUrl: $r['clone_url'] ?? null,
                defaultBranch: $r['default_branch'] ?? 'main',
                lastActivityAt: $r['pushed_at'] ?? null,
            ))->values()->all();
    }

    public function registerWebhook(Integration $integration, Project $project, string $url, string $secret): string
    {
        $response = $this->guard(
            $this->http($integration)->post("/repos/{$project->external_path}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['workflow_run', 'workflow_job'],
                'config' => [
                    'url' => $url,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => app()->isLocal() ? '1' : '0',
                ],
            ]),
            'register webhook',
        );

        return (string) $response->json('id');
    }

    public function removeWebhook(Integration $integration, Project $project, string $hookId): void
    {
        $this->http($integration)->delete("/repos/{$project->external_path}/hooks/{$hookId}");
    }

    public function verifySignature(Integration $integration, string $rawBody, array $headers): bool
    {
        $sent = $this->header($headers, 'x-hub-signature-256');

        if ($sent === null) {
            return false;
        }

        // The HMAC is over the EXACT bytes received. If any middleware has already
        // decoded and re-encoded the JSON, this can never match.
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $integration->webhook_secret);

        return hash_equals($expected, $sent);
    }

    public function eventType(array $payload, array $headers): ?string
    {
        return match ($this->header($headers, 'x-github-event')) {
            'workflow_run' => 'pipeline',
            'workflow_job' => 'job',
            default => null,
        };
    }

    public function externalPipelineId(array $payload): ?string
    {
        return isset($payload['workflow_run']['id'])
            ? (string) $payload['workflow_run']['id']
            : (isset($payload['workflow_job']['run_id']) ? (string) $payload['workflow_job']['run_id'] : null);
    }

    public function externalProjectId(array $payload): ?string
    {
        return isset($payload['repository']['id']) ? (string) $payload['repository']['id'] : null;
    }

    public function normalizePipeline(array $payload): NormalizedPipeline
    {
        $run = $payload['workflow_run'] ?? $payload;
        $head = $run['head_commit'] ?? [];

        $started = $run['run_started_at'] ?? $run['created_at'] ?? null;
        $finished = $run['updated_at'] ?? null;

        return new NormalizedPipeline(
            externalId: (string) $run['id'],
            iid: isset($run['run_number']) ? (int) $run['run_number'] : null,
            provider: 'github',
            status: StatusMapper::github($run['status'] ?? null, $run['conclusion'] ?? null),
            source: StatusMapper::githubSource($run['event'] ?? null),
            ref: (string) ($run['head_branch'] ?? 'unknown'),
            commitSha: $run['head_sha'] ?? null,
            commitMessage: $head['message'] ?? null,
            commitAuthorName: $head['author']['name'] ?? null,
            commitAuthorEmail: $head['author']['email'] ?? null,
            webUrl: $run['html_url'] ?? null,
            triggeredBy: $run['triggering_actor']['login'] ?? ($run['actor']['login'] ?? null),
            queuedAt: $run['created_at'] ?? null,
            startedAt: $started,
            finishedAt: $this->isTerminal($run) ? $finished : null,
            // GitHub reports no duration; derive it from the timestamps.
            durationSeconds: $this->duration($started, $this->isTerminal($run) ? $finished : null),
            raw: $payload,
        );
    }

    protected function isTerminal(array $run): bool
    {
        return ($run['status'] ?? null) === 'completed';
    }

    protected function duration(?string $from, ?string $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return max(0, strtotime($to) - strtotime($from));
    }

    public function normalizeJobs(array $payload): array
    {
        // A workflow_job event carries exactly one job.
        if (isset($payload['workflow_job'])) {
            return [$this->jobFrom($payload['workflow_job'], 0)];
        }

        return collect($payload['jobs'] ?? [])->values()
            ->map(fn (array $job, int $i) => $this->jobFrom($job, $i))->all();
    }

    protected function jobFrom(array $job, int $position): NormalizedJob
    {
        return new NormalizedJob(
            externalId: (string) $job['id'],
            name: (string) $job['name'],
            // GitHub has no stage concept; the workflow name is the closest analogue.
            stageName: (string) ($job['workflow_name'] ?? 'workflow'),
            status: StatusMapper::forJob(StatusMapper::github($job['status'] ?? null, $job['conclusion'] ?? null)),
            position: $position,
            runnerName: $job['runner_name'] ?? null,
            runnerTags: (array) ($job['labels'] ?? []),
            startedAt: $job['started_at'] ?? null,
            finishedAt: $job['completed_at'] ?? null,
            durationSeconds: $this->duration($job['started_at'] ?? null, $job['completed_at'] ?? null),
            webUrl: $job['html_url'] ?? null,
            raw: $job,
        );
    }

    public function fetchPipeline(Integration $integration, Project $project, string $externalId): NormalizedPipeline
    {
        $run = $this->guard(
            $this->http($integration)->get("/repos/{$project->external_path}/actions/runs/{$externalId}"),
            "run {$externalId}",
        )->json();

        return $this->normalizePipeline(['workflow_run' => $run]);
    }

    public function fetchJobs(Integration $integration, Project $project, string $externalId): array
    {
        $response = $this->guard(
            $this->http($integration)->get("/repos/{$project->external_path}/actions/runs/{$externalId}/jobs", [
                'per_page' => 100,
            ]),
            "jobs for run {$externalId}",
        );

        return $this->normalizeJobs(['jobs' => $response->json('jobs', [])]);
    }

    public function fetchJobLog(Integration $integration, Project $project, PipelineJob $job): string
    {
        // Returns 302 to a short-lived signed blob URL.
        $response = $this->http($integration)
            ->withoutRedirecting()
            ->get("/repos/{$project->external_path}/actions/jobs/{$job->external_id}/logs");

        if ($response->status() === 404) {
            throw new LogNotAvailable('The job log has expired (GitHub retains logs for 90 days).');
        }

        $location = $response->header('Location');

        if (! $location) {
            // Some deployments return the body directly rather than redirecting.
            return $this->guard($response, "log for job {$job->external_id}")->body();
        }

        // Plain GET, no Authorization header: the URL is already signed, and
        // re-sending credentials to blob storage gets the request rejected.
        $body = Http::timeout(120)->withOptions(['stream' => false])->get($location)->body();

        return str_starts_with($body, "PK\x03\x04")
            ? $this->flattenLogZip($body)
            : $body;
    }

    /**
     * Run-level log endpoints return a zip of per-step files. Job-level usually
     * returns plain text, but not always — handle both.
     */
    protected function flattenLogZip(string $zipBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ghlog');

        if ($path === false) {
            throw new LogNotAvailable('Could not buffer the log archive.');
        }

        file_put_contents($path, $zipBytes);

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new LogNotAvailable('The log archive could not be read.');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && ! str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }

        // Step files are prefixed with their ordinal ("3_Run tests.txt"), so a
        // natural sort restores execution order. Alphabetical would put 10 before 2.
        natsort($names);

        $output = '';
        foreach ($names as $name) {
            $output .= "\n===== {$name} =====\n".$zip->getFromName($name);
        }

        $zip->close();
        @unlink($path);

        return $output;
    }

    public function fetchCommitChanges(Integration $integration, Project $project, string $sha): array
    {
        $response = $this->http($integration)->get("/repos/{$project->external_path}/commits/{$sha}");

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('files', []))->map(fn (array $file) => [
            'file_path' => $file['filename'],
            'old_path' => $file['previous_filename'] ?? null,
            'change_type' => match ($file['status'] ?? 'modified') {
                'added' => 'added',
                'removed' => 'deleted',
                'renamed' => 'renamed',
                'copied' => 'copied',
                default => 'modified',
            },
            'additions' => (int) ($file['additions'] ?? 0),
            'deletions' => (int) ($file['deletions'] ?? 0),
            // GitHub omits `patch` entirely for binary files and for diffs over
            // its own size limit, so null here is normal rather than an error.
            'patch' => $file['patch'] ?? null,
        ])->all();
    }

    public function fetchFileContents(
        Integration $integration,
        Project $project,
        string $path,
        ?string $ref = null,
    ): ?string {
        // The raw media type returns the file itself rather than base64 inside
        // JSON, which avoids decoding a blob just to read six lines of it.
        $response = $this->http($integration)
            ->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
            ->get("/repos/{$project->external_path}/contents/".ltrim($path, '/'), array_filter([
                'ref' => $ref,
            ]));

        return $response->successful() ? $response->body() : null;
    }

    public function retryJob(Integration $integration, Project $project, PipelineJob $job): array
    {
        return $this->guard(
            $this->http($integration)->post("/repos/{$project->external_path}/actions/jobs/{$job->external_id}/rerun"),
            "rerun job {$job->external_id}",
        )->json() ?? [];
    }

    public function retryPipeline(Integration $integration, Project $project, Pipeline $pipeline): array
    {
        return $this->guard(
            $this->http($integration)
                ->post("/repos/{$project->external_path}/actions/runs/{$pipeline->external_id}/rerun-failed-jobs"),
            "rerun run {$pipeline->external_id}",
        )->json() ?? [];
    }

    public function cancelPipeline(Integration $integration, Project $project, Pipeline $pipeline): void
    {
        $this->guard(
            $this->http($integration)
                ->post("/repos/{$project->external_path}/actions/runs/{$pipeline->external_id}/cancel"),
            "cancel run {$pipeline->external_id}",
        );
    }

    public function createIssue(Integration $integration, Project $project, string $title, string $body): array
    {
        return $this->guard(
            $this->http($integration)->post("/repos/{$project->external_path}/issues", [
                'title' => $title,
                'body' => $body,
                'labels' => ['pipemind'],
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
            $this->http($integration)->post("/repos/{$project->external_path}/git/refs", [
                'ref' => 'refs/heads/'.$branch,
                'sha' => $fromSha,
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
        $last = [];

        // One commit per file: the contents API has no multi-file form, and
        // building a tree by hand would mean four more calls per file. A handful
        // of commits on a throwaway branch is a fair trade for that.
        foreach ($files as $path => $contents) {
            $path = ltrim($path, '/');

            $last = $this->guard(
                $this->http($integration)->put(
                    "/repos/{$project->external_path}/contents/{$path}",
                    array_filter([
                        'message' => $message,
                        'content' => base64_encode($contents),
                        'branch' => $branch,
                        // Updating an existing file requires its current blob
                        // sha; omitting it creates a file and fails if one is
                        // already there.
                        'sha' => $this->blobSha($integration, $project, $path, $branch),
                    ], fn ($value) => $value !== null),
                ),
                "commit {$path}",
            )->json() ?? [];
        }

        return $last;
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
            $this->http($integration)->post("/repos/{$project->external_path}/pulls", [
                'title' => $title,
                'head' => $head,
                'base' => $base,
                'body' => $body,
            ]),
            'open pull request',
        )->json() ?? [];
    }

    /** The blob sha of a file on a branch, or null when it does not exist yet. */
    private function blobSha(Integration $integration, Project $project, string $path, string $branch): ?string
    {
        $response = $this->http($integration)
            ->get("/repos/{$project->external_path}/contents/{$path}", ['ref' => $branch]);

        return $response->successful() ? $response->json('sha') : null;
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
