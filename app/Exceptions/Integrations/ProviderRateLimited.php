<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use App\Exceptions\PipeMindException;

class ProviderRateLimited extends PipeMindException
{
    protected string $errorCode = 'PROVIDER_RATE_LIMITED';

    protected int $status = 429;

    protected bool $retryable = true;

    protected $message = 'The CI/CD provider is rate limiting requests.';
}
