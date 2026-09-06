<?php

declare(strict_types=1);

namespace App\Integrations\DTO;

final class NormalizedJob
{
    /**
     * @param  array<int,string>  $runnerTags
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $stageName,
        public readonly string $status,
        public readonly int $position = 0,
        public readonly ?string $failureReason = null,
        public readonly ?int $exitCode = null,
        public readonly bool $allowFailure = false,
        public readonly ?string $runnerName = null,
        public readonly array $runnerTags = [],
        public readonly ?string $image = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?int $queueSeconds = null,
        public readonly ?string $webUrl = null,
        public readonly array $raw = [],
    ) {}

    /** @return array<string,mixed> */
    public function toAttributes(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
            'stage_name' => $this->stageName,
            'status' => $this->status,
            'position' => $this->position,
            'failure_reason' => $this->failureReason,
            'exit_code' => $this->exitCode,
            'allow_failure' => $this->allowFailure,
            'runner_name' => $this->runnerName,
            'runner_tags' => $this->runnerTags,
            'image' => $this->image,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'queue_seconds' => $this->queueSeconds,
            'web_url' => $this->webUrl,
            'raw_payload' => $this->raw ?: null,
        ];
    }
}
