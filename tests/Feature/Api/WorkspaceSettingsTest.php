<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\Failure;
use App\Models\Project;
use App\Models\User;

it('reports what deleting the workspace would destroy', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    Failure::factory()->count(3)->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id,
    ]);

    $data = $this->actingAs($user)->getJson('/api/v1/workspace/settings')->assertOk()->json('data');

    // Shown beside the danger zone rather than discovered afterwards.
    expect($data['contents']['projects'])->toBe(1)
        ->and($data['contents']['failures'])->toBe(3)
        ->and($data['contents']['members'])->toBe(1);
});

it('updates the name and budget', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->putJson('/api/v1/workspace/settings', [
        'name' => 'OJ Team',
        'monthly_ai_budget_usd' => 40,
    ])->assertOk()->assertJsonPath('data.name', 'OJ Team');

    expect((float) $user->currentTeam->fresh()->monthly_ai_budget_usd)->toBe(40.0);
});

it('refuses local-only without a local provider', function () {
    $user = User::factory()->withTeam()->create();

    AiProvider::factory()->create([
        'team_id' => $user->current_team_id, 'provider' => 'gemini', 'is_local' => false,
    ]);

    // Switching would break every analysis on the next failure. Refuse while
    // the user is looking at the setting, not an hour later inside a queue.
    $this->actingAs($user)->putJson('/api/v1/workspace/settings', ['privacy_mode' => 'local_only'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'NO_LOCAL_PROVIDER');
});

it('allows local-only once a local provider exists', function () {
    $user = User::factory()->withTeam()->create();

    AiProvider::factory()->create([
        'team_id' => $user->current_team_id,
        'provider' => 'ollama', 'is_local' => true, 'status' => 'active',
    ]);

    $this->actingAs($user)->putJson('/api/v1/workspace/settings', ['privacy_mode' => 'local_only'])
        ->assertOk()
        ->assertJsonPath('data.privacy_mode', 'local_only');
});

it('does not drop the webhook url when saving a timezone', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;
    $team->setWebhookBaseUrl('https://example.trycloudflare.com');

    $this->actingAs($user)->putJson('/api/v1/workspace/settings', ['timezone' => 'Africa/Tunis'])
        ->assertOk();

    // `settings` is shared JSON. Assigning a fresh array would silently discard
    // the tunnel URL every webhook depends on.
    expect($team->fresh()->settings['timezone'])->toBe('Africa/Tunis')
        ->and($team->fresh()->settings['webhook_base_url'])->toBe('https://example.trycloudflare.com');
});

describe('ai usage', function () {
    it('measures month-to-date over the window the budget guard uses', function () {
        $user = User::factory()->withTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['monthly_ai_budget_usd' => 10])->save();

        // Inside this month, and counted.
        AiRequest::factory()->create([
            'team_id' => $team->id, 'cost_usd' => 2.5, 'status' => 'success',
            'created_at' => now()->startOfMonth()->addHour(),
        ]);
        // Last month: inside a 30-day display window, outside the budget window.
        AiRequest::factory()->create([
            'team_id' => $team->id, 'cost_usd' => 90, 'status' => 'success',
            'created_at' => now()->startOfMonth()->subDays(3),
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/workspace/usage')->assertOk()->json('data');

        // The bar must agree with the thing that actually blocks you.
        expect($data['month_to_date_usd'])->toBe(2.5)
            ->and((float) $data['monthly_budget_usd'])->toBe(10.0)
            ->and($data['budget_used_ratio'])->toBe(0.25);
    });

    it('excludes failed calls from spend but not from the error rate', function () {
        $user = User::factory()->withTeam()->create();

        AiRequest::factory()->create([
            'team_id' => $user->current_team_id, 'cost_usd' => 1, 'status' => 'success',
            'created_at' => now(),
        ]);
        AiRequest::factory()->create([
            'team_id' => $user->current_team_id, 'cost_usd' => 0, 'status' => 'error',
            'created_at' => now(),
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/workspace/usage')->json('data');

        expect((float) $data['month_to_date_usd'])->toBe(1.0)
            ->and((float) $data['error_rate'])->toBe(0.5);
    });

    it('breaks spend down by model', function () {
        $user = User::factory()->withTeam()->create();

        AiRequest::factory()->create([
            'team_id' => $user->current_team_id, 'model' => 'gemini-3.6-flash',
            'cost_usd' => 0.5, 'created_at' => now(),
        ]);
        AiRequest::factory()->cacheHit()->create([
            'team_id' => $user->current_team_id, 'model' => 'gemini-3.6-flash',
            'created_at' => now(),
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/workspace/usage')->json('data');

        expect($data['by_model'])->toHaveCount(1)
            ->and($data['by_model'][0]['calls'])->toBe(2)
            // The number that shows whether caching earns its complexity.
            ->and($data['cache_hit_rate'])->toBe(0.5);
    });

    it('returns a null ratio rather than dividing by a zero budget', function () {
        $user = User::factory()->withTeam()->create();
        $user->currentTeam->forceFill(['monthly_ai_budget_usd' => 0])->save();

        expect($this->actingAs($user)->getJson('/api/v1/workspace/usage')->json('data.budget_used_ratio'))
            ->toBeNull();
    });
});

it('never exposes another workspace\'s settings or spend', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    AiRequest::factory()->create([
        'team_id' => $theirs->current_team_id, 'cost_usd' => 99, 'created_at' => now(),
    ]);

    // Both read from the bound team, so there is no id to tamper with — the
    // check is that the numbers are this workspace's, not the other's.
    expect((float) $this->actingAs($mine)->getJson('/api/v1/workspace/usage')->json('data.month_to_date_usd'))
        ->toBe(0.0);

    expect($this->actingAs($mine)->getJson('/api/v1/workspace/settings')->json('data.uuid'))
        ->toBe($mine->currentTeam->uuid);
});
