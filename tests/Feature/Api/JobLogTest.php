<?php

declare(strict_types=1);

use App\Models\JobLog;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function jobWithLog(User $user, array $logAttributes = []): PipelineJob
{
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    JobLog::factory()->create([
        'job_id' => $job->id,
        'pipeline_id' => $pipeline->id,
        'project_id' => $project->id,
        ...$logAttributes,
    ]);

    return $job;
}

it('returns the redacted excerpt by default', function () {
    $user = User::factory()->withTeam()->create();
    $job = jobWithLog($user, ['is_redacted' => true, 'redaction_count' => 2,
        'redaction_types' => ['aws_secret', 'bearer_token']]);

    $data = $this->actingAs($user)->getJson("/api/v1/jobs/{$job->uuid}/log")
        ->assertOk()->json('data');

    expect($data['available'])->toBeTrue()
        ->and($data['mode'])->toBe('excerpt')
        ->and($data['excerpt'])->toContain('SQLSTATE')
        // The UI tells the user what was removed, so a redacted log does not
        // look like a corrupted one.
        ->and($data['redaction_count'])->toBe(2)
        ->and($data['redaction_types'])->toBe(['aws_secret', 'bearer_token'])
        ->and($data['content'])->toBeNull();
});

it('streams the stored object in full mode', function () {
    Storage::fake('logs');
    $user = User::factory()->withTeam()->create();
    $job = jobWithLog($user, ['storage_path' => 'logs/full.log']);

    Storage::disk('logs')->put('logs/full.log', 'line one'.PHP_EOL.'line two');

    $data = $this->actingAs($user)->getJson("/api/v1/jobs/{$job->uuid}/log?mode=full")
        ->assertOk()->json('data');

    expect($data['mode'])->toBe('full')
        ->and($data['content'])->toContain('line two');
});

it('says why a log is missing rather than returning an error', function () {
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();
    $job = PipelineJob::factory()->failed()->for($pipeline)->create(['log_fetched' => true]);

    // An expired log is a fact about the provider, not a broken page.
    $data = $this->actingAs($user)->getJson("/api/v1/jobs/{$job->uuid}/log")
        ->assertOk()->json('data');

    expect($data['available'])->toBeFalse()
        ->and($data['reason'])->toContain('no longer has this log');
});
