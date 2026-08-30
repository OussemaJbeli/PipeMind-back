<?php

declare(strict_types=1);

namespace App\Exceptions\Remediation;

use App\Exceptions\PipeMindException;

class PolicyLimitReached extends PipeMindException
{
    protected string $errorCode = 'POLICY_LIMIT_REACHED';

    protected int $status = 429;

    protected bool $retryable = false;

    protected $message = 'The daily limit for this action has been reached.';
}
