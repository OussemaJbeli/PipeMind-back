<?php

declare(strict_types=1);

namespace App\Integrations\DTO;

/**
 * What a token can actually do, reported at connect time.
 *
 * Half of all integration support burden is a token with the wrong scope.
 * Showing "connected as X, N projects visible, retry: not permitted" up front
 * turns a confusing future failure into a clear present choice.
 */
final class ProviderIdentity
{
    /** @param array<int,string> $scopes */
    public function __construct(
        public readonly string $username,
        public readonly ?string $name = null,
        public readonly ?string $avatarUrl = null,
        public readonly array $scopes = [],
        public readonly bool $canReadProjects = true,
        public readonly bool $canRetryJobs = false,
        public readonly bool $canWriteIssues = false,
        public readonly ?int $projectCount = null,
    ) {}
}
