<?php

declare(strict_types=1);

use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function githubUser(): array
{
    return ['login' => 'OussemaJbeli', 'name' => 'Oussema', 'avatar_url' => 'https://example.test/a.png'];
}

function fakeGithubOk(): void
{
    Http::fake([
        'api.github.com/user' => Http::response(githubUser(), 200, ['X-OAuth-Scopes' => 'repo, workflow']),
        'api.github.com/user/repos*' => Http::response([
            [
                'id' => 900001, 'name' => 'PipeMind-back', 'full_name' => 'OussemaJbeli/PipeMind-back',
                'description' => 'Backend', 'html_url' => 'https://github.com/OussemaJbeli/PipeMind-back',
                'clone_url' => 'https://github.com/OussemaJbeli/PipeMind-back.git',
                'default_branch' => 'main', 'pushed_at' => '2026-08-30T10:00:00Z',
            ],
            [
                'id' => 900002, 'name' => 'PipeMind-front', 'full_name' => 'OussemaJbeli/PipeMind-front',
                'description' => null, 'html_url' => 'https://github.com/OussemaJbeli/PipeMind-front',
                'clone_url' => 'https://github.com/OussemaJbeli/PipeMind-front.git',
                'default_branch' => 'main', 'pushed_at' => '2026-08-29T10:00:00Z',
            ],
        ]),
        'api.github.com/repos/*/hooks' => Http::response(['id' => 55501], 201),
        '*' => Http::response([], 200),
    ]);
}

it('reports capabilities when testing unsaved credentials', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/integrations/test', [
        'provider' => 'github',
        'token' => 'ghp_'.str_repeat('a', 36),
    ])
        ->assertOk()
        ->assertJsonPath('data.username', 'OussemaJbeli')
        // What the token can DO, not what it claims — this is what stops a
        // read-only token becoming a confusing failure three days later.
        ->assertJsonPath('data.capabilities.read_projects', true)
        ->assertJsonPath('data.capabilities.retry_jobs', true);

    // Testing must not persist anything.
    expect(Integration::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses to save credentials that do not work', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/integrations', [
        'provider' => 'github',
        'name' => 'GitHub',
        'token' => 'ghp_wrong',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'INTEGRATION_UNAUTHORIZED');

    expect(Integration::withoutGlobalScopes()->count())->toBe(0);
});

it('creates an integration with a generated webhook secret', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/integrations', [
        'provider' => 'github',
        'name' => 'GitHub Actions',
        'token' => 'ghp_'.str_repeat('a', 36),
    ])->assertCreated();

    $integration = Integration::withoutGlobalScopes()->firstOrFail();

    expect($integration->webhook_secret)->toHaveLength(48)
        ->and($integration->status)->toBe('active')
        ->and($integration->token())->toBe('ghp_'.str_repeat('a', 36));

    // Secrets must never reach the client.
    expect($response->content())->not->toContain('ghp_')
        ->and($response->content())->not->toContain($integration->webhook_secret);
});

it('lists remote repositories and marks the ones already imported', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    Project::factory()->for($integration)->create([
        'team_id' => $user->current_team_id,
        'external_id' => '900001',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/integrations/{$integration->uuid}/remote-projects")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.already_imported', true)
        ->assertJsonPath('data.1.already_imported', false);
});

it('imports repositories and registers their webhooks', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();
    // Providers reject loopback hook URLs, so a reachable one is a precondition.
    $user->currentTeam->setWebhookBaseUrl('https://tunnel.example.com');
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    $this->actingAs($user)
        ->postJson("/api/v1/integrations/{$integration->uuid}/import", [
            'external_ids' => ['900001', '900002'],
        ])
        ->assertCreated()
        ->assertJsonPath('meta.imported', 2)
        ->assertJsonPath('meta.webhooks_failed', 0)
        ->assertJsonPath('data.0.webhook', true);

    expect(Project::withoutGlobalScopes()->count())->toBe(2);

    $project = Project::withoutGlobalScopes()->where('external_id', '900001')->first();
    expect($project->settings['webhook_id'])->toBe('55501')
        ->and($project->external_path)->toBe('OussemaJbeli/PipeMind-back');
});

it('reports per-repository status when a webhook fails', function () {
    Http::fake([
        'api.github.com/user' => Http::response(githubUser(), 200),
        'api.github.com/user/repos*' => Http::response([
            ['id' => 900001, 'name' => 'ok-repo', 'full_name' => 'me/ok-repo', 'default_branch' => 'main'],
            ['id' => 900002, 'name' => 'no-perms', 'full_name' => 'me/no-perms', 'default_branch' => 'main'],
        ]),
        'api.github.com/repos/me/ok-repo/hooks' => Http::response(['id' => 1], 201),
        'api.github.com/repos/me/no-perms/hooks' => Http::response(['message' => 'Not Found'], 404),
        '*' => Http::response([], 200),
    ]);

    $user = User::factory()->withTeam()->create();
    $user->currentTeam->setWebhookBaseUrl('https://tunnel.example.com');
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    // 207: partial success. A project imported without a working hook looks
    // fine and does nothing, so it must be reported, not swallowed.
    $this->actingAs($user)
        ->postJson("/api/v1/integrations/{$integration->uuid}/import", [
            'external_ids' => ['900001', '900002'],
        ])
        ->assertStatus(207)
        ->assertJsonPath('meta.webhooks_failed', 1)
        ->assertJsonPath('data.0.webhook', true)
        ->assertJsonPath('data.1.webhook', false);
});

