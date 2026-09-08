<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Enums\ActionType;
use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;

/**
 * Acknowledges that a human will look at it. Changes nothing anywhere.
 *
 * It exists so that "investigate" travels the same audited path as every other
 * action instead of being a special case in the UI — the trail then answers
 * "was anything done about this?" uniformly.
 */
class InvestigateExecutor implements RemediationExecutor
{
    public static function actionType(): string
    {
        return ActionType::INVESTIGATE->value;
    }

    public function canExecute(Remediation $remediation): bool
    {
        return true;
    }

    public function blockedReason(Remediation $remediation): string
    {
        return '';
    }

    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult
    {
        return ExecutionResult::make('Acknowledged for manual investigation.');
    }
}
