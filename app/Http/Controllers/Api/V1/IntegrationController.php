<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationResource;
use App\Integrations\DTO\RemoteProject;
use App\Integrations\ProviderRegistry;
use App\Models\Integration;
use App\Services\Integrations\ConnectionTester;
use App\Services\Integrations\ProjectImporter;
use App\Services\Integrations\WebhookRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public function index(): array
    {
        $integrations = Integration::with('team')->withCount('projects')->latest()->get();

        return ['data' => IntegrationResource::collection($integrations)->resolve()];
    }

    public function show(Integration $integration): array
    {
        $integration->load('team')->loadCount('projects');

        return ['data' => (new IntegrationResource($integration))->resolve()];
    }

    /**
     * Test credentials that have NOT been saved yet.
     *
     * The wizard proves the connection works at step 3, before it is willing to
     * persist a token — so this cannot operate on a stored integration.
     */
    public function testUnsaved(Request $request, ConnectionTester $tester): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:gitlab,github,jenkins,generic'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'token' => ['required_unless:provider,generic', 'string', 'max:500'],
            'username' => ['nullable', 'string', 'max:190'],
        ]);

        $result = $tester->test(
            currentTeam(),
            $data['provider'],
            $data['base_url'] ?? null,
            array_filter([
                'token' => $data['token'] ?? null,
                'username' => $data['username'] ?? null,
            ]),
        );

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'error_code' => $result['error_code'],
                'retryable' => $result['error_code'] === 'INTEGRATION_UNREACHABLE',
            ], 422);
        }

        $identity = $result['identity'];

        return response()->json(['data' => [
            'username' => $identity->username,
            'name' => $identity->name,
            'avatar_url' => $identity->avatarUrl,
            'scopes' => $identity->scopes,
            'project_count' => $identity->projectCount,
            // What the token can actually DO, not what it claims.
            'capabilities' => [
                'read_projects' => $identity->canReadProjects,
                'retry_jobs' => $identity->canRetryJobs,
                'create_issues' => $identity->canWriteIssues,
            ],
        ]]);
    }

    public function store(Request $request, ConnectionTester $tester): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:gitlab,github,jenkins,generic'],
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'token' => ['required_unless:provider,generic', 'string', 'max:500'],
            'username' => ['nullable', 'string', 'max:190'],
        ]);

        $credentials = array_filter([
            'token' => $data['token'] ?? null,
            'username' => $data['username'] ?? null,
        ]);

        // Never persist credentials that have not been proven to work.
        $result = $tester->test(currentTeam(), $data['provider'], $data['base_url'] ?? null, $credentials);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'error_code' => $result['error_code'],
                'retryable' => false,
            ], 422);
        }

        // A second integration for the same provider AND account means duplicate
        // projects, duplicate webhooks and duplicate pipelines for the same repo.
        // The unique index is per-integration, so nothing at the database level
        // catches it.
        $duplicate = Integration::where('provider', $data['provider'])
            ->where('base_url', $data['base_url'] ?? $this->defaultBaseUrl($data['provider']))
            ->get()
            ->first(function (Integration $existing) use ($result) {
                $account = $existing->credentials['account'] ?? null;

                // An integration created before accounts were recorded has no
                // account to compare. Treat it as a match: the overwhelmingly
                // common case is the same account, and a false block is a
                // one-click fix while a false allow silently triples every
                // webhook delivery.
                return $account === null || $account === $result['identity']->username;
            });

        if ($duplicate) {
            return response()->json([
                'message' => sprintf(
                    '%s is already connected ("%s"). Adding a second connection would register a '
                    .'duplicate webhook on every repository, so each pipeline would arrive twice. '
                    .'Update the existing connection, or remove it first if this is a different account.',
                    ucfirst($data['provider']),
                    $duplicate->name,
                ),
                'error_code' => 'INTEGRATION_ALREADY_CONNECTED',
                'retryable' => false,
            ], 409);
        }

        // Remembered so the duplicate check above has something to compare.
        $credentials['account'] = $result['identity']->username;

        $integration = Integration::create([
            'provider' => $data['provider'],
            'name' => $data['name'],
            'base_url' => $data['base_url'] ?? $this->defaultBaseUrl($data['provider']),
            'credentials' => $credentials,
            'webhook_secret' => Str::random(48),
            'scopes' => $result['identity']->scopes,
            'status' => 'active',
            'last_verified_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        activity_log(null, 'integration.connected', 'success', $integration->name,
            "Connected to {$integration->provider} as {$result['identity']->username}");

        return response()->json(
            ['data' => (new IntegrationResource($integration))->resolve()],
            201,
        );
    }

    public function update(Request $request, Integration $integration, ConnectionTester $tester): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'token' => ['sometimes', 'string', 'max:500'],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        if (isset($data['token'])) {
            $data['credentials'] = [...($integration->credentials ?? []), 'token' => $data['token']];
            unset($data['token']);
        }

        $integration->update($data);

        // Re-verify after any credential or endpoint change.
        if (isset($data['credentials']) || array_key_exists('base_url', $data)) {
            $tester->retest($integration->fresh());
        }

        return ['data' => (new IntegrationResource($integration->fresh()))->resolve()];
    }

    public function destroy(Integration $integration, WebhookRegistrar $registrar): JsonResponse
    {
        $integration->loadMissing('team');

        $projects = $integration->projects()->get();

        foreach ($projects as $project) {
            // Clean up on the provider side before we forget the hook ids.
            $registrar->unregister($integration, $project);

            // Deactivate rather than delete: the pipelines, failures and analyses
            // hanging off this project are the knowledge base, and they stay
            // valid even though nothing new will arrive. Leaving them active
            // would show them on the workspace grid forever, silently receiving
            // nothing.
            $project->update(['is_active' => false]);
        }

        $integration->delete();

        return response()->json([
            'message' => 'Integration removed.',
            'meta' => ['projects_deactivated' => $projects->count()],
        ]);
    }

    public function test(Integration $integration, ConnectionTester $tester): JsonResponse
    {
        $result = $tester->retest($integration);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'error_code' => $result['error_code'],
                'retryable' => true,
            ], 422);
        }

        return response()->json(['data' => [
            'username' => $result['identity']->username,
            'capabilities' => [
                'read_projects' => $result['identity']->canReadProjects,
                'retry_jobs' => $result['identity']->canRetryJobs,
                'create_issues' => $result['identity']->canWriteIssues,
            ],
        ]]);
    }

    /** Repositories the token can see, for the import step. */
    public function remoteProjects(Request $request, Integration $integration, ProviderRegistry $registry): array
    {
        $remote = $registry->for($integration)->remoteProjects(
            $integration,
            $request->string('search')->toString() ?: null,
            $request->integer('page', 1),
        );

        $imported = $integration->projects()->pluck('external_id')->all();

        return ['data' => collect($remote)->map(fn (RemoteProject $p) => [
            'external_id' => $p->externalId,
            'name' => $p->name,
            'path' => $p->path,
            'description' => $p->description,
            'web_url' => $p->webUrl,
            'default_branch' => $p->defaultBranch,
            'last_activity_at' => $p->lastActivityAt,
            // Lets the UI show "already monitored" instead of offering a no-op.
            'already_imported' => in_array($p->externalId, $imported, true),
        ])->all()];
    }

    public function import(Request $request, Integration $integration, ProjectImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'external_ids' => ['required', 'array', 'min:1', 'max:50'],
            'external_ids.*' => ['string'],
        ]);

        $results = $importer->import($integration, $data['external_ids']);

        $failed = collect($results)->where('webhook', false)->count();

        return response()->json([
            'data' => $results,
            'meta' => [
                'imported' => collect($results)->where('imported', true)->count(),
                'webhooks_failed' => $failed,
            ],
        ], $failed > 0 ? 207 : 201);
    }

    /**
     * Re-point every webhook at the current base URL.
     *
     * A free tunnel gets a new hostname on every restart, so this is a routine
     * operation rather than an edge case.
     */
    public function reRegister(Request $request, Integration $integration, WebhookRegistrar $registrar): JsonResponse
    {
        $data = $request->validate([
            'webhook_base_url' => ['sometimes', 'url', 'max:255'],
        ]);

        if ($url = $data['webhook_base_url'] ?? null) {
            currentTeam()->setWebhookBaseUrl($url);
            $integration->refresh();
        }

        $results = $registrar->reRegisterAll($integration);
        $failed = collect($results)->where('ok', false)->count();

        return response()->json([
            'data' => $results,
            'meta' => [
                'webhook_url' => $integration->fresh()->webhookUrl(),
                'total' => count($results),
                'failed' => $failed,
            ],
        ], $failed > 0 ? 207 : 200);
    }

    public function webhookSettings(): array
    {
        $team = currentTeam();

        return ['data' => [
            'webhook_base_url' => $team->webhookBaseUrl(),
            // A localhost URL means providers cannot reach us at all — worth
            // saying out loud rather than letting registration fail mysteriously.
            'reachable' => $team->webhookUrlIsReachable(),
        ]];
    }

    private function defaultBaseUrl(string $provider): ?string
    {
        return match ($provider) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com',
            default => null,
        };
    }
}
