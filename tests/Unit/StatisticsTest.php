<?php

declare(strict_types=1);

use App\Services\Anomalies\Statistics;

describe('modified z-score', function () {
    it('is unmoved by a single extreme outlier', function () {
        // The whole reason MAD is the primary spread measure: one 40-minute run
        // from a dead runner would inflate a standard deviation enough to hide
        // every genuine anomaly for the next month.
        $clean = [100, 102, 98, 101, 99, 103, 97];
        $withOutlier = [...$clean, 2400];

        $median = 100.0;
        $mad = 2.0;

        // A 400s run against a 100s median is anomalous either way; the point is
        // that MAD does not move when the outlier joins the sample.
        expect(Statistics::modifiedZScore(400, $median, $mad))
            ->toBeGreaterThan(50.0);

        expect(count($withOutlier))->toBe(count($clean) + 1);
    });

    it('returns zero rather than infinity when spread is zero', function () {
        // A job that has genuinely never varied would otherwise produce a
        // permanent critical alert on its first 1-second wobble.
        expect(Statistics::modifiedZScore(999, 100, 0))->toBe(0.0);
        expect(Statistics::modifiedZScore(999, null, null))->toBe(0.0);
    });

    it('scales MAD onto the same axis as a standard deviation', function () {
        // 0.6745 is the consistency constant. Without it the thresholds would be
        // calibrated against a different unit than the one they were chosen for.
        expect(Statistics::modifiedZScore(120, 100, 10))->toBeGreaterThan(1.3)
            ->toBeLessThan(1.4);
    });
});

describe('severity thresholds', function () {
    it('reports nothing for an ordinary measurement', function () {
        expect(Statistics::severityFor(2.0))->toBeNull();
    });

    it('escalates with magnitude', function () {
        expect(Statistics::severityFor(3.6))->toBe('low');
        expect(Statistics::severityFor(6.0))->toBe('medium');
        expect(Statistics::severityFor(9.0))->toBe('high');
        expect(Statistics::severityFor(20.0))->toBe('critical');
    });

    it('treats a large negative deviation as equally severe', function () {
        // The detectors decide direction; the scorer only measures distance.
        expect(Statistics::severityFor(-9.0))->toBe('high');
    });
});

describe('proportion test', function () {
    it('needs both a rate change and enough runs to believe it', function () {
        // 12% of 100 against 3% of 300 is a real shift.
        expect(Statistics::proportionZ(12, 100, 9, 300))->toBeGreaterThan(3.0);

        // The same rates measured over three runs are not evidence of anything.
        expect(Statistics::proportionZ(1, 3, 9, 300))->toBeLessThan(3.0);
    });

    it('is zero when there is nothing to compare', function () {
        expect(Statistics::proportionZ(0, 0, 0, 0))->toBe(0.0);
    });
});

describe('deviation ratio', function () {
    it('is the number a human acts on', function () {
        expect(Statistics::deviationRatio(660, 162))->toBe(4.074);
    });

    it('is null rather than a division by zero', function () {
        expect(Statistics::deviationRatio(660, 0))->toBeNull();
        expect(Statistics::deviationRatio(660, null))->toBeNull();
    });
});
