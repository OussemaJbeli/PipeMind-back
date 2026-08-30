<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

class NoLocalProviderConfigured extends PipeMindException
{
    protected string $errorCode = 'AI_NO_LOCAL_PROVIDER';

    protected int $status = 422;

    protected bool $retryable = false;

    protected $message = 'This workspace is local-only but has no local AI provider configured.';
}
