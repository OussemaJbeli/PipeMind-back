<?php

declare(strict_types=1);

use App\Models\Analysis;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AnalysisCache;
use Tests\AiFakes;

function signatureWithFailure(User $user): FailureSignature
{
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $signature = FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('e', 64),
    ]);

    Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'signature_id' => $signature->id,
    ]);

    return $signature;
}

it('confirms a known resolution', function () {
    $user = User::factory()->withTeam()->create();
    $signature = signatureWithFailure($user);

    $data = $this->actingAs($user)->putJson("/api/v1/signatures/{$signature->uuid}/resolution", [
        'is_known' => true,
        'known_root_cause' => 'The postgres service was not linked to the job.',
        'known_resolution' => 'Add postgres under services in the workflow.',
    ])->assertOk()->json('data');

    // This is what makes the analyzer short-circuit future occurrences: no
    // model call, no cost, and an answer a human wrote.
    expect($data['is_known'])->toBeTrue()
        ->and($data['known_resolution'])->toContain('Add postgres')
        ->and($data['confirmed_at'])->not->toBeNull();

    expect($signature->fresh()->resolution_confirmed_by)->toBe($user->id);
});

it('refuses to mark a signature known with no resolution text', function () {
    $user = User::factory()->withTeam()->create();
    $signature = signatureWithFailure($user);

    // `is_known` without a resolution short-circuits future failures to
    // nothing, which is worse than not short-circuiting at all.
    $this->actingAs($user)->putJson("/api/v1/signatures/{$signature->uuid}/resolution",
        ['is_known' => true])->assertStatus(422);
});

it('withdraws a resolution and clears the confirmation', function () {
    $user = User::factory()->withTeam()->create();
    $signature = signatureWithFailure($user);
    $signature->forceFill([
        'is_known' => true,
        'known_resolution' => 'Something we no longer believe',
        'resolution_confirmed_by' => $user->id,
        'resolution_confirmed_at' => now(),
    ])->save();

    $data = $this->actingAs($user)->putJson("/api/v1/signatures/{$signature->uuid}/resolution",
        ['is_known' => false])->assertOk()->json('data');

    expect($data['is_known'])->toBeFalse()
        ->and($data['known_resolution'])->toBeNull()
        ->and($data['confirmed_at'])->toBeNull();
});

it('purges cached analyses for the signature it just learned about', function () {
    $user = User::factory()->withTeam()->create();
    $signature = signatureWithFailure($user);
    $failure = $signature->failures()->with('signature')->first();

    $cache = app(AnalysisCache::class);
    $cache->put($failure, AiFakes::analyze());
    expect($cache->get($failure))->not->toBeNull();

    $this->actingAs($user)->putJson("/api/v1/signatures/{$signature->uuid}/resolution", [
        'is_known' => true,
        'known_resolution' => 'Add the healthcheck back',
    ])->assertOk();

    // Every cached analysis predates the knowledge just recorded, so serving
    // one would ignore what a human just taught the system.
    expect($cache->get($failure->fresh()->load('signature')))->toBeNull();
});

it('never exposes another workspace\'s signature', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $signature = FailureSignature::factory()->create([
        'team_id' => $theirs->current_team_id,
        'hash' => str_repeat('f', 64),
    ]);

    $this->actingAs($mine)->getJson("/api/v1/signatures/{$signature->uuid}")->assertNotFound();
    $this->actingAs($mine)->putJson("/api/v1/signatures/{$signature->uuid}/resolution",
        ['is_known' => false])->assertNotFound();
});