it('re-registers every webhook against a new tunnel url', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    Project::factory()->count(2)->for($integration)->create(['team_id' => $user->current_team_id]);

    $this->actingAs($user)
        ->postJson("/api/v1/integrations/{$integration->uuid}/re-register", [
            'webhook_base_url' => 'https://fresh-tunnel.trycloudflare.com',
        ])
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.failed', 0);

    // The new hostname must persist so later registrations use it too.
    expect($user->currentTeam->fresh()->webhookBaseUrl())
        ->toBe('https://fresh-tunnel.trycloudflare.com');

    expect($integration->fresh()->webhookUrl())
        ->toStartWith('https://fresh-tunnel.trycloudflare.com/api/webhooks/github/');
});

it('warns when the webhook url is not publicly reachable', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->getJson('/api/v1/integrations/webhook-settings')
        ->assertOk()
        // APP_URL is localhost in dev: providers cannot reach it, and saying so
        // beats letting registration fail mysteriously.
        ->assertJsonPath('data.reachable', false);

    $user->currentTeam->setWebhookBaseUrl('https://abc.trycloudflare.com');

    $this->actingAs($user)->getJson('/api/v1/integrations/webhook-settings')
        ->assertOk()
        ->assertJsonPath('data.reachable', true);
});

it('denies integration management to a member', function () {
    $member = User::factory()->withTeam('member')->create();

    $this->actingAs($member)->getJson('/api/v1/integrations')->assertForbidden();
    $this->actingAs($member)->postJson('/api/v1/integrations/test', [
        'provider' => 'github', 'token' => 'x',
    ])->assertForbidden();
});

it('refuses to register a webhook at an unreachable url', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    // APP_URL is localhost in dev; GitHub rejects loopback hook URLs with an
    // opaque 422, so fail here with something the user can act on.
    $response = $this->actingAs($user)
        ->postJson("/api/v1/integrations/{$integration->uuid}/import", [
            'external_ids' => ['900001'],
        ])
        ->assertStatus(207)
        ->assertJsonPath('data.0.webhook', false);

    expect($response->json('data.0.error'))
        ->toContain('cannot reach')
        ->toContain('pipemind:tunnel');
});

it('explains a github validation failure instead of just the status code', function () {
    Http::fake([
        'api.github.com/user' => Http::response(githubUser(), 200),
        'api.github.com/user/repos*' => Http::response([
            ['id' => 900001, 'name' => 'repo', 'full_name' => 'me/repo', 'default_branch' => 'main'],
        ]),
        'api.github.com/repos/*/hooks' => Http::response([
            'message' => 'Validation Failed',
            'errors' => [['resource' => 'Hook', 'code' => 'custom', 'message' => 'Hook already exists on this repository']],
        ], 422),
        '*' => Http::response([], 200),
    ]);

    $user = User::factory()->withTeam()->create();
    $user->currentTeam->setWebhookBaseUrl('https://tunnel.example.com');
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/integrations/{$integration->uuid}/import", ['external_ids' => ['900001']])
        ->assertStatus(207);

    // The provider's own explanation is what makes this solvable.
    expect($response->json('data.0.error'))
        ->toContain('Validation Failed')
        ->toContain('Hook already exists');
});

it('refuses a second integration for the same provider and account', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();

    $payload = [
        'provider' => 'github',
        'name' => 'GitHub Actions',
        'token' => 'ghp_'.str_repeat('a', 36),
    ];

    $this->actingAs($user)->postJson('/api/v1/integrations', $payload)->assertCreated();

    // A duplicate means the same repo imported twice, two webhooks, and every
    // pipeline arriving twice. Nothing at the database level catches it.
    $this->actingAs($user)->postJson('/api/v1/integrations', $payload)
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'INTEGRATION_ALREADY_CONNECTED');

    expect(Integration::withoutGlobalScopes()->where('provider', 'github')->count())->toBe(1);
});

it('deactivates projects when their integration is removed', function () {
    fakeGithubOk();
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->github()->create(['team_id' => $user->current_team_id]);
    $projects = Project::factory()->count(2)->for($integration)->create([
        'team_id' => $user->current_team_id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/integrations/{$integration->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.projects_deactivated', 2);

    // Deactivated, not deleted: the pipelines and failures hanging off these
    // projects are the knowledge base and stay valid. But leaving them active
    // would show them on the workspace grid forever, silently receiving nothing.
    foreach ($projects as $project) {
        expect($project->fresh()->is_active)->toBeFalse();
    }

    expect(Project::withoutGlobalScopes()->count())->toBe(2);
});
