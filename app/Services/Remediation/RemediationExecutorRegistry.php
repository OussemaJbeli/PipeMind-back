<?php

declare(strict_types=1);

namespace App\Services\Remediation;

use App\Enums\ActionType;
use App\Exceptions\Remediation\RemediationForbidden;
use App\Services\Remediation\Executors\CreateIssueExecutor;
use App\Services\Remediation\Executors\CreateMergeRequestExecutor;
use App\Services\Remediation\Executors\InvestigateExecutor;
use App\Services\Remediation\Executors\RemediationExecutor;
use App\Services\Remediation\Executors\RetryJobExecutor;
use App\Services\Remediation\Executors\RetryPipelineExecutor;

/**
 * Maps an action type to the code that performs it.
 *
 * An action with no executor is refused rather than ignored. `update_config`,
 * `update_dependency`, `edit_file` and `rollback_deployment` are deliberately
 * absent: the first three are proposals a human applies, and a production
 * rollback is not something this system should ever perform. They reach the
 * approval flow — a user can see and approve them — and then stop here with a
 * message saying so, which is the honest outcome rather than a silent no-op.
 */
class RemediationExecutorRegistry
{
    /** @var array<int,class-string<RemediationExecutor>> */
    private array $executors = [
        InvestigateExecutor::class,
        RetryJobExecutor::class,
        RetryPipelineExecutor::class,
        CreateIssueExecutor::class,
        CreateMergeRequestExecutor::class,
    ];

    public function for(ActionType|string $action): RemediationExecutor
    {
        $key = $action instanceof ActionType ? $action->value : $action;

        foreach ($this->executors as $executor) {
            if ($executor::actionType() === $key) {
                return app($executor);
            }
        }

        throw new RemediationForbidden(
            "PipeMind cannot perform '{$key}' itself — apply this one by hand."
        );
    }

    public function handles(ActionType|string $action): bool
    {
        $key = $action instanceof ActionType ? $action->value : $action;

        foreach ($this->executors as $executor) {
            if ($executor::actionType() === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    public function actionTypes(): array
    {
        return array_map(fn (string $executor) => $executor::actionType(), $this->executors);
    }
}
