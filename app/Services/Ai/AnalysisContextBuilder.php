<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\Ai\NoLocalProviderConfigured;
use App\Models\AiProvider;
use App\Models\Failure;
use App\Models\JobBaseline;
use App\Models\Pipeline;
use Illuminate\Support\Str;

/**
 * Assembles everything the model needs and nothing it does not.
 *
 * The quality of an analysis is decided here rather than in the prompt: a
 * perfectly worded prompt over missing context still produces a guess.
 */
class AnalysisContextBuilder
{
    /** @return array<string,mixed> */
    public function build(Failure $failure): array
    {
        $failure->loadMissing([
            'project.integration', 'project.team', 'project.aiProvider',
            'pipeline.changes', 'job.log', 'signature',
        ]);

        $project = $failure->project;
        $pipeline = $failure->pipeline;
        $job = $failure->job;
        $log = $job?->log;

        return [
            'contract_version' => config('pipemind.ai.contract_version'),
            'team_id' => $failure->team_id,

            'failure' => [
                'uuid' => $failure->uuid,
                'occurrence_index' => $failure->occurrence_index,
                'is_flaky' => $failure->is_flaky,
                'signature_hash' => $failure->signature?->hash,
                // Folded into the signature hash at ingestion; retrieval needs it
                // to compose the same embedding input.
                'ecosystem' => $failure->ecosystem,
            ],

            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'tech_stack' => $project->tech_stack ?? [],
                'provider' => $project->integration?->provider ?? 'unknown',
                'default_branch' => $project->default_branch,
            ],

            'pipeline' => [
                'iid' => $pipeline?->iid,
                'ref' => $pipeline?->ref,
                'source' => $pipeline?->source,
                'commit_sha' => $pipeline?->commit_short_sha,
                'commit_message' => Str::limit((string) $pipeline?->commit_message, 200),
                'duration_seconds' => $pipeline?->duration_seconds,

                // The single highest-signal fact available to the model: a branch
                // that was green until this commit narrows the search enormously.
                'previous_status' => $pipeline ? $this->previousStatus($pipeline) : null,

                'changed_files' => $pipeline ? $this->changedFiles($pipeline) : [],
            ],

            'job' => [
                'name' => $job?->name,
                'stage_name' => $job?->stage_name,
                'status' => $job?->status,
                'exit_code' => $failure->exit_code ?? $job?->exit_code,
                'duration_seconds' => $job?->duration_seconds,
                'failure_reason' => $job?->failure_reason,
                'baseline_duration_seconds' => $this->baseline($failure),
            ],

            // Already redacted by /v1/logs/process during ingestion. The AI
            // service re-checks anyway.
            'log_excerpt' => $log?->excerpt ?? $failure->error_message ?? '',
            'error_block' => $log?->error_block,
            'stack_trace' => $log?->stack_trace,

            'use_rag' => true,
            'use_llm' => true,
            'llm_override' => $this->providerOverride($failure),
        ];
    }

    /** Status of the last pipeline on this ref before this one. */
    protected function previousStatus(Pipeline $pipeline): ?string
    {
        return $pipeline->project->pipelines()
            ->where('ref', $pipeline->ref)
            ->where('id', '<', $pipeline->id)
            ->whereIn('status', ['success', 'failed'])
            ->reorder('id', 'desc')
            ->value('status');
    }

    /**
     * Config and dependency changes first — they explain far more failures than
     * source edits, and only the first 20 make it into the prompt.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function changedFiles(Pipeline $pipeline): array
    {
        return $pipeline->changes
            ->sortByDesc(fn ($change) => ($change->is_config ? 2 : 0) + ($change->is_dependency ? 2 : 0))
            ->take(20)
            ->map(fn ($change) => [
                'path' => $change->file_path,
                'change_type' => $change->change_type,
                'additions' => $change->additions,
                'deletions' => $change->deletions,
                'is_config' => (bool) $change->is_config,
                'is_dependency' => (bool) $change->is_dependency,
            ])
            ->values()
            ->all();
    }

    /**
     * "92 seconds" means nothing. "92 seconds against a 41 second baseline"
     * means a timeout or a hang.
     */
    protected function baseline(Failure $failure): ?float
    {
        if (! $failure->job_name) {
            return null;
        }

        $value = JobBaseline::query()
            ->where('project_id', $failure->project_id)
            ->where('job_name', $failure->job_name)
            ->whereIn('ref', array_filter([$failure->pipeline?->ref, '*']))
            ->orderByRaw("ref = '*'")     // an exact-ref baseline beats the wildcard
            ->value('mean_duration_seconds');

        return $value === null ? null : (float) $value;
    }

    /**
     * The team's configured provider and key, sent per request so the AI service
     * stores no team's credentials at rest.
     *
     * `privacy_mode = local_only` is enforced here, in Laravel, not in the AI
     * service: the boundary deciding whether a team's logs may reach a third
     * party belongs on the side that owns the team record. With no local
     * provider configured the analysis fails loudly rather than quietly
     * falling back to the cloud.
     *
     * @return array<string,mixed>|null
     */
    protected function providerOverride(Failure $failure): ?array
    {
        $team = $failure->project->team;
        $provider = $failure->project->aiProvider ?? $team?->defaultAiProvider();

        if ($team?->isLocalOnly() && ! $provider?->is_local) {
            $provider = $team->aiProviders()
                ->where('is_local', true)
                ->where('status', 'active')
                ->first();

            if (! $provider) {
                throw new NoLocalProviderConfigured(
                    "{$team->name} is set to local-only, but no local AI provider is configured. "
                    .'Add an Ollama provider under Workspace → AI Providers, or change the privacy mode.'
                );
            }
        }

        return $provider instanceof AiProvider ? $provider->toOverride() : null;
    }
}
