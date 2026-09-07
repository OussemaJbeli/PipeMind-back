<?php

namespace App\Providers;

use App\Models\Failure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Surfaces N+1 queries during development rather than in production
        // once a project has thousands of pipelines.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configurePasswordReset();
    }

    /**
     * Points the reset link at the SPA, not the API.
     *
     * The default URL is an API route that renders JSON, so a user clicking the
     * link in their inbox would be shown a raw payload instead of a form.
     */
    private function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token) => sprintf(
            '%s/reset-password/%s?email=%s',
            rtrim((string) config('pipemind.frontend_url'), '/'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)
            ->by($r->user()?->id ?: $r->ip()));

        // Tight: this is the credential-stuffing surface.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)
            ->by($r->input('email').'|'.$r->ip()));

        // Generous: a busy pipeline fires many events in a burst, and dropping
        // them means lost state that only reconciliation can recover.
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)
            ->by($r->route('integration') ?? $r->ip()));

        // Each analysis costs money and provider quota.
        RateLimiter::for('analysis', fn (Request $r) => Limit::perHour(20)
            ->by('team:'.(currentTeamId() ?? $r->ip())));

        RateLimiter::for('assistant', fn (Request $r) => Limit::perHour(30)
            ->by($r->user()?->id ?: $r->ip()));

        // The queue-side limiter for AnalyzeFailure. Separate from 'analysis'
        // above, which throttles the HTTP trigger: a burst of auto-analyses from
        // webhook ingestion never passes through a request at all.
        //
        // Per-team, so one noisy workspace cannot starve another.
        RateLimiter::for('ai-analysis', function (object $job) {
            $teamId = Failure::withoutGlobalScopes()
                ->where('id', $job->failureId ?? 0)
                ->value('team_id');

            return Limit::perMinute(10)->by('ai-analysis:'.($teamId ?? 'unknown'));
        });
    }
}
