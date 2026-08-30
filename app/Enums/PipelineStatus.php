<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case SKIPPED = 'skipped';
    case MANUAL = 'manual';
    case TIMEOUT = 'timeout';

    /** No further transitions expected — safe to fetch logs and compute metrics. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::SUCCESS, self::FAILED, self::CANCELED, self::SKIPPED, self::TIMEOUT,
        ], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::QUEUED, self::RUNNING], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FAILED, self::TIMEOUT], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::SUCCESS => '#4ADE80',
            self::FAILED => '#F04438',
            self::RUNNING => '#6366F1',
            self::TIMEOUT, self::MANUAL => '#F5A524',
            default => '#8B93A1',
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
