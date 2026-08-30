<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

class AiServiceUnavailable extends PipeMindException
{
    protected string $errorCode = 'AI_SERVICE_UNAVAILABLE';

    protected int $status = 503;

    protected bool $retryable = true;

    protected $message = 'The analysis service is unreachable.';
}
