<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\ProviderRegistry;
use App\Models\CommitChange;
use App\Models\Pipeline;
use App\Services\Ingestion\FileSignalClassifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCommitChanges implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $pipelineId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(ProviderRegistry $registry, FileSignalClassifier $signals): void
    {
        $pipeline = Pipeline::withoutGlobalScopes()
            ->with('project.integration.team')
            ->find($this->pipelineId);

        $integration = $pipeline?->project?->integration;

        if (! $pipeline?->commit_sha || ! $integration?->team) {
            return;
        }

        withTeam($integration->team, function () use ($pipeline, $integration, $registry, $signals): void {
            $changes = $registry->for($integration)
                ->fetchCommitChanges($integration, $pipeline->project, $pipeline->commit_sha);

            foreach ($changes as $change) {
                CommitChange::updateOrCreate(
                    ['pipeline_id' => $pipeline->id, 'file_path' => $change['file_path']],
                    [
                        'project_id' => $pipeline->project_id,
                        'change_type' => $change['change_type'],
                        'old_path' => $change['old_path'] ?? null,
                        'additions' => $change['additions'] ?? 0,
                        'deletions' => $change['deletions'] ?? 0,
                        'language' => $signals->language($change['file_path']),
                        // The two highest-signal features for root-cause correlation.
                        'is_config' => $signals->isConfig($change['file_path']),
                        'is_dependency' => $signals->isDependency($change['file_path']),
                    ],
                );
            }
        });
    }
}
