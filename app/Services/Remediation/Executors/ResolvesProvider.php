<?php

declare(strict_types=1);

namespace App\Services\Remediation\Executors;

use App\Exceptions\Remediation\RemediationForbidden;
use App\Integrations\Contracts\PipelineProvider;
use App\Integrations\ProviderRegistry;
use App\Models\Integration;
use App\Models\Remediation;

trait ResolvesProvider
{
    /** @return array{0: PipelineProvider, 1: Integration} */
    protected function provider(Remediation $remediation): array
    {
        $project = $remediation->project;
        $integration = $project?->integration;

        // A project whose integration was removed still has failures and
        // recommendations, so this is reachable rather than defensive.
        if (! $integration) {
            throw new RemediationForbidden(
                'This project has no integration, so there is nothing to act on.'
            );
        }

        return [app(ProviderRegistry::class)->for($integration), $integration];
    }
}
