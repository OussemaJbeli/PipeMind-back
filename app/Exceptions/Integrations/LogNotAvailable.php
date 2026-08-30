<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use App\Exceptions\PipeMindException;

class LogNotAvailable extends PipeMindException
{
    protected string $errorCode = 'LOG_NOT_AVAILABLE';

    protected int $status = 404;

    protected bool $retryable = false;

    protected $message = 'The job log is no longer available from the provider.';
}
