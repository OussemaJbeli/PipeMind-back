<?php

declare(strict_types=1);

namespace App\Services\Anomalies;

/**
 * Robust statistics for noisy infrastructure metrics.
 *
 * Everything here uses the median and MAD rather than the mean and standard
 * deviation. One 40-minute run caused by a dead runner inflates a standard
 * deviation enough to hide every genuine anomaly for the next month; the median
 * absolute deviation is unmoved by it, which is exactly the property a metric
 * derived from shared CI infrastructure needs.
 */
final class Statistics
{
    /** 0.6745 is the 0.75 quantile of the normal distribution — it puts MAD on the same scale as σ. */
    private const CONSISTENCY = 0.6745;

    /** @var array<string,float> */
    public const THRESHOLDS = [
        'critical' => 12.0,
        'high' => 8.0,
        'medium' => 5.0,
        'low' => 3.5,
    ];

    public static function modifiedZScore(float $value, ?float $median, ?float $mad): float
    {
        // A zero MAD means every sample was identical. Dividing by it would make
        // any deviation infinite, so a job that has genuinely never varied simply
        // produces no score rather than a permanent critical alert.
        if ($median === null || $mad === null || $mad <= 0.0) {
            return 0.0;
        }

        return self::CONSISTENCY * ($value - $median) / $mad;
    }

    /** Null means "not worth reporting" — most measurements are normal. */
    public static function severityFor(float $score): ?string
    {
        $magnitude = abs($score);

        foreach (self::THRESHOLDS as $name => $threshold) {
            if ($magnitude >= $threshold) {
                return $name;
            }
        }

        return null;
    }

    /** "4.1× slower" is the number a human acts on; the z-score is the reason it was flagged. */
    public static function deviationRatio(float $observed, ?float $baseline): ?float
    {
        if (! $baseline) {
            return null;
        }

        return round($observed / $baseline, 3);
    }

    /**
     * Two-proportion z-test, for rates rather than durations.
     *
     * "12% of runs failed this week against 3% over the month" is a different
     * kind of claim from "this run was slow", and needs a test that accounts for
     * how many runs each rate was measured over.
     */
    public static function proportionZ(int $recentFails, int $recentTotal, int $baseFails, int $baseTotal): float
    {
        if ($recentTotal < 1 || $baseTotal < 1) {
            return 0.0;
        }

        $p1 = $recentFails / $recentTotal;
        $p2 = $baseFails / $baseTotal;
        $pooled = ($recentFails + $baseFails) / ($recentTotal + $baseTotal);

        if ($pooled <= 0.0 || $pooled >= 1.0) {
            return 0.0;
        }

        $standardError = sqrt($pooled * (1 - $pooled) * (1 / $recentTotal + 1 / $baseTotal));

        return $standardError > 0.0 ? ($p1 - $p2) / $standardError : 0.0;
    }
}
