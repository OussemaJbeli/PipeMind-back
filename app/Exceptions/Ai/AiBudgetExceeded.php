<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

class AiBudgetExceeded extends PipeMindException
{
    protected string $errorCode = 'AI_BUDGET_EXCEEDED';

    protected int $status = 402;

    protected bool $retryable = false;

    protected $message = 'The monthly AI budget for this workspace has been reached.';
}
