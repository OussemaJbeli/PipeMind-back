<?php

declare(strict_types=1);

use App\Exceptions\Ai\AiInvalidResponse;
use App\Exceptions\Ai\AiProviderRateLimited;
use App\Exceptions\Ai\NoLocalProviderConfigured;
use App\Jobs\AnalyzeFailure;
use App\Jobs\EmbedFailure;
use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\Analysis;
use App\Models\CommitChange;
use App\Models\Failure;
use App\Models\FailureEmbedding;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AnalysisCache;
use App\Services\Ai\AnalysisContextBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AiFakes;

function analysable(array $failureAttributes = [], array $projectAttributes = []): Failure
{
    $user = User::factory()->withTeam()->create();
    $project = Project::factory()->create([
        'team_id' => $user->current_team_id,
        ...$projectAttributes,
    ]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create(['ref' => 'feature/payment']);
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    $signature = FailureSignature::factory()->create([
        'team_id' => $user->current_team_id,
        'hash' => str_repeat('b', 64),
    ]);

    return Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'pipeline_id' => $pipeline->id,
        'job_id' => $job->id,
        'signature_id' => $signature->id,
        'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
        'category' => 'DATABASE',
        'ecosystem' => 'php',
        ...$failureAttributes,
    ]);
}

describe('the analysis job', function () {
    it('stores the analysis, its evidence and its recommendations', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable();

        AnalyzeFailure::dispatchSync($failure->id);

        $analysis = $failure->fresh()->latestAnalysis;

        expect($analysis->status->value)->toBe('completed')
            ->and($analysis->root_cause)->toContain('database service')
            ->and($analysis->evidence()->count())->toBe(1)
            ->and($analysis->recommendations()->count())->toBe(1)
            ->and($failure->fresh()->status->value)->toBe('analyzed');
    });

    it('records every call in the ledger', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable();

        AnalyzeFailure::dispatchSync($failure->id);

        $request = AiRequest::withoutGlobalScopes()->first();

        expect($request->operation)->toBe('analyze')
            ->and($request->provider)->toBe('stub')
            ->and($request->total_tokens)->toBe(591)
            ->and($request->status)->toBe('success');
    });

    it('lets the analysis supersede the ingest-time category on the signature too', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable(['category' => 'UNKNOWN']);

        AnalyzeFailure::dispatchSync($failure->id);

        expect($failure->fresh()->category->value)->toBe('DATABASE')
            ->and($failure->signature->fresh()->category->value)->toBe('DATABASE');
    });

    it('skips flaky failures rather than paying to re-analyse noise', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable(['is_flaky' => true]);

        AnalyzeFailure::dispatchSync($failure->id);

        expect($failure->fresh()->status->value)->toBe('analyzed')
            ->and(Analysis::withoutGlobalScopes()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('analyses a flaky failure anyway when forced', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable(['is_flaky' => true]);

        AnalyzeFailure::dispatchSync($failure->id, force: true);

        expect(Analysis::withoutGlobalScopes()->count())->toBe(1);
    });

    it('queues the embedding after a successful analysis', function () {
        Queue::fake([EmbedFailure::class]);
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable();

        AnalyzeFailure::dispatchSync($failure->id);

        Queue::assertPushed(EmbedFailure::class, fn ($job) => $job->failureId === $failure->id);
    });

    it('does not retry when the budget is spent', function () {
        Http::fake([
            '*/v1/analyze' => Http::response([
                'error_code' => 'AI_BUDGET_EXCEEDED', 'message' => 'Monthly AI budget reached.',
            ], 402),
            '*' => AiFakes::router(),
        ]);

        $failure = analysable();

        AnalyzeFailure::dispatchSync($failure->id);

        // The job calls fail() rather than rethrowing: the point of a budget is
        // that it stops you, and a rethrow would hand the job back to the queue
        // to spend the money again.
        expect($failure->fresh()->status->value)->toBe('analysis_failed')
            ->and($failure->fresh()->latestAnalysis->status->value)->toBe('failed')
            ->and($failure->fresh()->latestAnalysis->error)->toContain('budget');

        Http::assertSentCount(1);

        // The refusal is in the ledger, so a spent budget is visible in the
        // cost dashboard rather than looking like the AI simply went quiet.
        expect(AiRequest::withoutGlobalScopes()->first()->status)->toBe('budget_exceeded');
    });

    it('rejects a response missing the fields the contract guarantees', function () {
        Http::fake([
            '*/v1/analyze' => Http::response(['summary' => 'partial']),
            '*' => AiFakes::router(),
        ]);
        $failure = analysable();

        expect(fn () => AnalyzeFailure::dispatchSync($failure->id))
            ->toThrow(AiInvalidResponse::class);

        // Nothing half-written: no analysis row claiming to be completed.
        expect(Analysis::withoutGlobalScopes()->where('status', 'completed')->count())->toBe(0);
    });

    it('leaves nothing stuck at analyzing when the last retry dies', function () {
        $failure = analysable(['status' => 'analyzing']);

        (new AnalyzeFailure($failure->id))->failed(new RuntimeException('queue gave up'));

        expect($failure->fresh()->status->value)->toBe('analysis_failed');
    });
});

