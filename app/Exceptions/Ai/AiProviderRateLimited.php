<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Exceptions\PipeMindException;

/**
 * The AI provider is throttling us.
 *
 * Distinct from AiServiceUnavailable on purpose: nothing is broken, and telling
 * a user "the analysis service is unreachable" when they have simply spent the
 * day's quota sends them to check containers and logs for a problem that does
 * not exist.
 *
 * Retryable — but on the provider's clock, not ours. Google's free tier caps
 * generateContent at 20 requests per day per model, and that daily ceiling is
 * reached long before any monthly budget.
 */
class AiProviderRateLimited extends PipeMindException
{
    protected string $errorCode = 'AI_PROVIDER_RATE_LIMITED';

    protected int $status = 429;

    protected bool $retryable = true;

    protected $message = 'The AI provider is rate limiting requests. Try again shortly.';
}
