<?php

declare(strict_types=1);

namespace App\Enums;

enum JobStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
    case SKIPPED = 'skipped';
    case MANUAL = 'manual';
    case TIMEOUT = 'timeout';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
