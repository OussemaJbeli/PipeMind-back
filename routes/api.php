<?php

declare(strict_types=1);

use App\Enums\FailureCategory;
use App\Http\Controllers\Api\V1\AiProviderController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AnomalyController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\FailureController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\JobLogController;
use App\Http\Controllers\Api\V1\KnowledgeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RemediationController;
use App\Http\Controllers\Api\V1\RemediationPolicyController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SignatureController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use App\Http\Controllers\Api\V1\Webhooks\WebhookController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceSettingsController;
use Illuminate\Support\Facades\DB;
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
| This used to be registered at `/v1/system/status`, which the AUTHENTICATED
| route of the same path silently shadowed — Laravel keeps the last
| registration. The public endpoint was dead code returning 401 to any uptime
| monitor pointed at it, and the bug inside it (a call to a method that does not
| exist) was never reached, so nothing ever complained.
|
| Deliberately minimal. The rich version — model names, contract version,
| provider health — stays behind auth: an unauthenticated caller has no business
| learning which model a workspace runs.
*/
Route::get('/health', function () {
    $database = rescue(fn () => DB::select('select 1') !== [], false, report: false);

    return response()->json([
        'status' => $database ? 'ok' : 'degraded',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ], $database ? 200 : 503);
});

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

        // Both answer identically for a known and an unknown address: any
        // difference makes this an account-enumeration oracle.
        Route::post('/auth/forgot-password', [PasswordResetController::class, 'request']);
        Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);
    });

    // Public, and NOT under throttle:auth — that limiter keys on the submitted
    // email, which a GET has none of, so every invitee would share one 5/min
    // bucket and a page reload could lock somebody out of joining.
    Route::middleware('throttle:api')->group(function (): void {
        Route::get('/invitations/{token}', [MemberController::class, 'preview']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated, no team context required
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [LoginController::class, 'destroy']);

        // Outside the `team` middleware on purpose: the invitee is not yet a
        // member of the team they are joining, so resolving a team first would
        // reject exactly the person the link was sent to.
        Route::post('/invitations/{token}/accept', [MemberController::class, 'accept']);
        Route::get('/auth/me', [MeController::class, 'show']);
        Route::put('/auth/profile', [MeController::class, 'update']);
        Route::post('/auth/onboarded', [MeController::class, 'completeOnboarding']);
    });

    /*
    |----------------------------------------------------------------------
    | Team-scoped — every query below is bound to a team by ResolveTeam
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'team'])->group(function (): void {

        Route::get('/system/status', SystemStatusController::class);

        // One request per keystroke, across everything the palette can reach.
        Route::get('/search', SearchController::class);

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
        Route::get('/projects/{project}/analytics', [ProjectController::class, 'analytics']);
        Route::get('/projects/{project}/settings', [ProjectController::class, 'settings']);

        Route::middleware('can.do:projects.manage')->group(function (): void {
            Route::put('/projects/{project}', [ProjectController::class, 'update']);
        });

        /*
        |------------------------------------------------------------------
        | Knowledge — runbooks an analysis can quote
        |------------------------------------------------------------------
        | Retrieval has existed since roadmap 09; nothing populated it, so RAG's
        | third source was permanently empty.
        */
        Route::get('/projects/{project}/knowledge', [KnowledgeController::class, 'index']);
        Route::get('/knowledge/{document}', [KnowledgeController::class, 'show']);

        Route::middleware('can.do:knowledge.manage')->group(function (): void {
            Route::post('/projects/{project}/knowledge', [KnowledgeController::class, 'store']);
            Route::put('/knowledge/{document}', [KnowledgeController::class, 'update']);
            Route::delete('/knowledge/{document}', [KnowledgeController::class, 'destroy']);
            Route::post('/knowledge/{document}/reindex', [KnowledgeController::class, 'reindex']);
        });

        // Anomalies. "Not an issue" is a first-class action, not a hidden dismiss:
        // a detector nobody can push back on is ignored within a week.
        Route::get('/projects/{project}/anomalies', [AnomalyController::class, 'index']);
        Route::middleware('can.do:anomalies.acknowledge')->group(function (): void {
            Route::put('/anomalies/{anomaly}/acknowledge', [AnomalyController::class, 'acknowledge']);
            Route::put('/anomalies/{anomaly}/resolve', [AnomalyController::class, 'resolve']);
            Route::put('/anomalies/{anomaly}/false-positive', [AnomalyController::class, 'falsePositive']);
        });

        // Pipelines. Bound by iid within the project: that is the number the
        // provider shows and the number users quote to each other.
        Route::get('/projects/{project}/pipelines', [PipelineController::class, 'index']);
        Route::get('/projects/{project}/pipelines/{iid}', [PipelineController::class, 'show']);
        Route::get('/projects/{project}/analyses', [PipelineController::class, 'analyses']);
        Route::get('/projects/{project}/signatures', [PipelineController::class, 'signatures']);

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

        /*
        |------------------------------------------------------------------
        | AI providers — tested before they are saved, like integrations
        |------------------------------------------------------------------
        */
        Route::middleware('can.do:ai.manage')->group(function (): void {
            Route::post('/ai-providers/test', [AiProviderController::class, 'testUnsaved']);

            Route::get('/ai-providers', [AiProviderController::class, 'index']);
            Route::post('/ai-providers', [AiProviderController::class, 'store']);
            Route::put('/ai-providers/{provider}', [AiProviderController::class, 'update']);
            Route::delete('/ai-providers/{provider}', [AiProviderController::class, 'destroy']);
            Route::post('/ai-providers/{provider}/test', [AiProviderController::class, 'test']);
        });

        // By UUID, for a link that carries no project slug.
        Route::get('/pipelines/{pipeline}', [PipelineController::class, 'showByUuid']);

        Route::get('/failures/{failure}/timeline', [FailureController::class, 'timeline']);
        Route::get('/failures/{failure}/recommendations', [FailureController::class, 'recommendations']);

        /*
        |------------------------------------------------------------------
        | Remediation — roadmaps/18
        |------------------------------------------------------------------
        | The only routes in the application that change a user's repository.
        | Reading is open to anyone who can see the project; requesting needs
        | `remediation.request`; approving needs `remediation.approve` AND a
        | different person from the requester (enforced in the controller, since
        | it depends on the row rather than the role).
        */
        Route::get('/projects/{project}/remediations', [RemediationController::class, 'index']);
        Route::get('/remediations/{remediation}', [RemediationController::class, 'show']);
        Route::get('/remediations/{remediation}/dry-run', [RemediationController::class, 'dryRun']);

        Route::middleware('can.do:remediation.request')->group(function (): void {
            Route::post('/recommendations/{recommendation}/accept', [RemediationController::class, 'accept']);
        });

        Route::middleware('can.do:remediation.approve')->group(function (): void {
            Route::post('/remediations/{remediation}/approve', [RemediationController::class, 'approve']);
            Route::post('/remediations/{remediation}/reject', [RemediationController::class, 'reject']);
        });

        // Owner only: these decide what runs without asking again.
        Route::middleware('can.do:policies.edit')->group(function (): void {
            Route::get('/workspace/policies', [RemediationPolicyController::class, 'index']);
            Route::put('/workspace/policies/{actionType}', [RemediationPolicyController::class, 'update']);
        });

        // The signature catalogue. Confirming a resolution here is the highest-
        // leverage write in the app: every future occurrence short-circuits to
        // it with no model call at all.
        Route::get('/signatures/{signature}', [SignatureController::class, 'show']);
        Route::middleware('can.do:failures.resolve')->group(function (): void {
            Route::put('/signatures/{signature}/resolution', [SignatureController::class, 'update']);
        });

        /*
        |------------------------------------------------------------------
        | Members, workspace settings and AI spend
        |------------------------------------------------------------------
        */
        Route::get('/members', [MemberController::class, 'index']);
        Route::get('/members/roles', [MemberController::class, 'roles']);

        Route::middleware('can.do:team.manage')->group(function (): void {
            Route::put('/members/{user}', [MemberController::class, 'update']);
            Route::delete('/members/{user}', [MemberController::class, 'destroy']);
            Route::get('/invitations', [MemberController::class, 'invitations']);
            Route::post('/invitations', [MemberController::class, 'invite']);
            Route::delete('/invitations/{invitation}', [MemberController::class, 'revoke']);
        });

        Route::get('/workspace/settings', [WorkspaceSettingsController::class, 'show']);
        Route::get('/workspace/usage', [WorkspaceSettingsController::class, 'usage']);
        Route::middleware('can.do:team.manage')->group(function (): void {
            Route::put('/workspace/settings', [WorkspaceSettingsController::class, 'update']);
        });

        // Where the ML classifier's training set comes from.
        Route::middleware('can.do:feedback.submit')->group(function (): void {
            Route::post('/analyses/{analysis}/feedback', [AnalysisController::class, 'feedback']);
        });
    });
});
