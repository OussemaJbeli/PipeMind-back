<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Integrations\DTO\RemoteProject;
use App\Integrations\ProviderRegistry;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Support\Str;

class ProjectImporter
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly WebhookRegistrar $registrar,
    ) {}

    /**
     * Import selected repositories and register their webhooks.
     *
     * Reports per-repository status rather than a single boolean: a project
     * imported without a working hook looks fine and does nothing, which is the
     * worst possible failure mode.
     *
     * @param  array<int,string>  $externalIds
     * @return array<int,array<string,mixed>>
     */
    public function import(Integration $integration, array $externalIds): array
    {
        $integration->loadMissing('team');

        $adapter = $this->registry->for($integration);

        $remote = collect($adapter->remoteProjects($integration))
            ->keyBy(fn (RemoteProject $p) => $p->externalId);

        $results = [];

        foreach ($externalIds as $externalId) {
            $spec = $remote->get($externalId);

            if (! $spec) {
                $results[] = [
                    'external_id' => $externalId,
                    'name' => $externalId,
                    'imported' => false,
                    'webhook' => false,
                    'error' => 'Repository not found or no longer accessible.',
                ];

                continue;
            }

            $project = $this->upsertProject($integration, $spec);
            $webhook = $this->registrar->register($integration, $project);

            $results[] = [
                'external_id' => $externalId,
                'name' => $project->name,
                'slug' => $project->slug,
                'uuid' => $project->uuid,
                'imported' => true,
                'webhook' => $webhook['ok'],
                // A permission error here is the single most common import
                // problem, so surface the provider's own message verbatim.
                'error' => $webhook['error'],
            ];
        }

        return $results;
    }

    protected function upsertProject(Integration $integration, RemoteProject $spec): Project
    {
        $existing = Project::where('integration_id', $integration->id)
            ->where('external_id', $spec->externalId)
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $spec->name,
                'external_path' => $spec->path,
                'repository_url' => $spec->repositoryUrl,
                'web_url' => $spec->webUrl,
                'default_branch' => $spec->defaultBranch,
                'is_active' => true,
            ]);

            return $existing;
        }

        return Project::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'name' => $spec->name,
            'slug' => $this->uniqueSlug($integration->team_id, $spec->name),
            'description' => $spec->description,
            'external_id' => $spec->externalId,
            'external_path' => $spec->path,
            'repository_url' => $spec->repositoryUrl,
            'web_url' => $spec->webUrl,
            'default_branch' => $spec->defaultBranch,
            'icon' => 'code',
            'color' => $this->colorFor($spec->name),
            'tech_stack' => [],
            'created_by' => auth()->id(),
        ]);
    }

    protected function uniqueSlug(int $teamId, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while (Project::where('team_id', $teamId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** Deterministic colour so a project looks the same on every import. */
    protected function colorFor(string $name): string
    {
        $palette = ['#6366F1', '#42B883', '#61DAFB', '#8B5CF6', '#F97316', '#14B8A6', '#EC4899'];

        return $palette[abs(crc32($name)) % count($palette)];
    }
}
