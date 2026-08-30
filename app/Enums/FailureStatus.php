<?php

declare(strict_types=1);

namespace App\Enums;

enum FailureStatus: string
{
    case DETECTED = 'detected';
    case QUEUED = 'queued';
    case ANALYZING = 'analyzing';
    case ANALYZED = 'analyzed';
    case ANALYSIS_FAILED = 'analysis_failed';
    case RESOLVED = 'resolved';
    case IGNORED = 'ignored';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
