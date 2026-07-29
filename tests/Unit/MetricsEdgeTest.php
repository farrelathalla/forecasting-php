<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Scoring\Metrics;
use Tests\TestCase;

/**
 * MASE is undefined when the seasonal-naive denominator is zero, which happens whenever
 * a series is exactly periodic at the seasonality. Discovered by a test built on a pure
 * sine wave. Returning null is correct; returning 0, INF or NAN would put a meaningless
 * number straight onto the scorecard.
 */
final class MetricsEdgeTest extends TestCase
{
    public function testMaseIsNullWhenTheSeasonalDenominatorIsZero(): void
    {
        $history = [];
        for ($i = 0; $i < 300; $i++) {
            $history[] = 100.0 + 5.0 * sin(2 * M_PI * $i / 96.0);
        }
        $actual = array_slice($history, 0, 96);
        $this->assertNull(Metrics::mase($actual, $actual, $history, 96));
    }

    public function testMaseIsNullWhenHistoryIsShorterThanOneSeason(): void
    {
        $this->assertNull(Metrics::mase([1.0, 2.0], [1.0, 2.0], [1.0, 2.0, 3.0], 96));
    }

    public function testMaseIsNullWhenEveryActualIsMissing(): void
    {
        $history = [];
        for ($i = 0; $i < 300; $i++) {
            $history[] = 100.0 + ($i % 13);
        }
        $this->assertNull(Metrics::mase([null, null], [1.0, 2.0], $history, 96));
    }

    public function testWqlIsNullWithNothingToCompare(): void
    {
        $this->assertNull(Metrics::wql([null, null], ['0.5' => [1.0, 2.0]]));
    }

    public function testSdNeedsTwoObservations(): void
    {
        $this->assertNull(Metrics::sd([1.0]));
        $this->assertNotNull(Metrics::sd([1.0, 2.0]));
    }

    public function testMeanIgnoresNulls(): void
    {
        $this->assertFloatEquals(2.0, Metrics::mean([1.0, null, 3.0]), 1e-12);
        $this->assertNull(Metrics::mean([null, null]));
    }
}
