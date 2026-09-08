<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Models\Remediation;
use App\Services\Remediation\ExecutionResult;

interface RemediationExecutor
{
    public static function actionType(): string;

    /**
     * Pre-flight: is this action still worth taking?
     *
     * Time passes between an approval and its execution, and humans act in that
     * window — somebody retries the job themselves, or pushes a fix. Executing
     * anyway is how an automated system becomes noise.
     */
    public function canExecute(Remediation $remediation): bool;

    /**
     * Why not executed: shown to the user in place of a result, so it has to
     * explain what changed rather than say "preconditions failed".
     */
    public function blockedReason(Remediation $remediation): string;

    /** @param bool $dryRun Describe the action without performing it. */
    public function execute(Remediation $remediation, bool $dryRun = false): ExecutionResult;
}
