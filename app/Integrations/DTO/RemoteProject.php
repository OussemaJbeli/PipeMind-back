<?php

declare(strict_types=1);

namespace App\Integrations\DTO;

final class RemoteProject
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $path,
        public readonly ?string $description = null,
        public readonly ?string $webUrl = null,
        public readonly ?string $repositoryUrl = null,
        public readonly string $defaultBranch = 'main',
        public readonly ?string $lastActivityAt = null,
    ) {}
}
