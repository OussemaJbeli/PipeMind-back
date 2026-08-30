<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Team;

it('scopes queries to the bound team', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    Project::factory()->count(3)->create(['team_id' => $teamA->id]);
    Project::factory()->count(5)->create(['team_id' => $teamB->id]);

    withTeam($teamA, fn () => expect(Project::count())->toBe(3));
    withTeam($teamB, fn () => expect(Project::count())->toBe(5));
});

it('restores the previous team binding after withTeam', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    withTeam($teamA, function () use ($teamA, $teamB) {
        expect(currentTeamId())->toBe($teamA->id);

        // Nested binding: a worker process is long-lived, so leaking a team
        // binding here would poison the next job.
        withTeam($teamB, fn () => expect(currentTeamId())->toBe($teamB->id));

        expect(currentTeamId())->toBe($teamA->id);
    });

    expect(currentTeamId())->toBeNull();
});

it('assigns team_id automatically on create', function () {
    $team = Team::factory()->create();

    withTeam($team, function () use ($team) {
        $project = Project::factory()->create(['team_id' => null]);
        expect($project->team_id)->toBe($team->id);
    });
});

it('never exposes integration credentials', function () {
    $integration = Integration::factory()->create([
        'credentials' => ['token' => 'glpat-SUPERSECRETVALUE123'],
    ]);

    expect($integration->toArray())->not->toHaveKey('credentials')
        ->and($integration->toArray())->not->toHaveKey('webhook_secret')
        ->and(json_encode($integration))->not->toContain('SUPERSECRETVALUE123');

    // ...but the application can still read it.
    expect($integration->token())->toBe('glpat-SUPERSECRETVALUE123');
});

it('never exposes an ai provider api key', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-SECRETKEY99']);

    expect(json_encode($provider))->not->toContain('SECRETKEY99')
        ->and($provider->api_key)->toBe('sk-SECRETKEY99');
});
