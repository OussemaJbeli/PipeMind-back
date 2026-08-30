<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use App\Exceptions\PipeMindException;

class IntegrationUnauthorized extends PipeMindException
{
    protected string $errorCode = 'INTEGRATION_UNAUTHORIZED';

    protected int $status = 401;

    protected bool $retryable = false;

    protected $message = 'The provider rejected the stored credentials.';
}
