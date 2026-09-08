<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Enums\ActionType;
use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;
use Illuminate\Support\Str;

class CreateIssueExecutor implements RemediationExecutor
{
    use ResolvesProvider;

    public static function actionType(): string
    {
        return ActionType::CREATE_ISSUE->value;
    }

    public function canExecute(Remediation $remediation): bool
    {
        // Filing the same issue twice for one failure is the classic automation
        // failure mode: the tracker fills with duplicates and people stop reading it.
        return ! Remediation::withoutGlobalScopes()
            ->where('failure_id', $remediation->failure_id)
            ->where('action_type', ActionType::CREATE_ISSUE->value)
            ->where('status', 'succeeded')
            ->whereKeyNot($remediation->getKey())
            ->exists();
    }

    public function blockedReason(Remediation $remediation): string
    {
        return 'An issue has already been filed for this failure.';
    }

    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult
    {
        $failure = $remediation->failure;
        $title = Str::limit(
            sprintf('[%s] %s', $failure->category ?? 'CI', $failure->error_message ?: 'Pipeline failure'),
            120,
        );

        if ($dryRun) {
            return ExecutionResult::dryRun("Would open an issue titled \"{$title}\".", ['title' => $title]);
        }

        [$provider, $integration] = $this->provider($remediation);
        $response = $provider->createIssue($integration, $remediation->project, $title, $this->body($remediation));

        return ExecutionResult::make(
            summary: 'Opened an issue.',
            url: $response['html_url'] ?? $response['web_url'] ?? null,
            externalId: isset($response['number'])
                ? (string) $response['number']
                : (isset($response['iid']) ? (string) $response['iid'] : null),
        );
    }

    /**
     * The analysis, as the issue body.
     *
     * Written so the issue is useful on its own: someone reading it in the
     * tracker three days later should not have to open PipeMind to know what
     * broke, and the link back is there when they want the evidence.
     */
    private function body(Remediation $remediation): string
    {
        $failure = $remediation->failure;
        $analysis = $failure->latestAnalysis;
        $recommendation = $remediation->recommendation;

        $lines = [
            '## What failed',
            '',
            sprintf('`%s` in job **%s** on `%s`.',
                $failure->error_message ?: 'Pipeline failure',
                $failure->job_name ?? 'unknown',
                $failure->pipeline->ref ?? 'unknown',
            ),
            '',
        ];

        if ($analysis?->root_cause) {
            $lines = [...$lines, '## Root cause', '', $analysis->root_cause, ''];
        }

        if ($recommendation) {
            $lines = [...$lines, '## Suggested fix', '', "**{$recommendation->title}**", ''];

            if ($recommendation->description) {
                $lines = [...$lines, $recommendation->description, ''];
            }

            if ($recommendation->patch) {
                $lines = [...$lines, '```diff', $recommendation->patch, '```', ''];
            }
        }

        $lines = [...$lines,
            '---',
            '',
            sprintf('Filed by PipeMind from pipeline #%s. Confidence %s.',
                $failure->pipeline->iid ?? '?',
                $recommendation?->confidence !== null
                    ? round($recommendation->confidence * 100).'%'
                    : 'not stated',
            ),
        ];

        return implode("\n", $lines);
    }
}
