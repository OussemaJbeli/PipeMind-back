<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncPipeline;
use App\Models\Integration;
use App\Models\Pipeline;
use Illuminate\Console\Command;

/**
 * Webhooks WILL be lost - a restart, a network blip, a provider outage.
 * Without reconciliation the UI shows spinners forever, so this is not optional.
 */
class ReconcilePipelines extends Command
{
    protected $signature = 'pipemind:reconcile-pipelines {--minutes=10 : Consider a pipeline stale after this long}';

    protected $description = 'Refetch pipelines stuck in a non-terminal state';

    public function handle(): int
    {
        $staleAfter = now()->subMinutes((int) $this->option('minutes'));

        $stuck = Pipeline::withoutGlobalScopes()
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', $staleAfter)
            ->limit(200)
            ->get();

        foreach ($stuck as $pipeline) {
            // Nothing runs for six hours. Stop refetching and call it a timeout.
            if ($pipeline->updated_at->lt(now()->subHours(6))) {
                $pipeline->update(['status' => 'timeout', 'finished_at' => $pipeline->updated_at]);
                $this->warn("Pipeline #{$pipeline->iid} marked timeout after 6h.");

                continue;
            }

            SyncPipeline::dispatch($pipeline->id);
        }

        $this->info("Queued {$stuck->count()} pipeline(s) for reconciliation.");

        // A dead webhook looks like silence, which is indistinguishable from
        // "nothing happened" unless we say so explicitly.
        $silent = Integration::withoutGlobalScopes()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('last_event_at')->orWhere('last_event_at', '<', now()->subDay()))
            ->whereHas('projects', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($silent as $integration) {
            $integration->update([
                'status' => 'error',
                'last_error' => 'No events received in the last 24 hours. The webhook may no longer be registered.',
            ]);

            $this->warn("Integration {$integration->name} has received no events in 24h.");
        }

        return self::SUCCESS;
    }
}
