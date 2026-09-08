<?php

declare(strict_types=1);

use App\Events\ActivityCreated;
use App\Events\AnomalyDetected;
use App\Events\FailureDetected;
use App\Events\JobUpdated;
use App\Events\PipelineUpdated;
use App\Events\ProjectStatsUpdated;
use App\Events\RemediationStatusChanged;
use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\Remediation;
use App\Models\Team;
use App\Services\Anomalies\AnomalyRecorder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** @return array<int,string> */
function channelNames(ShouldBroadcast $event): array
{
    return array_map(
        fn (PrivateChannel $channel) => (string) $channel,
        $event->broadcastOn(),
    );
}

it('queues every broadcast rather than sending it inline', function () {
    $events = collect(glob(app_path('Events/*.php')))
        ->map(fn (string $path) => 'App\\Events\\'.basename($path, '.php'));

    expect($events)->not->toBeEmpty();

    foreach ($events as $class) {
        // ShouldBroadcastNow would put a WebSocket round trip inside ingestion.
        // A webhook arriving while Reverb is down must still be recorded, so
        // broadcasting is queued work, always.
        expect(is_subclass_of($class, ShouldBroadcastNow::class))
            ->toBeFalse("{$class} broadcasts inline and can block ingestion")
            ->and(is_subclass_of($class, ShouldBroadcast::class))
            ->toBeTrue("{$class} does not broadcast at all");
    }
});

it('sends every event to a private channel and never a public one', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id]);
    $job = PipelineJob::factory()->create(['pipeline_id' => $pipeline->id]);
    $failure = Failure::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id, 'pipeline_id' => $pipeline->id,
    ]);

    // Pipeline names, branches and error text are proprietary. A public channel
    // hands all of it to anyone who can guess a UUID.
    foreach ([
        new PipelineUpdated($pipeline),
        new JobUpdated($job),
        new FailureDetected($failure),
        new ProjectStatsUpdated($project),
    ] as $event) {
        foreach ($event->broadcastOn() as $channel) {
            expect($channel)->toBeInstanceOf(PrivateChannel::class);
            expect((string) $channel)->toStartWith('private-');
        }
    }
});

it('addresses pipeline updates to both the project and the pipeline', function () {
    $project = Project::factory()->create();
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id]);

    // The project page needs the row; an open pipeline page needs the detail.
    expect(channelNames(new PipelineUpdated($pipeline)))->toBe([
        "private-project.{$project->uuid}",
        "private-pipeline.{$pipeline->uuid}",
    ]);
});

it('keeps job updates off the project channel', function () {
    $project = Project::factory()->create();
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id]);
    $job = PipelineJob::factory()->create(['pipeline_id' => $pipeline->id]);

    // Nothing on a project page renders individual jobs, and a busy pipeline
    // emits dozens of these.
    expect(channelNames(new JobUpdated($job)))->toBe(["private-pipeline.{$pipeline->uuid}"]);
});

it('sends remediation changes to the team as well, for the approval badge', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $failure = Failure::factory()->create(['team_id' => $team->id, 'project_id' => $project->id]);
    $remediation = Remediation::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id, 'failure_id' => $failure->id,
    ]);

    // A pending approval is somebody blocked, and it has to be visible from
    // anywhere in the workspace rather than only on that project's page.
    expect(channelNames(new RemediationStatusChanged($remediation)))
        ->toContain("private-team.{$team->uuid}")
        ->toContain("private-project.{$project->uuid}");
});

it('carries a complete pipeline payload so the client never has to refetch', function () {
    $project = Project::factory()->create();
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id, 'status' => 'failed']);

    $payload = (new PipelineUpdated($pipeline))->broadcastWith();

    // The frontend patches its cache from this. A partial forces a request per
    // job event and turns a busy pipeline into a load generator — the exact
    // thing realtime exists to remove.
    expect($payload)->toHaveKeys([
        'uuid', 'iid', 'status', 'ref', 'duration_seconds',
        'finished_at', 'jobs_total', 'jobs_failed', 'has_failure', 'project_slug',
    ]);

    // Enums must be serialised, not handed over as objects.
    expect($payload['status'])->toBeString()->toBe('failed');
});

it('gives a failure toast everything it needs to route to the failure', function () {
    $project = Project::factory()->create();
    $pipeline = Pipeline::factory()->create(['project_id' => $project->id]);
    $failure = Failure::factory()->create([
        'team_id' => $project->team_id, 'project_id' => $project->id, 'pipeline_id' => $pipeline->id,
    ]);

    $payload = (new FailureDetected($failure))->broadcastWith();

    // A notification that says "something failed" and then makes you find it is
    // worse than none: uuid + project_slug are the route parameters.
    expect($payload['uuid'])->toBe($failure->uuid)
        ->and($payload['project_slug'])->toBe($project->slug)
        ->and($payload)->toHaveKeys(['error_message', 'job_name', 'pipeline_iid', 'ref']);
});

it('broadcasts an activity entry whenever one is written', function () {
    Event::fake([ActivityCreated::class]);
    $project = Project::factory()->create();

    activity_log($project, 'pipeline.failed', 'error', $project->name, 'Something broke');

    // Dispatched from the helper rather than from ~40 call sites, because some
    // of those would inevitably be missed.
    Event::assertDispatched(ActivityCreated::class);
});

it('never lets a broken broadcaster fail the write it was reporting', function () {
    $project = Project::factory()->create();

    Event::listen(ActivityCreated::class, function (): void {
        throw new RuntimeException('reverb is down');
    });

    // Ingestion and queued jobs call this. A dead WebSocket must not turn
    // "a pipeline was recorded" into a failed job.
    activity_log($project, 'pipeline.failed', 'error', $project->name, 'Something broke');

    $this->assertDatabaseHas('activity_logs', [
        'team_id' => $project->team_id,
        'action' => 'pipeline.failed',
    ]);
});

it('announces a new anomaly but stays quiet when the same one recurs', function () {
    Event::fake([AnomalyDetected::class]);
    $project = Project::factory()->create();

    $candidate = [
        'type' => 'duration', 'severity' => 'high', 'metric_name' => 'job:test:duration',
        'observed_value' => 300.0, 'baseline_value' => 100.0, 'deviation_ratio' => 3.0,
        'title' => 'test is 3x slower than usual',
    ];

    $recorder = app(AnomalyRecorder::class);
    $recorder->record($project, $candidate);
    Event::assertDispatchedTimes(AnomalyDetected::class, 1);

    // The same slow job seen on twenty pipelines is one problem. Toasting it
    // twenty times is how people learn to ignore the toasts.
    $recorder->record($project, $candidate);
    Event::assertDispatchedTimes(AnomalyDetected::class, 1);
});
