<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health — no auth, for uptime checks
|--------------------------------------------------------------------------
*/
Route::get('/health', fn () => [
    'status' => 'ok',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]);

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

        // Workspace home (ui/workspace.png)
        Route::get('/workspace/summary', [WorkspaceController::class, 'summary']);
        Route::get('/workspace/projects', [WorkspaceController::class, 'projects']);
        Route::get('/workspace/activity', [WorkspaceController::class, 'activity']);

        // Projects. Bound by slug (see Project::getRouteKeyName).
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::get('/projects/{project}/overview', [ProjectController::class, 'overview']);
        Route::get('/projects/{project}/insights', [ProjectController::class, 'insights']);
    });
});
