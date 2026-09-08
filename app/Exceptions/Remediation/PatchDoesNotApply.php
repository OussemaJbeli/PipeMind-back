<?php

declare(strict_types=1);

namespace App\Exceptions\Remediation;

use App\Exceptions\PipeMindException;

/**
 * The patch no longer matches the file it was written against.
 *
 * A safety outcome, not a bug. A diff is only meaningful against the exact text
 * it was computed from, and between the analysis and the approval somebody may
 * have edited the same lines. Refusing is correct: applying a near-miss silently
 * produces code nobody wrote or reviewed.
 *
 * 409 rather than 422 — the request was valid when it was made, and the state of
 * the world moved underneath it.
 */
class PatchDoesNotApply extends PipeMindException
{
    protected string $errorCode = 'PATCH_DOES_NOT_APPLY';

    protected int $status = 409;

    protected bool $retryable = false;

    protected $message = 'The file has changed since this patch was written.';
}
