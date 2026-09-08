<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Enums\ActionType;
use App\Exceptions\Remediation\PatchDoesNotApply;
use App\Exceptions\Remediation\RemediationForbidden;
use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;
use App\Services\Remediation\PatchApplier;
use Illuminate\Support\Str;

/**
 * Turns a patch into a pull request. The only executor that writes code.
 *
 * Three guarantees, each enforced here rather than trusted:
 *
 * 1. **Never the default branch.** The change lands on a new branch and is
 *    proposed, because the value of a suggested fix is that a person still reads
 *    it. Committing to `main` would make PipeMind the author of unreviewed code.
 * 2. **Only files the recommendation declared.** A patch that touches a path the
 *    analysis never mentioned is refused. This mirrors `validate_patch()` in the
 *    AI service, and it is defence against the model, not against the user.
 * 3. **Exact context or nothing.** `PatchApplier` refuses a near-miss, so a file
 *    edited since the analysis produces a clean failure instead of a silent
 *    mangle.
 */
class CreateMergeRequestExecutor implements RemediationExecutor
{
    use ResolvesProvider;

    public function __construct(private readonly PatchApplier $applier) {}

    public static function actionType(): string
    {
        return ActionType::CREATE_MERGE_REQUEST->value;
    }

    public function canExecute(Remediation $remediation): bool
    {
        return filled($remediation->recommendation?->patch)
            && filled($remediation->failure?->pipeline?->commit_sha);
    }

    public function blockedReason(Remediation $remediation): string
    {
        return filled($remediation->recommendation?->patch)
            ? 'The commit this failure came from is unknown, so there is nothing to branch from.'
            : 'This recommendation has no patch to apply.';
    }

    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult
    {
        $project = $remediation->project;
        $recommendation = $remediation->recommendation;
        $pipeline = $remediation->failure->pipeline;

        [$provider, $integration] = $this->provider($remediation);

        if (! $provider->supportsMergeRequests()) {
            throw new RemediationForbidden(
                "{$integration->provider} does not host a repository, so a merge request cannot be opened."
            );
        }

        $base = $pipeline->ref;

        // Guarantee 1. The pipeline's own branch is the target; if the failure
        // happened on the default branch the proposal still goes to a new
        // branch, and the default branch is never a commit target.
        $head = sprintf('pipemind/%s-%s',
            Str::slug(Str::limit($recommendation->title, 40, '')) ?: 'fix',
            substr($remediation->uuid, 0, 8),
        );

        $this->assertDeclaredFilesOnly($recommendation->patch, $recommendation->affected_files ?? []);

        $updated = $this->applier->applyAll(
            $recommendation->patch,
            fn (string $path) => $provider->fetchFileContents($integration, $project, $path, $pipeline->commit_sha),
        );

        if ($updated === []) {
            throw new PatchDoesNotApply('The patch changed nothing.');
        }

        if ($dryRun) {
            return ExecutionResult::dryRun(
                sprintf('Would open a merge request from `%s` into `%s`, changing %s.',
                    $head, $base, implode(', ', array_keys($updated))),
                ['branch' => $head, 'base' => $base, 'files' => array_keys($updated)],
            );
        }

        $provider->createBranch($integration, $project, $head, $pipeline->commit_sha);
        $provider->commitFiles($integration, $project, $head, $updated, $this->commitMessage($remediation));

        $response = $provider->openMergeRequest(
            $integration, $project, $head, $base,
            Str::limit($recommendation->title, 100),
            $this->description($remediation, array_keys($updated)),
        );

        return ExecutionResult::make(
            summary: sprintf('Opened a merge request from `%s` into `%s`.', $head, $base),
            url: $response['html_url'] ?? $response['web_url'] ?? null,
            externalId: isset($response['number'])
                ? (string) $response['number']
                : (isset($response['iid']) ? (string) $response['iid'] : null),
            meta: ['branch' => $head, 'base' => $base, 'files' => array_keys($updated)],
        );
    }

    /**
     * Guarantee 2. Refuses a patch reaching outside the files the analysis said
     * it would change.
     *
     * @param  array<int,string>  $declared
     */
    private function assertDeclaredFilesOnly(string $patch, array $declared): void
    {
        if ($declared === []) {
            throw new PatchDoesNotApply(
                'The recommendation lists no affected files, so the patch cannot be checked against it.'
            );
        }

        $normalise = fn (string $path) => ltrim(preg_replace('#^[ab]/#', '', $path) ?? $path, '/');
        $allowed = array_map($normalise, $declared);
        $touched = array_map($normalise, $this->applier->paths($patch));

        if ($extra = array_diff($touched, $allowed)) {
            throw new PatchDoesNotApply(sprintf(
                'The patch modifies %s, which the analysis never mentioned.',
                implode(', ', array_map(fn ($p) => "`{$p}`", $extra)),
            ));
        }
    }

    private function commitMessage(Remediation $remediation): string
    {
        return sprintf("%s\n\nProposed by PipeMind for pipeline #%s.\nRemediation %s.",
            Str::limit($remediation->recommendation->title, 68),
            $remediation->failure->pipeline->iid ?? '?',
            $remediation->uuid,
        );
    }

    /** @param array<int,string> $files */
    private function description(Remediation $remediation, array $files): string
    {
        $failure = $remediation->failure;
        $recommendation = $remediation->recommendation;
        $approver = $remediation->approver?->name;

        $lines = [
            '### Why this change',
            '',
            $recommendation->description ?: $recommendation->title,
            '',
            '### The failure it fixes',
            '',
            sprintf('`%s` in job **%s**, pipeline #%s on `%s`.',
                $failure->error_message ?: 'Pipeline failure',
                $failure->job_name ?? 'unknown',
                $failure->pipeline->iid ?? '?',
                $failure->pipeline->ref ?? 'unknown',
            ),
            '',
        ];

        if ($recommendation->rationale) {
            $lines = [...$lines, '### Evidence', '', $recommendation->rationale, ''];
        }

        return implode("\n", [...$lines,
            '### Files changed',
            '',
            ...array_map(fn ($path) => "- `{$path}`", $files),
            '',
            '---',
            '',
            sprintf('Generated by PipeMind · confidence %s · %s.',
                $recommendation->confidence !== null
                    ? round($recommendation->confidence * 100).'%'
                    : 'not stated',
                $approver ? "approved by {$approver}" : 'applied automatically under policy',
            ),
            '',
            '**Review before merging.** PipeMind proposes changes; it does not verify them.',
        ]);
    }
}
