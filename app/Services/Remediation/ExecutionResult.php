<?php

declare(strict_types=1);

namespace App\Services\Remediation;

/**
 * What an executor actually did, in terms a human can verify.
 *
 * `url` matters more than it looks: every mutating action produces something on
 * the provider — a run, an issue, a pull request — and a remediation the user
 * cannot click through to is one they have to take on trust.
 */
final readonly class ExecutionResult
{
    /** @param array<string,mixed> $meta */
    private function __construct(
        public string $summary,
        public ?string $url = null,
        public ?int $pipelineId = null,
        public ?string $externalId = null,
        public array $meta = [],
        public bool $dryRun = false,
    ) {}

    /** @param array<string,mixed> $meta */
    public static function make(
        string $summary,
        ?string $url = null,
        ?int $pipelineId = null,
        ?string $externalId = null,
        array $meta = [],
    ): self {
        return new self($summary, $url, $pipelineId, $externalId, $meta);
    }

    /**
     * What would have happened. Nothing was called.
     *
     * Every executor supports this: the first thing anyone sensibly asks of a
     * tool that edits their repository is to see it do nothing first.
     *
     * @param  array<string,mixed>  $meta
     */
    public static function dryRun(string $summary, array $meta = []): self
    {
        return new self($summary, meta: $meta, dryRun: true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'summary' => $this->summary,
            'url' => $this->url,
            'external_id' => $this->externalId,
            'dry_run' => $this->dryRun ?: null,
            'meta' => $this->meta ?: null,
        ], fn ($value) => $value !== null);
    }
}
