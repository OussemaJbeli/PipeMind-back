<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTeamRole;
use App\Http\Middleware\ResolveTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth for the browser; bearer tokens for CLI and CI.
        $middleware->statefulApi();

        $middleware->alias([
            'team' => ResolveTeam::class,
            'team.role' => EnsureTeamRole::class,
            'can.do' => EnsurePermission::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Consistent JSON shape for the API. The frontend renders `message`
        // directly and switches behaviour on `error_code`.
        $exceptions->render(function (AuthenticationException $e, $request) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => 'Unauthenticated.',
                    'error_code' => 'UNAUTHENTICATED',
                    'retryable' => false,
                ], 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                    'error_code' => 'INSUFFICIENT_ROLE',
                    'retryable' => false,
                ], 403)
                : null;
        });
    })->create();
