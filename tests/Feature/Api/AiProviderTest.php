<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\AiFakes;

it('reports a working provider without saving anything', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/ai-providers/test', [
        'provider' => 'gemini',
        'api_key' => 'AIzaSyExampleKeyValue000000000000000000',
        'model' => 'gemini-2.0-flash',
    ])->assertOk()->assertJsonPath('data.ok', true);

    expect(AiProvider::count())->toBe(0);
});

it('returns 200 with the reason when credentials are rejected', function () {
    // "Your key is wrong" is a valid answer to "does this key work" — a 500 here
    // would make the wizard show a crash instead of the fix.
    Http::fake(['*/v1/providers/test' => Http::response(
        AiFakes::providerTest(false, 'It looks like an OAuth access token, not an API key.')
    )]);
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/ai-providers/test', [
        'provider' => 'gemini',
        'api_key' => 'AQ.Ab8NotAnApiKey',
    ])->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.message', 'It looks like an OAuth access token, not an API key.');
});

it('refuses to save a provider that does not work', function () {
    // A broken row that looks configured is worse than no row: auto-analysis
    // picks it up and every failure silently stops being analysed.
    Http::fake(['*/v1/providers/test' => Http::response(AiFakes::providerTest(false, 'Rejected.'))]);
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/ai-providers', [
        'name' => 'Gemini',
        'provider' => 'gemini',
        'model' => 'gemini-2.0-flash',
        'api_key' => 'bad',
    ])->assertStatus(422)->assertJsonPath('error_code', 'AI_PROVIDER_UNAUTHORIZED');

    expect(AiProvider::count())->toBe(0);
});

it('saves a provider that tests clean', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/ai-providers', [
        'name' => 'Gemini',
        'provider' => 'gemini',
        'model' => 'gemini-2.0-flash',
        'api_key' => 'AIzaSyExampleKeyValue000000000000000000',
        'is_default' => true,
    ])->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.has_api_key', true);

    expect(AiProvider::first()->last_tested_at)->not->toBeNull();
});

it('never returns the api key', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();
    AiProvider::factory()->create([
        'team_id' => $user->current_team_id,
        'api_key' => 'AIzaSySecretValueThatMustNotLeak000000',
    ]);

    $body = $this->actingAs($user)->getJson('/api/v1/ai-providers')->assertOk()->getContent();

    expect($body)->not->toContain('AIzaSySecretValueThatMustNotLeak')
        ->and(json_decode($body, true)['data'][0])->not->toHaveKey('api_key');
});

it('requires an api key for a cloud provider but not a local one', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->postJson('/api/v1/ai-providers', [
        'name' => 'Gemini', 'provider' => 'gemini', 'model' => 'gemini-2.0-flash',
    ])->assertStatus(422);

    $this->actingAs($user)->postJson('/api/v1/ai-providers', [
        'name' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.1',
    ])->assertCreated();
});

it('keeps exactly one default per team', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();

    AiProvider::factory()->create(['team_id' => $user->current_team_id, 'is_default' => true]);

    $this->actingAs($user)->postJson('/api/v1/ai-providers', [
        'name' => 'Second', 'provider' => 'ollama', 'model' => 'llama3.1', 'is_default' => true,
    ])->assertCreated();

    expect(AiProvider::where('is_default', true)->count())->toBe(1);
});

it('records the reason when a saved provider stops working', function () {
    Http::fake(['*/v1/providers/test' => Http::response(
        AiFakes::providerTest(false, 'Gemini rejected the credentials.')
    )]);
    $user = User::factory()->withTeam()->create();
    $provider = AiProvider::factory()->create([
        'team_id' => $user->current_team_id, 'status' => 'active',
    ]);

    $this->actingAs($user)->postJson("/api/v1/ai-providers/{$provider->uuid}/test")
        ->assertOk()->assertJsonPath('data.ok', false);

    // The workspace page shows this, so a dead key is visible before the next
    // failure goes unanalysed.
    expect($provider->fresh()->status)->toBe('error')
        ->and($provider->fresh()->last_error)->toContain('rejected');
});

it('refuses to delete a provider still assigned to projects', function () {
    $user = User::factory()->withTeam()->create();
    $provider = AiProvider::factory()->create(['team_id' => $user->current_team_id]);
    Project::factory()->create([
        'team_id' => $user->current_team_id,
        'ai_provider_id' => $provider->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/ai-providers/{$provider->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'AI_PROVIDER_IN_USE');
});
