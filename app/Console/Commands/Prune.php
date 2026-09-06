<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AiRequest;
use App\Models\CommitChange;
use App\Models\PipelineEvent;
use Illuminate\Console\Command;

class Prune extends Command
{
    protected $signature = 'pipemind:prune {--dry-run}';

    protected $description = 'Prune records past their retention window';

    public function handle(): int
    {
        $retention = config('pipemind.retention');
        $dryRun = (bool) $this->option('dry-run');

        // failures, analyses and embeddings are never pruned: they ARE the
        // knowledge base, and the whole product gets better as they accumulate.
        $targets = [
            'pipeline_events' => [PipelineEvent::class, 'received_at', $retention['pipeline_events_days']],
            'commit_changes' => [CommitChange::class, 'created_at', $retention['commit_changes_days']],
            'activity_logs' => [ActivityLog::class, 'created_at', $retention['activity_logs_days']],
            'ai_requests' => [AiRequest::class, 'created_at', $retention['ai_requests_days']],
        ];

        foreach ($targets as $label => [$model, $column, $days]) {
            $query = $model::withoutGlobalScopes()->where($column, '<', now()->subDays((int) $days));
            $count = $query->count();

            if (! $dryRun && $count > 0) {
                $query->limit(50_000)->delete();
            }

            $this->line(sprintf('%-18s %s %d row(s) older than %d days', $label, $dryRun ? 'would prune' : 'pruned', $count, $days));
        }

        return self::SUCCESS;
    }
}
