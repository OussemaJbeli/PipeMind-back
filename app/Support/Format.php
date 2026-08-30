<?php

declare(strict_types=1);

namespace App\Support;

class Format
{
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        if ($minutes < 60) {
            return $remainder ? "{$minutes}m {$remainder}s" : "{$minutes}m";
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /** Signed percentage or absolute delta, formatted for a KPI tile. */
    public static function deltaLabel(?float $delta, string $suffix, bool $percent = false): ?string
    {
        if ($delta === null) {
            return null;
        }

        $sign = $delta >= 0 ? '+' : '';
        $value = $percent ? number_format($delta, 1).'%' : (string) round($delta);

        return trim("{$sign}{$value} {$suffix}");
    }
}