describe('the analysis cache', function () {
    it('reuses a confident analysis of the same signature in the same project', function () {
        Http::fake(['*' => AiFakes::router()]);
        $first = analysable();

        AnalyzeFailure::dispatchSync($first->id);

        $second = Failure::factory()->create([
            'team_id' => $first->team_id,
            'project_id' => $first->project_id,
            'pipeline_id' => $first->pipeline_id,
            'job_id' => $first->job_id,
            'signature_id' => $first->signature_id,
            'error_message' => $first->error_message,
        ]);

        Http::fake(['*' => AiFakes::router()]);
        AnalyzeFailure::dispatchSync($second->id);

        $analysis = $second->fresh()->latestAnalysis;

        expect($analysis->cache_hit)->toBeTrue()
            ->and((float) $analysis->cost_usd)->toBe(0.0)
            // Carrying the original latency forward would make every
            // "the cache saved us" figure report the opposite of the truth.
            ->and($analysis->latency_ms)->toBe(0)
            ->and($analysis->prompt_tokens)->toBe(0)
            // A reused analysis still gets its own evidence and recommendations,
            // or the failure page renders empty for the best-handled failures.
            ->and($analysis->evidence()->count())->toBe(1)
            ->and($analysis->recommendations()->count())->toBe(1);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/analyze'));
    });

    it('never shares a cached analysis across projects', function () {
        $failure = analysable();
        $cache = app(AnalysisCache::class);

        $cache->put($failure, AiFakes::analyze());

        $otherProject = Project::factory()->create(['team_id' => $failure->team_id]);
        $elsewhere = Failure::factory()->create([
            'team_id' => $failure->team_id,
            'project_id' => $otherProject->id,
            'signature_id' => $failure->signature_id,
        ]);

        // Identical error text in a different codebase can have a genuinely
        // different cause. Sharing here is how you ship a confidently wrong answer.
        expect($cache->get($elsewhere->load('signature')))->toBeNull();
    });

    it('forgets an analysis a human has rejected', function () {
        $failure = analysable();
        $cache = app(AnalysisCache::class);

        $cache->put($failure, AiFakes::analyze());
        expect($cache->get($failure))->not->toBeNull();

        $cache->forget($failure);
        expect($cache->get($failure))->toBeNull();
    });
});

describe('the context builder', function () {
    it('puts config and dependency changes first', function () {
        $failure = analysable();

        CommitChange::factory()->create([
            'pipeline_id' => $failure->pipeline_id, 'project_id' => $failure->project_id,
            'file_path' => 'src/Thing.php',
        ]);
        CommitChange::factory()->config()->create([
            'pipeline_id' => $failure->pipeline_id, 'project_id' => $failure->project_id,
        ]);

        $context = app(AnalysisContextBuilder::class)->build($failure);

        expect($context['pipeline']['changed_files'][0]['path'])->toBe('docker-compose.yml')
            ->and($context['pipeline']['changed_files'][0]['is_config'])->toBeTrue();
    });

    it('carries the ecosystem so retrieval can rebuild the same embedding input', function () {
        $context = app(AnalysisContextBuilder::class)->build(analysable());

        expect($context['failure']['ecosystem'])->toBe('php');
    });

    it('refuses to send a local-only team to the cloud', function () {
        $failure = analysable();
        $failure->project->team->update(['privacy_mode' => 'local_only']);

        AiProvider::factory()->create([
            'team_id' => $failure->team_id,
            'provider' => 'gemini',
            'is_local' => false,
            'is_default' => true,
        ]);

        // Failing loudly beats quietly overriding the user's privacy setting.
        expect(fn () => app(AnalysisContextBuilder::class)->build($failure->fresh()))
            ->toThrow(NoLocalProviderConfigured::class);
    });

    it('uses the local provider when a local-only team has one', function () {
        $failure = analysable();
        $failure->project->team->update(['privacy_mode' => 'local_only']);

        AiProvider::factory()->create([
            'team_id' => $failure->team_id,
            'provider' => 'ollama',
            'is_local' => true,
            'is_default' => true,
            'status' => 'active',
        ]);

        $context = app(AnalysisContextBuilder::class)->build($failure->fresh());

        expect($context['llm_override']['provider'])->toBe('ollama');
    });
});

