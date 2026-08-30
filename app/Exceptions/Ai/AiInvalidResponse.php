<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

class AiInvalidResponse extends PipeMindException
{
    protected string $errorCode = 'AI_INVALID_RESPONSE';

    protected int $status = 502;

    protected bool $retryable = true;

    protected $message = 'The analysis service returned an unusable response.';
}
