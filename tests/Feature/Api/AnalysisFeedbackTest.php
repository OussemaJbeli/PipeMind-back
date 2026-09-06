<?php

declare(strict_types=1);

use App\Models\Analysis;
use App\Models\AnalysisFeedback;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AnalysisCache;
use Tests\AiFakes;

function analysisFor(User $user): Analysis
{
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $signature = FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('c', 64),
    ]);
    $failure = Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'signature_id' => $signature->id,
    ]);

    return Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $user->current_team_id,
        'status' => 'completed',
    ]);
}

it('records the correction that becomes the training set', function () {
    $user = User::factory()->withTeam()->create();
    $analysis = analysisFor($user);

    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback", [
        'was_helpful' => false,
        'root_cause_correct' => false,
        'correct_category' => 'DOCKER',
        'actual_root_cause' => 'The image tag no longer existed in the registry.',
    ])->assertOk()->assertJsonPath('data.given', true);

    $feedback = AnalysisFeedback::first();

    // correct_category is the label column of the ML classifier's dataset.
    // Nothing else in PipeMind produces supervised labels.
    expect($feedback->correct_category->value)->toBe('DOCKER')
        ->and($feedback->was_helpful)->toBeFalse()
        ->and($feedback->user_id)->toBe($user->id);
});

it('constrains the corrected category to the classifier vocabulary', function () {
    $user = User::factory()->withTeam()->create();
    $analysis = analysisFor($user);

    // Free text here would produce labels the classifier can never predict.
    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback", [
        'was_helpful' => false,
        'correct_category' => 'SOMETHING_ELSE',
    ])->assertStatus(422);
});

it('purges the cached analysis a developer has rejected', function () {
    $user = User::factory()->withTeam()->create();
    $analysis = analysisFor($user);
    $failure = $analysis->failure->load('signature');

    $cache = app(AnalysisCache::class);
    $cache->put($failure, AiFakes::analyze());
    expect($cache->get($failure))->not->toBeNull();

    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback", [
        'was_helpful' => false,
    ])->assertOk();

    // Serving it again would repeat the mistake and look like the correction
    // was never heard.
    expect($cache->get($failure->fresh()->load('signature')))->toBeNull();
});

it('keeps the cache when the analysis was helpful', function () {
    $user = User::factory()->withTeam()->create();
    $analysis = analysisFor($user);
    $failure = $analysis->failure->load('signature');

    app(AnalysisCache::class)->put($failure, AiFakes::analyze());

    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback", [
        'was_helpful' => true,
    ])->assertOk();

    expect(app(AnalysisCache::class)->get($failure))->not->toBeNull();
});

it('replaces a user\'s earlier feedback rather than stacking it', function () {
    $user = User::factory()->withTeam()->create();
    $analysis = analysisFor($user);

    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback",
        ['was_helpful' => true])->assertOk();
    $this->actingAs($user)->postJson("/api/v1/analyses/{$analysis->uuid}/feedback",
        ['was_helpful' => false])->assertOk();

    expect(AnalysisFeedback::count())->toBe(1)
        ->and(AnalysisFeedback::first()->was_helpful)->toBeFalse();
});
