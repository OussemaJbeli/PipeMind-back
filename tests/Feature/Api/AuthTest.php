<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;

it('logs in and returns the user with permissions', function () {
    $user = User::factory()->withTeam()->create(['email' => 'dev@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'dev@example.com',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'uuid', 'name', 'email', 'initials', 'theme',
            'current_team' => ['uuid', 'name', 'slug', 'role', 'plan', 'privacy_mode'],
            'permissions',
        ]])
        ->assertJsonPath('data.current_team.role', 'owner');
});

it('gives the same message for a wrong password and an unknown email', function () {
    User::factory()->create(['email' => 'real@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'real@example.com', 'password' => 'nope',
    ])->assertStatus(422)->json('errors.email.0');

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.com', 'password' => 'nope',
    ])->assertStatus(422)->json('errors.email.0');

    // Anything else is an account-enumeration oracle.
    expect($wrongPassword)->toBe($unknownEmail);
});

it('registers a user with a team and the default policies', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Oussema',
        'email' => 'new@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'team_name' => 'Evox AI',
    ])->assertCreated()->assertJsonPath('data.current_team.name', 'Evox AI');

    $team = Team::where('name', 'Evox AI')->firstOrFail();

    // A missing policy means FORBIDDEN, so a workspace without these could
    // never remediate anything.
    expect($team->remediationPolicies()->count())
        ->toBe(count(config('pipemind.default_policies')));
});

it('rejects an unauthenticated request with a typed error code', function () {
    $this->getJson('/api/v1/workspace/summary')
        ->assertStatus(401)
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');
});

it('returns a permission list matching the role', function () {
    $viewer = User::factory()->withTeam('viewer')->create();

    $permissions = $this->actingAs($viewer)->getJson('/api/v1/auth/me')
        ->assertOk()->json('data.permissions');

    expect($permissions)->toContain('projects.view')
        ->and($permissions)->not->toContain('remediation.approve')
        ->and($permissions)->not->toContain('policies.edit');
});
