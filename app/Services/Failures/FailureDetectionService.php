<?php

declare(strict_types=1);

namespace App\Services\Failures;

use App\Enums\FailureCategory;
use App\Enums\Severity;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FailureDetectionService
{
    /** Patterns that mean "retrying might just work". */
    private const TRANSIENT = [
        '/ETIMEDOUT|ECONNRESET|EAI_AGAIN|connection reset by peer/i',
        '/temporary failure in name resolution|could not resolve host/i',
        '/429 too many requests|rate limit exceeded/i',
        '/502 bad gateway|503 service unavailable|504 gateway timeout/i',
        '/runner system failure|job was cancelled by the system/i',
        '/the runner has (?:disappeared|crashed)/i',
    ];

    /** @param  array<string,mixed>  $context */
    public function detect(Pipeline $pipeline, ?PipelineJob $job, array $context = []): ?Failure
    {
        $project = $pipeline->project;
        $teamId = $project->team_id;

        $errorMessage = $context['error_message'] ?? $this->fallbackMessage($pipeline, $job);
        $signature = $this->resolveSignature($teamId, $context, $errorMessage);

        $existing = Failure::withoutGlobalScopes()
            ->where('pipeline_id', $pipeline->id)
            ->when($job, fn ($q) => $q->where('job_id', $job->id))
            ->when(! $job, fn ($q) => $q->whereNull('job_id'))
            ->first();

        // Idempotent: a redelivered event must not create a second failure.
        if ($existing) {
            return $existing;
        }

        $occurrenceIndex = $signature
            ? Failure::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('signature_id', $signature->id)
                ->count() + 1
            : 1;

        $isFlaky = $this->isFlaky($pipeline, $job, $signature?->id);
        $isTransient = $this->isTransient($errorMessage, $job?->failure_reason);

        $severity = $this->severity($pipeline, $job, $occurrenceIndex, $isFlaky, $isTransient);

        return DB::transaction(function () use (
            $pipeline, $job, $project, $teamId, $signature, $errorMessage,
            $context, $occurrenceIndex, $isFlaky, $isTransient, $severity,
        ) {
            $failure = Failure::create([
                'team_id' => $teamId,
                'project_id' => $project->id,
                'pipeline_id' => $pipeline->id,
                'job_id' => $job?->id,
                'signature_id' => $signature?->id,
                'status' => 'detected',
                'severity' => $severity->value,
                'category' => $context['category'] ?? $signature?->category?->value ?? FailureCategory::UNKNOWN->value,
                'subcategory' => $context['subcategory'] ?? $signature?->subcategory,
                'stage_name' => $job?->stage_name,
                'job_name' => $job?->name,
                'error_message' => $errorMessage ? Str::limit($errorMessage, 2000) : null,
                'exit_code' => $context['exit_code'] ?? $job?->exit_code,
                // Part of the signature hash, so retrieval must be able to
                // reproduce it when composing the embedding input.
                'ecosystem' => $context['ecosystem'] ?? null,
                'is_flaky' => $isFlaky,
                'is_transient' => $isTransient,
                'occurrence_index' => $occurrenceIndex,
                'failed_at' => $pipeline->finished_at ?? now(),
            ]);

            $signature?->forceFill([
                'occurrence_count' => $signature->occurrence_count + 1,
                'last_seen_at' => $failure->failed_at,
            ])->save();

            return $failure;
        });
    }

    /** @param  array<string,mixed>  $context */
    protected function resolveSignature(int $teamId, array $context, ?string $errorMessage): ?FailureSignature
    {
        $hash = $context['signature_hash'] ?? null;

        if (! $hash || ! $errorMessage) {
            return null;
        }

        $signature = FailureSignature::withoutGlobalScopes()->firstOrCreate(
            ['team_id' => $teamId, 'hash' => $hash],
            [
                'normalized_error' => $context['normalized_error'] ?? Str::lower($errorMessage),
                'sample_error' => Str::limit($errorMessage, 2000),
                'category' => $context['category'] ?? FailureCategory::UNKNOWN->value,
                'subcategory' => $context['subcategory'] ?? null,
                'occurrence_count' => 0,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        // A signature first seen before the classifier could name it would stay
        // UNKNOWN forever otherwise — and its category is what powers the
        // failure-history catalogue and global search. Upgrade it, but never
        // downgrade: a later low-signal occurrence must not erase a good label.
        $incoming = $context['category'] ?? null;

        if ($incoming
            && $incoming !== FailureCategory::UNKNOWN->value
            && $signature->category === FailureCategory::UNKNOWN) {
            $signature->forceFill([
                'category' => $incoming,
                'subcategory' => $context['subcategory'] ?? $signature->subcategory,
            ])->save();
        }

        return $signature;
    }

    /**
     * Severity is a deterministic rule, not an AI decision: it has to be stable
     * and explainable before any model has run.
     */
    protected function severity(
        Pipeline $pipeline,
        ?PipelineJob $job,
        int $occurrenceIndex,
        bool $isFlaky,
        bool $isTransient,
    ): Severity {
        // A flake or a known-transient failure is noise, whatever it broke.
        if ($isFlaky || $isTransient) {
            return Severity::LOW;
        }

        if ($job?->allow_failure) {
            return Severity::LOW;
        }

        $isDefaultBranch = $pipeline->ref === $pipeline->project->default_branch;
        $isDeploy = Str::contains(Str::lower((string) $job?->stage_name), ['deploy', 'release', 'publish']);

        if ($isDefaultBranch && $isDeploy) {
            return Severity::CRITICAL;
        }

        if ($isDefaultBranch) {
            return Severity::HIGH;
        }

        // Repeatedly breaking the same way is worse than breaking once, even on
        // a feature branch — it means nobody is fixing it.
        if ($occurrenceIndex >= 3) {
            return Severity::HIGH;
        }

        return Severity::MEDIUM;
    }

    /**
     * The same signature failing on a commit that previously passed is a flake,
     * not a regression. Analysing it repeatedly burns budget for zero insight.
     */
    protected function isFlaky(Pipeline $pipeline, ?PipelineJob $job, ?int $signatureId): bool
    {
        if (! $job || ! $pipeline->commit_sha) {
            return false;
        }

        return Pipeline::withoutGlobalScopes()
            ->where('project_id', $pipeline->project_id)
            ->where('commit_sha', $pipeline->commit_sha)
            ->where('id', '!=', $pipeline->id)
            ->where('status', 'success')
            ->exists();
    }

    protected function isTransient(?string $errorMessage, ?string $failureReason): bool
    {
        if ($failureReason && Str::contains($failureReason, ['runner_system_failure', 'scheduler_failure', 'stuck_or_timeout_failure'])) {
            return true;
        }

        if (! $errorMessage) {
            return false;
        }

        foreach (self::TRANSIENT as $pattern) {
            if (preg_match($pattern, $errorMessage)) {
                return true;
            }
        }

        return false;
    }

    protected function fallbackMessage(Pipeline $pipeline, ?PipelineJob $job): ?string
    {
        if ($job?->failure_reason) {
            return "Job failed: {$job->failure_reason}";
        }

        if ($job) {
            return "Job '{$job->name}' failed in stage '{$job->stage_name}'.";
        }

        return "Pipeline #{$pipeline->iid} failed without a failing job (infrastructure or configuration).";
    }
}
