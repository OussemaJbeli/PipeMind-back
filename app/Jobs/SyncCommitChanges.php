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
    /** ~40 KB: enough for any hand-written change, far short of a lockfile. */
    private const MAX_PATCH_BYTES = 40_000;

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
                        'patch' => $this->boundedPatch($change['patch'] ?? null),
                        'patch_truncated' => $this->wouldTruncate($change['patch'] ?? null),
                        'is_config' => $signals->isConfig($change['file_path']),
                        'is_dependency' => $signals->isDependency($change['file_path']),
                    ],
                );
            }
        });
    }

    /**
     * A lockfile diff runs to megabytes and carries no diagnostic value, while
     * the hunk that actually broke the build is almost always small. Keeping the
     * head of the diff bounds both the row size and the prompt built from it.
     */
    protected function boundedPatch(?string $patch): ?string
    {
        if ($patch === null || $patch === '') {
            return null;
        }

        return strlen($patch) > self::MAX_PATCH_BYTES
            ? substr($patch, 0, self::MAX_PATCH_BYTES)
            : $patch;
    }

    protected function wouldTruncate(?string $patch): bool
    {
        return $patch !== null && strlen($patch) > self::MAX_PATCH_BYTES;
    }
}
