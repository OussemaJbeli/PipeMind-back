<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AiProvider;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

it('returns the project, its integration health and its stats in one request', function () {
    $user = User::factory()->withTeam()->create();
    $integration = Integration::factory()->create([
        'team_id' => $user->current_team_id,
        'status' => 'active',
    ]);
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        'integration_id' => $integration->id,
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->slug}/settings")
        ->assertOk()->json('data');

    // One request because the settings screen shows all three at once; three
    // would mean three loading states for one page.
    expect($data)->toHaveKeys(['uuid', 'name', 'auto_analyze', 'integration', 'stats'])
        ->and($data['integration']['status'])->toBe('active')
        ->and($data['stats'])->toHaveKeys(['pipelines', 'failures', 'last_pipeline_at']);
});

it('reports a null integration rather than omitting it', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        'integration_id' => null,
    ]);

    $data = $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->slug}/settings")
        ->assertOk()->json('data');

    // A project with no integration receives nothing and looks healthy. The key
    // must be present and null so the UI can say so out loud.
    expect($data)->toHaveKey('integration')
        ->and($data['integration'])->toBeNull();
});

it('updates settings and records the change', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        'auto_analyze' => true,
    ]);

    $data = $this->actingAs($user)->putJson("/api/v1/projects/{$project->slug}", [
        'name' => 'Renamed project',
        'auto_analyze' => false,
        'analyze_on_branches' => ['main', 'develop'],
    ])->assertOk()->json('data');

    expect($data['name'])->toBe('Renamed project')
        ->and($data['auto_analyze'])->toBeFalse()
        ->and($data['analyze_on_branches'])->toBe(['main', 'develop']);

    $this->assertDatabaseHas('activity_logs', [
        'team_id' => $user->current_team_id,
        'action' => 'project.updated',
    ]);
});

it('assigns an ai provider belonging to the team', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $provider = AiProvider::factory()->create(['team_id' => $user->current_team_id]);

    $data = $this->actingAs($user)->putJson("/api/v1/projects/{$project->slug}", [
        'ai_provider_uuid' => $provider->uuid,
    ])->assertOk()->json('data');

    expect($data['ai_provider']['uuid'])->toBe($provider->uuid)
        ->and($project->fresh()->ai_provider_id)->toBe($provider->id);
});

it('rejects an ai provider from another team instead of silently clearing it', function () {
    $user = User::factory()->withTeam()->create();
    $mine = AiProvider::factory()->create(['team_id' => $user->current_team_id]);
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        'ai_provider_id' => $mine->id,
    ]);
    $foreign = AiProvider::factory()->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    // Resolving through the team is right, but a uuid that resolves to nothing
    // must fail loudly: silently writing null detaches the provider the project
    // was already using and reports success for it.
    $this->actingAs($user)->putJson("/api/v1/projects/{$project->slug}", [
        'ai_provider_uuid' => $foreign->uuid,
    ])->assertStatus(422);

    expect($project->fresh()->ai_provider_id)->toBe($mine->id);
});

it('clears the ai provider when told to explicitly', function () {
    $user = User::factory()->withTeam()->create();
    $provider = AiProvider::factory()->create(['team_id' => $user->current_team_id]);
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        'ai_provider_id' => $provider->id,
    ]);

    $data = $this->actingAs($user)->putJson("/api/v1/projects/{$project->slug}", [
        'ai_provider_uuid' => null,
    ])->assertOk()->json('data');

    // Falling back to the team default is a legitimate choice, distinct from
    // naming a provider that does not exist.
    expect($data['ai_provider'])->toBeNull()
        ->and($project->fresh()->ai_provider_id)->toBeNull();
});

it('never exposes another team\'s project settings', function () {
    $user = User::factory()->withTeam()->create();
    $foreign = Project::factory()->create(['team_id' => Team::factory()->create()->id]);

    $this->actingAs($user)->getJson("/api/v1/projects/{$foreign->slug}/settings")->assertNotFound();
    $this->actingAs($user)->putJson("/api/v1/projects/{$foreign->slug}", [
        'name' => 'Hijacked',
    ])->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('Hijacked');
});

it('does not let a viewer change project settings', function () {
    $user = User::factory()->withTeam(TeamRole::VIEWER)->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    $this->actingAs($user)->putJson("/api/v1/projects/{$project->slug}", [
        'name' => 'Nope',
    ])->assertForbidden();

    $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/settings")->assertOk();
});
