<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * A KPI tile value. Not an Eloquent resource — a shaped array the frontend
 * renders identically everywhere.
 */
class MetricResource
{
    /**
     * @param  'up'|'down'  $positiveDirection  Which direction is good for THIS metric.
     *                                          Fewer failures is an improvement; the UI
     *                                          colours the arrow from this rather than
     *                                          from the sign, so a falling MTTR renders
     *                                          green instead of red.
     */
    public static function make(
        float|int $value,
        ?float $delta = null,
        ?string $deltaLabel = null,
        ?string $unit = null,
        ?string $display = null,
        string $positiveDirection = 'up',
        ?array $spark = null,
    ): array {
        $trend = match (true) {
            $delta === null || abs($delta) < 0.01 => 'flat',
            $delta > 0 => 'up',
            default => 'down',
        };

        return array_filter([
            'value' => $value,
            'unit' => $unit,
            'display' => $display,
            'delta' => $delta,
            'delta_label' => $deltaLabel,
            'trend' => $trend,
            'positive_direction' => $positiveDirection,
            'spark' => $spark,
        ], fn ($v) => $v !== null);
    }
}
