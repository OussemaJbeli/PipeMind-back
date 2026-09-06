<?php

declare(strict_types=1);

use App\Enums\FailureCategory;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\FailureController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\JobLogController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use App\Http\Controllers\Api\V1\Webhooks\WebhookController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks — public, no auth, signature-verified inside the controller
|--------------------------------------------------------------------------
| Excluded from the `api` throttle and given a far more generous limiter: a
| busy pipeline fires many events in a burst, and dropping them means lost
| state that only reconciliation can recover.
*/
Route::post('/webhooks/{provider}/{integration}', WebhookController::class)
    ->where('provider', 'gitlab|github|jenkins|generic')
    ->middleware('throttle:webhooks')
    ->withoutMiddleware(['throttle:api']);

/*
|--------------------------------------------------------------------------
| Health — no auth, for uptime checks
|--------------------------------------------------------------------------
*/
Route::get('/v1/system/status', function () {
    $gateway = app(AiGateway::class);
    $reachable = $gateway->reachable();

    return ['data' => [
        'api' => true,
        // A dead AI container must be visible, not mysterious: the UI has to
        // tell "analysis unavailable" apart from "your pipeline failed".
        'ai_service' => $reachable
            ? ['reachable' => true, ...rescue(fn () => $gateway->info(), [], report: false)]
            : ['reachable' => false],
    ]];
});

Route::get('/health', fn () => [
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]);

/*
|--------------------------------------------------------------------------
| Metadata — public reference data, no auth
|--------------------------------------------------------------------------
| Lets the frontend assert its CATEGORY_META matches this enum instead of
| trusting a hand-maintained copy. Drift here makes the donut and the bar list
| disagree with each other.
*/
Route::get('/v1/meta/categories', fn () => ['data' => collect(FailureCategory::cases())
    ->map(fn ($c) => [
        'value' => $c->value,
        'label' => $c->label(),
        'color' => $c->color(),
        'icon' => $c->icon(),
    ])->values()]);

Route::prefix('v1')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Guest auth — throttled hard: this is the credential-stuffing surface
    |----------------------------------------------------------------------
    */
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/login', [LoginController::class, 'store']);
        Route::post('/auth/register', [RegisterController::class, 'store']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated, no team context required
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [LoginController::class, 'destroy']);
        Route::get('/auth/me', [MeController::class, 'show']);
        Route::put('/auth/profile', [MeController::class, 'update']);
    });

    /*
    |----------------------------------------------------------------------
    | Team-scoped — every query below is bound to a team by ResolveTeam
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'team'])->group(function (): void {

        Route::get('/system/status', SystemStatusController::class);

        // Workspace home (ui/workspace.png)
        Route::get('/workspace/summary', [WorkspaceController::class, 'summary']);
        Route::get('/workspace/projects', [WorkspaceController::class, 'projects']);
        Route::get('/workspace/activity', [WorkspaceController::class, 'activity']);

        /*
        |------------------------------------------------------------------
        | Integrations — the CI/CD connection wizard
        |------------------------------------------------------------------
        */
        Route::middleware('can.do:integrations.manage')->group(function (): void {
            // Tests credentials BEFORE they are saved: the wizard proves the
            // connection works before it is willing to persist a token.
            Route::post('/integrations/test', [IntegrationController::class, 'testUnsaved']);

            Route::get('/integrations/webhook-settings', [IntegrationController::class, 'webhookSettings']);

            Route::get('/integrations', [IntegrationController::class, 'index']);
            Route::post('/integrations', [IntegrationController::class, 'store']);
            Route::get('/integrations/{integration}', [IntegrationController::class, 'show']);
            Route::put('/integrations/{integration}', [IntegrationController::class, 'update']);
            Route::delete('/integrations/{integration}', [IntegrationController::class, 'destroy']);

            Route::post('/integrations/{integration}/test', [IntegrationController::class, 'test']);
            Route::get('/integrations/{integration}/remote-projects', [IntegrationController::class, 'remoteProjects']);
            Route::post('/integrations/{integration}/import', [IntegrationController::class, 'import']);

            // Routine, not an edge case: a free tunnel rotates its hostname.
            Route::post('/integrations/{integration}/re-register', [IntegrationController::class, 'reRegister']);
        });

        // Projects. Bound by slug (see Project::getRouteKeyName).
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::get('/projects/{project}/overview', [ProjectController::class, 'overview']);
        Route::get('/projects/{project}/insights', [ProjectController::class, 'insights']);

        /*
        |------------------------------------------------------------------
        | Failure investigation — the page the whole product exists for
        |------------------------------------------------------------------
        */
        Route::get('/failures', [FailureController::class, 'index']);
        Route::get('/failures/{failure}', [FailureController::class, 'show']);
        Route::get('/failures/{failure}/similar', [FailureController::class, 'similar']);
        Route::get('/jobs/{job}/log', [JobLogController::class, 'show']);

        // Each analysis costs money and provider quota, so the manual trigger is
        // throttled on top of the queue-side per-team limiter.
        Route::middleware(['can.do:analysis.trigger', 'throttle:analysis'])->group(function (): void {
            Route::post('/failures/{failure}/analyze', [FailureController::class, 'analyze']);
        });

        Route::middleware('can.do:failures.resolve')->group(function (): void {
            Route::put('/failures/{failure}/resolve', [FailureController::class, 'resolve']);
            Route::put('/failures/{failure}/ignore', [FailureController::class, 'ignore']);
        });

        // Where the ML classifier's training set comes from.
        Route::middleware('can.do:feedback.submit')->group(function (): void {
            Route::post('/analyses/{analysis}/feedback', [AnalysisController::class, 'feedback']);
        });
    });
});
