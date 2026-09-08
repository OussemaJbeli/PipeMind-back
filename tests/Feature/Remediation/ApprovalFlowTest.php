<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\ExecuteRemediation;
use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Recommendation, 1: Failure, 2: Project}
 */
function flowFixture(
    Team $team,
    array $recommendation = [],
    string $ref = 'feature/x',
    array $policy = [],
): array {
    $project = Project::factory()->create(['team_id' => $team->id, 'default_branch' => 'main']);
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id, 'ref' => $ref]);
    $failure = Failure::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id, 'pipeline_id' => $pipeline->id,
    ]);

    if ($policy !== []) {
        RemediationPolicy::factory()->create([
            'team_id' => $team->id,
            'action_type' => $recommendation['action_type'] ?? 'retry_job',
            'blocked_branches' => [],
            ...$policy,
        ]);
    }

    $rec = Recommendation::factory()->create([
        'failure_id' => $failure->id,
        ...['action_type' => 'retry_job', 'confidence' => 0.95, 'risk' => 'low', ...$recommendation],
    ]);

    return [$rec, $failure, $project];
}

it('runs a low-risk retry automatically on a feature branch and queues execution', function () {
    Queue::fake();
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();
    $team = $user->currentTeam;

    [$rec] = flowFixture($team, policy: [
        'mode' => 'auto', 'max_risk' => 'low', 'min_confidence' => 0.85,
    ]);

    $data = $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertCreated()->json('data');

    // DoD 1. No human in the loop for this one, and the queue entry is what
    // proves it will actually happen rather than sitting approved forever.
    expect($data['status'])->toBe('approved')
        ->and($data['policy_decision'])->toBe('auto_allowed');

    Queue::assertPushed(ExecuteRemediation::class);
});

it('requires approval for a medium-risk action and says which limit stopped it', function () {
    Queue::fake();
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();

    [$rec] = flowFixture($user->currentTeam,
        ['action_type' => 'create_merge_request', 'confidence' => 0.99],
        policy: ['action_type' => 'create_merge_request', 'mode' => 'auto',
            'max_risk' => 'low', 'min_confidence' => 0.90]);

    $data = $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertCreated()->json('data');

    // create_merge_request is MEDIUM by action type, over a max_risk of low.
    expect($data['status'])->toBe('pending_approval')
        ->and($data['policy_reason'])->toContain('medium')->toContain('low');

    Queue::assertNotPushed(ExecuteRemediation::class);
});

it('shows the policy reason on an action it cannot perform itself', function () {
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();

    // DoD 2. edit_file has no executor by design — it is a proposal a human
    // applies — but the gate still has to be visible on the recommendation, or
    // the UI cannot say why the action is unavailable. The decision is recorded
    // before the "can PipeMind do this?" check for exactly that reason.
    [$rec, $failure] = flowFixture($user->currentTeam,
        ['action_type' => 'edit_file', 'confidence' => 0.99],
        policy: ['action_type' => 'edit_file', 'mode' => 'auto',
            'max_risk' => 'low', 'min_confidence' => 0.90]);

    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertForbidden();

    $policy = collect($this->actingAs($user)
        ->getJson("/api/v1/failures/{$failure->uuid}/recommendations")
        ->assertOk()->json('data'))
        ->firstWhere('uuid', $rec->uuid)['policy'];

    expect($policy['decision'])->toBe('requires_approval')
        ->and($policy['reason'])->toContain('medium')->toContain('low');
});

