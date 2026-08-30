<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Denormalised project counters: the workspace grid cannot run 4xN aggregates.
Schedule::command('pipemind:refresh-project-stats')->everyFiveMinutes()->withoutOverlapping();

// Rollups that back every chart on the project board.
Schedule::command('pipemind:rollup-metrics')->hourlyAt(5)->withoutOverlapping();

// "Normal" per job, per branch — successful runs only, minimum 10 samples.
Schedule::command('pipemind:compute-baselines')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('pipemind:detect-anomalies')->everyTenMinutes()->withoutOverlapping();

// Webhooks WILL be lost — a restart, a network blip, a provider outage.
// Without reconciliation the UI shows spinners forever.
Schedule::command('pipemind:reconcile-pipelines')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('pipemind:prune')->dailyAt('04:00');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
