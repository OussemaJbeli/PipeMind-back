<?php

declare(strict_types=1);

namespace App\Exceptions\Remediation;

use App\Exceptions\PipeMindException;

class RemediationForbidden extends PipeMindException
{
    protected string $errorCode = 'REMEDIATION_FORBIDDEN';

    protected int $status = 403;

    protected bool $retryable = false;

    protected $message = 'This action is not permitted by the workspace policy.';
}
