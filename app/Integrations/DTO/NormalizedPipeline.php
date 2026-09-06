<?php

declare(strict_types=1);

namespace App\Integrations\DTO;

/**
 * A pipeline in PipeMind's vocabulary. Provider payloads never travel past the
 * adapter layer — the rest of the application, and the AI service, must not need
 * to know what a "workflow run" is.
 */
final class NormalizedPipeline
{
    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?int $iid,
        public readonly string $provider,
        public readonly string $status,
        public readonly string $source,
        public readonly string $ref,
        public readonly bool $isTag = false,
        public readonly ?string $commitSha = null,
        public readonly ?string $commitMessage = null,
        public readonly ?string $commitAuthorName = null,
        public readonly ?string $commitAuthorEmail = null,
        public readonly ?string $commitUrl = null,
        public readonly ?string $webUrl = null,
        public readonly ?string $triggeredBy = null,
        public readonly ?string $queuedAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?int $queueSeconds = null,
        public readonly array $raw = [],
    ) {}

    /** @return array<string,mixed> */
    public function toAttributes(): array
    {
        return [
            'external_id' => $this->externalId,
            'iid' => $this->iid,
            'provider' => $this->provider,
            'status' => $this->status,
            'source' => $this->source,
            'ref' => $this->ref,
            'is_tag' => $this->isTag,
            'commit_sha' => $this->commitSha,
            'commit_short_sha' => $this->commitSha ? substr($this->commitSha, 0, 8) : null,
            'commit_message' => $this->commitMessage,
            'commit_author_name' => $this->commitAuthorName,
            'commit_author_email' => $this->commitAuthorEmail,
            'commit_url' => $this->commitUrl,
            'web_url' => $this->webUrl,
            'triggered_by' => $this->triggeredBy,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'queue_seconds' => $this->queueSeconds,
            'raw_payload' => $this->raw ?: null,
        ];
    }
}
