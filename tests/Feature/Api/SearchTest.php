<?php

declare(strict_types=1);

use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;

it('returns nothing for a single character', function () {
    $user = User::factory()->withTeam()->create();
    Project::factory()->create(['team_id' => $user->current_team_id, 'name' => 'biker-api']);

    // One character matches almost everything, at full table-scan cost, and is
    // never what the user meant.
    expect($this->actingAs($user)->getJson('/api/v1/search?q=b')->assertOk()->json('data.groups'))
        ->toBeEmpty();
});

it('finds projects by name and slug', function () {
    $user = User::factory()->withTeam()->create();
    Project::factory()->create(['team_id' => $user->current_team_id, 'name' => 'biker-api']);
    Project::factory()->create(['team_id' => $user->current_team_id, 'name' => 'unrelated']);

    $groups = $this->actingAs($user)->getJson('/api/v1/search?q=biker')->json('data.groups');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['type'])->toBe('project')
        ->and($groups[0]['items'])->toHaveCount(1)
        ->and($groups[0]['items'][0]['route']['name'])->toBe('project.overview');
});

it('matches a pipeline by iid exactly, with or without the hash', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    Pipeline::factory()->for($project)->create(['iid' => 821]);
    Pipeline::factory()->for($project)->create(['iid' => 8214]);

    // urlencode, because a raw '#' is a fragment delimiter and would truncate
    // the query string to nothing. Axios encodes its params, so the real client
    // never hits this — but a hand-built test URL does.
    foreach (['821', '#821'] as $term) {
        $groups = $this->actingAs($user)
            ->getJson('/api/v1/search?q='.urlencode($term))
            ->json('data.groups');
        $pipelines = collect($groups)->firstWhere('type', 'pipeline');

        // Exact, not substring: "821" must not also return #8214 ranked by
        // accident, because "#821" is a precise reference not a search.
        expect($pipelines['items'])->toHaveCount(1)
            ->and($pipelines['items'][0]['title'])->toContain('#821');
    }
});

it('finds a failure by error text and by category name', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);

    Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'category' => 'DATABASE',
        'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
    ]);

    foreach (['SQLSTATE', 'database'] as $term) {
        $groups = $this->actingAs($user)->getJson("/api/v1/search?q={$term}")->json('data.groups');

        expect(collect($groups)->firstWhere('type', 'failure')['items'])->toHaveCount(1);
    }
});

it('surfaces known fixes but gives them no route', function () {
    $user = User::factory()->withTeam()->create();

    FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('a', 64),
        'is_known' => true,
        'known_resolution' => 'Add a postgres healthcheck to the compose file',
    ]);

    $groups = $this->actingAs($user)->getJson('/api/v1/search?q=healthcheck')->json('data.groups');
    $signatures = collect($groups)->firstWhere('type', 'signature');

    // Signatures have no page of their own yet. A link to nowhere is worse than
    // a result that only informs, so the route is explicitly null and the UI
    // says "no page yet".
    expect($signatures['items'][0]['route'])->toBeNull();
});

it('ignores an unconfirmed signature', function () {
    $user = User::factory()->withTeam()->create();

    FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('b', 64),
        'is_known' => false,
        'sample_error' => 'something about healthcheck',
    ]);

    // Only confirmed fixes are worth offering as an answer.
    expect(collect($this->actingAs($user)->getJson('/api/v1/search?q=healthcheck')->json('data.groups'))
        ->firstWhere('type', 'signature'))->toBeNull();
});

it('never searches across workspaces', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();
    $project = Project::factory()->create([
        'team_id' => $theirs->current_team_id, 'name' => 'secret-service',
    ]);

    Failure::factory()->create([
        'team_id' => $theirs->current_team_id,
        'project_id' => $project->id,
        'error_message' => 'secret-service exploded',
    ]);

    expect($this->actingAs($mine)->getJson('/api/v1/search?q=secret')->json('data.groups'))
        ->toBeEmpty();
});
