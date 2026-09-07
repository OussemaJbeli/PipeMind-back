<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

/**
 * The AI provider rejected the credentials.
 *
 * Deliberately not retryable and deliberately distinct from
 * AiServiceUnavailable: a wrong API key is a configuration mistake only a human
 * can fix, and a queue that retries it turns one typo into repeated failures
 * whose real cause never reaches the screen.
 */
class AiProviderUnauthorized extends PipeMindException
{
    protected string $errorCode = 'AI_PROVIDER_UNAUTHORIZED';

    protected int $status = 422;

    protected bool $retryable = false;

    protected $message = 'The AI provider rejected the configured credentials.';
}
