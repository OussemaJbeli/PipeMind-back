<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Integrations\Contracts\PipelineProvider;
use App\Integrations\Providers\GenericProvider;
use App\Integrations\Providers\GithubProvider;
use App\Integrations\Providers\GitlabProvider;
use App\Integrations\Providers\JenkinsProvider;
use App\Models\Integration;
use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string,class-string<PipelineProvider>> */
    protected array $providers = [
        'gitlab' => GitlabProvider::class,
        'github' => GithubProvider::class,
        'jenkins' => JenkinsProvider::class,
        'generic' => GenericProvider::class,
    ];

    public function for(Integration $integration): PipelineProvider
    {
        return $this->make($integration->provider);
    }

    public function make(string $key): PipelineProvider
    {
        $class = $this->providers[$key]
            ?? throw new InvalidArgumentException("Unsupported provider: {$key}");

        return app($class);
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
