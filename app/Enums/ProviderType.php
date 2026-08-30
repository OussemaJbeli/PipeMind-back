<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderType: string
{
    case GITLAB = 'gitlab';
    case GITHUB = 'github';
    case JENKINS = 'jenkins';
    case GENERIC = 'generic';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