describe('the embedding job', function () {
    it('stores the vector against the failure', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable();

        EmbedFailure::dispatchSync($failure->id);

        $embedding = FailureEmbedding::where('failure_id', $failure->id)->first();

        expect($embedding)->not->toBeNull()
            ->and($embedding->dimensions)->toBe(384)
            ->and($embedding->model)->toBe('all-MiniLM-L6-v2')
            ->and($embedding->embedding)->toHaveCount(384);
    });

    it('re-embeds rather than duplicating when run again', function () {
        Http::fake(['*' => AiFakes::router()]);
        $failure = analysable();

        EmbedFailure::dispatchSync($failure->id);
        EmbedFailure::dispatchSync($failure->id);

        expect(FailureEmbedding::where('failure_id', $failure->id)->count())->toBe(1);
    });
});

it('does not retry a rejected API key', function () {
    Http::fake([
        '*/v1/analyze' => Http::response([
            'error_code' => 'AI_PROVIDER_UNAUTHORIZED',
            'message' => 'Gemini rejected this credential type. It looks like an OAuth access token.',
        ], 401),
        '*' => AiFakes::router(),
    ]);

    $failure = analysable();

    AnalyzeFailure::dispatchSync($failure->id);

    // One attempt, not three: no retry can fix a typo, and repeating it buries
    // the one message that tells the user what to change.
    Http::assertSentCount(1);

    expect($failure->fresh()->status->value)->toBe('analysis_failed')
        ->and($failure->fresh()->latestAnalysis->error)->toContain('OAuth access token');
});

it('sends the previous pipeline status as a string, not a PHP enum', function () {
    // `value()` applies model casts, so this comes back as a PipelineStatus enum.
    // The payload is JSON-encoded for a Python service that has no idea what a
    // PHP enum is — and the declared ?string return type made it a hard TypeError
    // on every failure whose branch had a prior pipeline.
    $failure = analysable();

    Pipeline::factory()->for($failure->project)->create([
        'ref' => $failure->pipeline->ref,
        'status' => 'success',
        'id' => $failure->pipeline_id - 1,
    ]);

    $context = app(AnalysisContextBuilder::class)->build($failure);

    expect($context['pipeline']['previous_status'])->toBe('success')
        ->and(json_encode($context))->toBeString();
});

it('reports a spent quota as a rate limit, not an outage', function () {
    // Google's free tier caps generateContent at 20 requests per day per model.
    // Telling the user "the analysis service is unreachable" sends them to check
    // containers and logs for a problem that does not exist.
    Http::fake([
        '*/v1/analyze' => Http::response([
            'error_code' => 'AI_PROVIDER_RATE_LIMITED',
            'message' => 'Quota exceeded for generate_content_free_tier_requests, limit: 20.',
        ], 429),
        '*' => AiFakes::router(),
    ]);

    $failure = analysable();

    // handle() directly, not dispatchSync: sync dispatch has no retries left, so
    // it runs failed() and finalises the row. The state under test is the one
    // BETWEEN queue attempts, which only exists mid-retry.
    $job = new AnalyzeFailure($failure->id);

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(AiProviderRateLimited::class);

    // Left as `analyzing`: the queue is coming back, and flipping to failed
    // would flicker in the UI for what is only a wait.
    expect($failure->fresh()->status->value)->toBe('analyzing')
        ->and(AiRequest::withoutGlobalScopes()->first()->status)->toBe('rate_limited');

    // The last attempt does finalise it, so nothing is left spinning forever.
    $job->failed(new AiProviderRateLimited);
    expect($failure->fresh()->status->value)->toBe('analysis_failed');
});