it('refuses a deployment rollback outright', function () {
    $user = User::factory()->withTeam(TeamRole::OWNER)->create();

    // DoD 3. Forbidden by the shipped default policy set — not by the absence of
    // one, which is why the policy is created here explicitly.
    [$rec] = flowFixture($user->currentTeam, ['action_type' => 'rollback_deployment'], policy: [
        'action_type' => 'rollback_deployment', 'mode' => 'forbidden',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'REMEDIATION_FORBIDDEN');
});

it('will not let the requester approve their own request', function () {
    Queue::fake();
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();

    [$rec] = flowFixture($user->currentTeam, ['action_type' => 'create_merge_request'], policy: [
        'action_type' => 'create_merge_request', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $uuid = $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertCreated()->json('data.uuid');

    // DoD 4. Self-approval makes the gate a formality: the point of an approval
    // is that a second person saw it.
    $this->actingAs($user)
        ->postJson("/api/v1/remediations/{$uuid}/approve")
        ->assertForbidden();

    expect(Remediation::withoutGlobalScopes()->firstWhere('uuid', $uuid)->status->value)
        ->toBe('pending_approval');
    Queue::assertNotPushed(ExecuteRemediation::class);
});

it('lets a different approver run it', function () {
    Queue::fake();
    $requester = User::factory()->withTeam(TeamRole::ADMIN)->create();
    $team = $requester->currentTeam;

    $approver = User::factory()->create();
    $approver->teams()->attach($team, ['role' => TeamRole::ADMIN->value, 'joined_at' => now()]);
    $approver->forceFill(['current_team_id' => $team->id])->save();

    [$rec] = flowFixture($team, ['action_type' => 'create_merge_request'], policy: [
        'action_type' => 'create_merge_request', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $uuid = $this->actingAs($requester)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertCreated()->json('data.uuid');

    $data = $this->actingAs($approver)
        ->postJson("/api/v1/remediations/{$uuid}/approve")
        ->assertOk()->json('data');

    expect($data['status'])->toBe('approved')->and($data['approved_by'])->toBe($approver->name);
    Queue::assertPushed(ExecuteRemediation::class);
});

it('refuses to approve an expired request', function () {
    Queue::fake();
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();
    [$rec, $failure, $project] = flowFixture($user->currentTeam);

    $remediation = Remediation::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'failure_id' => $failure->id,
        'recommendation_id' => $rec->id,
        'status' => 'pending_approval',
        'requested_by' => User::factory()->create()->id,
        'expires_at' => now()->subHour(),
    ]);

    // DoD 5. A stale approval executing against a codebase that has moved on is
    // the hazard the whole expiry exists for.
    $this->actingAs($user)
        ->postJson("/api/v1/remediations/{$remediation->uuid}/approve")
        ->assertStatus(410)
        ->assertJsonPath('error_code', 'REMEDIATION_EXPIRED');

    expect($remediation->fresh()->status->value)->toBe('expired');
    Queue::assertNotPushed(ExecuteRemediation::class);
});

it('requires a reason to reject', function () {
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();
    [$rec, $failure, $project] = flowFixture($user->currentTeam);

    $remediation = Remediation::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id,
        'failure_id' => $failure->id, 'recommendation_id' => $rec->id,
        'requested_by' => User::factory()->create()->id,
    ]);

    // A bare "rejected" teaches nobody anything, and these rejections are the
    // record of what the policy should have caught.
    $this->actingAs($user)
        ->postJson("/api/v1/remediations/{$remediation->uuid}/reject", [])
        ->assertStatus(422);

    $data = $this->actingAs($user)
        ->postJson("/api/v1/remediations/{$remediation->uuid}/reject", [
            'reason' => 'The healthcheck belongs in the base compose file, not the CI override.',
        ])->assertOk()->json('data');

    expect($data['status'])->toBe('rejected')
        ->and($data['rejection_reason'])->toContain('base compose file');
});

it('cancels an approved but unexecuted remediation when the policy is tightened', function () {
    Queue::fake();
    $owner = User::factory()->withTeam(TeamRole::OWNER)->create();
    $team = $owner->currentTeam;

    [$rec, $failure, $project] = flowFixture($team, ['action_type' => 'retry_pipeline'], policy: [
        'action_type' => 'retry_pipeline', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $remediation = Remediation::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id, 'failure_id' => $failure->id,
        'recommendation_id' => $rec->id, 'action_type' => 'retry_pipeline',
        'status' => 'approved', 'approved_at' => now(),
    ]);

    // DoD 7. Otherwise turning an action off would not turn it off: the approval
    // sits in the queue and executes under the policy that no longer exists.
    $response = $this->actingAs($owner)->putJson('/api/v1/workspace/policies/retry_pipeline', [
        'mode' => 'forbidden',
        'max_risk' => 'low',
        'min_confidence' => 1.0,
        'max_per_day' => 0,
        'allowed_branches' => ['*'],
        'blocked_branches' => [],
        'enabled' => true,
    ])->assertOk();

    expect($response->json('data.cancelled_remediations'))->toBe(1)
        ->and($remediation->fresh()->status->value)->toBe('cancelled')
        ->and($remediation->fresh()->error)->toContain('policy was changed');
});

it('records every transition in the audit trail', function () {
    Queue::fake();
    $requester = User::factory()->withTeam(TeamRole::ADMIN)->create();
    $team = $requester->currentTeam;

    $approver = User::factory()->create();
    $approver->teams()->attach($team, ['role' => TeamRole::ADMIN->value, 'joined_at' => now()]);
    $approver->forceFill(['current_team_id' => $team->id])->save();

    [$rec] = flowFixture($team, ['action_type' => 'create_merge_request'], policy: [
        'action_type' => 'create_merge_request', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $uuid = $this->actingAs($requester)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")->json('data.uuid');
    $this->actingAs($approver)->postJson("/api/v1/remediations/{$uuid}/approve")->assertOk();

    $audit = $this->actingAs($approver)
        ->getJson("/api/v1/remediations/{$uuid}")->assertOk()->json('data.audit');

    // DoD 8. The trail answers "who allowed this, and when" without reading logs.
    expect(collect($audit)->pluck('event')->all())->toBe(['requested', 'approved'])
        ->and($audit[0]['user_id'])->toBe($requester->id)
        ->and($audit[1]['user_id'])->toBe($approver->id);

    $this->assertDatabaseHas('activity_logs', [
        'team_id' => $team->id, 'action' => 'remediation.approved',
    ]);
});

it('does not create a second remediation when accept is called twice', function () {
    Queue::fake();
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();

    [$rec] = flowFixture($user->currentTeam, ['action_type' => 'create_merge_request'], policy: [
        'action_type' => 'create_merge_request', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $first = $this->actingAs($user)->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertCreated()->json('data.uuid');

    // A double click is not a request for two pull requests.
    $second = $this->actingAs($user)->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertOk()->json('data.uuid');

    expect($second)->toBe($first)
        ->and(Remediation::withoutGlobalScopes()->where('recommendation_id', $rec->id)->count())->toBe(1);
});

it('refuses an action no executor can perform, rather than accepting it silently', function () {
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();

    // update_config is a proposal a human applies. It reaches the flow and stops
    // here with a message, instead of becoming a remediation that never runs.
    [$rec] = flowFixture($user->currentTeam, ['action_type' => 'update_config'], policy: [
        'action_type' => 'update_config', 'mode' => 'approval',
        'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertForbidden();
});

it('never lets one workspace accept another\'s recommendation', function () {
    $user = User::factory()->withTeam(TeamRole::ADMIN)->create();
    $other = Team::factory()->create();
    [$foreign] = flowFixture($other, policy: ['mode' => 'auto', 'min_confidence' => 0.0]);

    // Recommendations carry no team_id, so route binding is the only guard.
    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$foreign->uuid}/accept")
        ->assertNotFound();

    expect(Remediation::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let a viewer request or approve anything', function () {
    $user = User::factory()->withTeam(TeamRole::VIEWER)->create();
    [$rec] = flowFixture($user->currentTeam, policy: ['mode' => 'auto', 'min_confidence' => 0.0]);

    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$rec->uuid}/accept")
        ->assertForbidden();
});
