<?php

declare(strict_types=1);

namespace App\Exceptions\Remediation;

use App\Exceptions\PipeMindException;

class RemediationExpired extends PipeMindException
{
    protected string $errorCode = 'REMEDIATION_EXPIRED';

    protected int $status = 410;

    protected bool $retryable = false;

    protected $message = 'This approval has expired and can no longer be executed.';
}
