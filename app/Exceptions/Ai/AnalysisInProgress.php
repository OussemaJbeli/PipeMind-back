<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

class AnalysisInProgress extends PipeMindException
{
    protected string $errorCode = 'ANALYSIS_IN_PROGRESS';

    protected int $status = 409;

    protected bool $retryable = false;

    protected $message = 'An analysis is already running for this failure.';
}
