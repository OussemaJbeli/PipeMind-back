<?php

declare(strict_types=1);

use App\Exceptions\Integrations\IntegrationUnreachable;
use App\Jobs\FetchJobLog;
use App\Models\Failure;
use App\Models\Integration;
use App\Models\JobLog;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\AiFakes;

function failedJobFixture(): PipelineJob
{
    $integration = Integration::factory()->gitlab()->create();
    $project = Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id,
        'external_id' => '42',
    ]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();

    return PipelineJob::factory()->failed()->for($pipeline)->create([
        'name' => 'backend-tests',
        'stage_name' => 'test',
    ]);
}

it('still records a failure when the log has expired', function () {
    Storage::fake('logs');
    Http::fake(['*/trace*' => Http::response('', 404), '*' => AiFakes::router()]);

    $job = failedJobFixture();

    FetchJobLog::dispatchSync($job->id);

    // An expired log is a fact about the provider, not a reason to lose the failure.
    expect(Failure::withoutGlobalScopes()->count())->toBe(1)
        ->and(JobLog::withoutGlobalScopes()->count())->toBe(0)
        ->and($job->fresh()->log_fetched)->toBeTrue();

    $failure = Failure::withoutGlobalScopes()->first();
    expect($failure->job_name)->toBe('backend-tests')
        ->and($failure->error_message)->toContain('backend-tests');
});

it('still records a failure when the provider is unreachable', function () {
    Storage::fake('logs');
    Http::fake(['*/trace*' => Http::response('', 503), '*' => AiFakes::router()]);

    $job = failedJobFixture();

    // Exhaust the retries the way the queue eventually would.
    try {
        FetchJobLog::dispatchSync($job->id);
    } catch (IntegrationUnreachable) {
        (new FetchJobLog($job->id))->failed(new IntegrationUnreachable);
    }

    // Without this the pipeline reads as failed on the board with nothing to
    // click into — a failure with a weak message beats no failure at all.
    expect(Failure::withoutGlobalScopes()->count())->toBe(1)
        ->and($job->fresh()->log_fetched)->toBeTrue();
});

it('does not fetch the log twice', function () {
    Storage::fake('logs');
    Http::fake(['*/trace*' => Http::response('some log output'), '*' => AiFakes::router()]);

    $job = failedJobFixture();

    FetchJobLog::dispatchSync($job->id);
    FetchJobLog::dispatchSync($job->id);

    expect(JobLog::withoutGlobalScopes()->count())->toBe(1);

    // Count the PROVIDER call specifically. Asserting a total request count
    // would also capture the AI service call that ProcessJobLog now makes.
    $traceCalls = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/trace'))
        ->count();

    expect($traceCalls)->toBe(1);
});
