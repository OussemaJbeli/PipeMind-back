<?php

declare(strict_types=1);

namespace App\Integrations\Providers;

use App\Exceptions\Integrations\IntegrationUnauthorized;
use App\Exceptions\Integrations\IntegrationUnreachable;
use App\Exceptions\Integrations\LogNotAvailable;
use App\Integrations\DTO\NormalizedJob;
use App\Integrations\DTO\NormalizedPipeline;
use App\Integrations\DTO\ProviderIdentity;
use App\Integrations\Support\StatusMapper;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Jenkins has no first-class pipeline webhook, so we meet it where it is: a
 * snippet the user pastes into their Jenkinsfile posts the same envelope the
 * generic provider accepts. Logs and actions do go through the Jenkins API.
 */
class JenkinsProvider extends GenericProvider
{
    public static function key(): string
    {
        return 'jenkins';
    }

    protected function http(Integration $integration): PendingRequest
    {
        $credentials = $integration->credentials ?? [];

        return Http::baseUrl(rtrim((string) $integration->base_url, '/'))
            ->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['token'] ?? ''))
            ->timeout(30)
            ->connectTimeout(8);
    }

    public function verify(Integration $integration): ProviderIdentity
    {
        $response = $this->http($integration)->get('/api/json');

        if ($response->status() === 401 || $response->status() === 403) {
            throw new IntegrationUnauthorized('Jenkins rejected the stored credentials.');
        }

        if (! $response->successful()) {
            throw new IntegrationUnreachable('Could not reach the Jenkins instance.');
        }

        return new ProviderIdentity(
            username: (string) (($integration->credentials['username'] ?? null) ?? 'jenkins'),
            name: $integration->name,
            canReadProjects: true,
            canRetryJobs: true,
            canWriteIssues: false,
        );
    }

    public function normalizePipeline(array $payload): NormalizedPipeline
    {
        $building = (bool) ($payload['building'] ?? false);

        return new NormalizedPipeline(
            externalId: (string) ($payload['build'] ?? $payload['external_id'] ?? ''),
            iid: isset($payload['build']) ? (int) $payload['build'] : null,
            provider: 'jenkins',
            status: StatusMapper::jenkins($payload['result'] ?? null, $building),
            source: (string) ($payload['source'] ?? 'push'),
            ref: (string) ($payload['branch'] ?? 'unknown'),
            commitSha: $payload['commit'] ?? null,
            webUrl: $payload['url'] ?? null,
            startedAt: isset($payload['started_at'])
                ? date('c', (int) ($payload['started_at'] / 1000))
                : null,
            durationSeconds: isset($payload['duration'])
                ? (int) round(((int) $payload['duration']) / 1000)
                : null,
            raw: $payload,
        );
    }

    public function normalizeJobs(array $payload): array
    {
        // A Jenkins build is reported as a single unit unless stages are sent.
        if (! isset($payload['stages'])) {
            return [new NormalizedJob(
                externalId: (string) ($payload['build'] ?? '0'),
                name: (string) ($payload['job'] ?? 'build'),
                stageName: 'build',
                status: StatusMapper::jenkins($payload['result'] ?? null, (bool) ($payload['building'] ?? false)),
                raw: $payload,
            )];
        }

        return collect($payload['stages'])->values()->map(
            fn (array $stage, int $i) => new NormalizedJob(
                externalId: (string) ($stage['id'] ?? $i),
                name: (string) ($stage['name'] ?? "stage-{$i}"),
                stageName: (string) ($stage['name'] ?? "stage-{$i}"),
                status: StatusMapper::jenkins($stage['status'] ?? null),
                position: $i,
                durationSeconds: isset($stage['durationMillis'])
                    ? (int) round(((int) $stage['durationMillis']) / 1000)
                    : null,
                raw: $stage,
            ),
        )->all();
    }

    public function fetchJobLog(Integration $integration, Project $project, PipelineJob $job): string
    {
        $buildUrl = $job->raw_payload['url'] ?? null;

        if (! $buildUrl) {
            throw new LogNotAvailable('No Jenkins build URL was recorded for this job.');
        }

        $response = Http::withBasicAuth(
            (string) ($integration->credentials['username'] ?? ''),
            (string) ($integration->credentials['token'] ?? ''),
        )->timeout(120)->get(rtrim($buildUrl, '/').'/consoleText');

        if ($response->status() === 404) {
            throw new LogNotAvailable('The Jenkins console log is no longer available.');
        }

        if (! $response->successful()) {
            throw new IntegrationUnreachable('Could not read the Jenkins console log.');
        }

        return $response->body();
    }

    public function retryJob(Integration $integration, Project $project, PipelineJob $job): array
    {
        $buildUrl = $job->raw_payload['url'] ?? null;

        if (! $buildUrl) {
            throw new IntegrationUnreachable('No Jenkins build URL was recorded for this job.');
        }

        $this->http($integration)->post(rtrim($buildUrl, '/').'/rebuild');

        return ['status' => 'requested'];
    }

    public function retryPipeline(Integration $integration, Project $project, Pipeline $pipeline): array
    {
        $url = $pipeline->web_url;

        if (! $url) {
            throw new IntegrationUnreachable('No Jenkins build URL was recorded for this pipeline.');
        }

        $this->http($integration)->post(rtrim($url, '/').'/rebuild');

        return ['status' => 'requested'];
    }
}
