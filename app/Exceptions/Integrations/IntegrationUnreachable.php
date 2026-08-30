<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use App\Exceptions\PipeMindException;

class IntegrationUnreachable extends PipeMindException
{
    protected string $errorCode = 'INTEGRATION_UNREACHABLE';

    protected int $status = 502;

    protected bool $retryable = true;

    protected $message = 'Could not reach the CI/CD provider.';
}
