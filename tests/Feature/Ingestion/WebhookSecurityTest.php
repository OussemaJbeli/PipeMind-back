<?php

declare(strict_types=1);

use App\Models\Integration;
use App\Models\PipelineEvent;
use App\Models\Project;
use Illuminate\Support\Str;

function gitlabPayload(): array
{
    return json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-failed.json')), true);
}

it('rejects an unknown integration and a bad signature identically', function () {
    $integration = Integration::factory()->gitlab()->create();

    $unknown = $this->postJson('/api/webhooks/gitlab/'.Str::uuid(), gitlabPayload());

    $badSignature = $this->postJson(
        "/api/webhooks/gitlab/{$integration->uuid}",
        gitlabPayload(),
        ['X-Gitlab-Token' => 'not-the-secret'],
    );

    // Identical responses: never confirm to an unauthenticated caller that a
    // given integration UUID exists.
    expect($unknown->status())->toBe(401)
        ->and($badSignature->status())->toBe(401)
        ->and($unknown->json())->toEqual($badSignature->json());

    expect(PipelineEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('never stores auth headers alongside the payload', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create(['external_id' => '42']);

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", gitlabPayload(), [
        'X-Gitlab-Token' => $integration->webhook_secret,
        'Authorization' => 'Bearer super-secret-token',
        'Cookie' => 'session=abc123',
    ])->assertStatus(202);

    $headers = PipelineEvent::withoutGlobalScopes()->first()->headers;
    $keys = array_map('strtolower', array_keys($headers));

    expect($keys)->not->toContain('x-gitlab-token')
        ->and($keys)->not->toContain('authorization')
        ->and($keys)->not->toContain('cookie')
        ->and(json_encode($headers))->not->toContain('super-secret-token');
});

it('treats a redelivered webhook as a duplicate', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create(['external_id' => '42']);

    $headers = [
        'X-Gitlab-Token' => $integration->webhook_secret,
        'X-Gitlab-Event-UUID' => 'delivery-abc-123',
    ];

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", gitlabPayload(), $headers)
        ->assertStatus(202)->assertJsonPath('status', 'accepted');

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", gitlabPayload(), $headers)
        ->assertStatus(202)->assertJsonPath('status', 'duplicate');

    // The unique index does the deduplication, not application logic.
    expect(PipelineEvent::withoutGlobalScopes()->count())->toBe(1);
});

it('ignores events it does not care about', function () {
    $integration = Integration::factory()->gitlab()->create();

    $this->postJson(
        "/api/webhooks/gitlab/{$integration->uuid}",
        ['object_kind' => 'push', 'project' => ['id' => 42]],
        ['X-Gitlab-Token' => $integration->webhook_secret],
    )->assertStatus(202)->assertJsonPath('status', 'ignored');

    expect(PipelineEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('verifies a github HMAC over the raw body', function () {
    $integration = Integration::factory()->github()->create();
    $payload = ['workflow_run' => ['id' => 1], 'repository' => ['id' => 7]];
    $body = json_encode($payload);

    $this->call('POST', "/api/webhooks/github/{$integration->uuid}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'workflow_run',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $integration->webhook_secret),
    ], $body)->assertStatus(202);

    $this->call('POST', "/api/webhooks/github/{$integration->uuid}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'workflow_run',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'tampered', $integration->webhook_secret),
    ], $body)->assertStatus(401);
});
