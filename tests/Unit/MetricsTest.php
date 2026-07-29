<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Scoring\Metrics;
use Tests\TestCase;

/**
 * The metric hierarchy from notebook section 6, carried into production unchanged:
 *
 *   MASE  headline point metric. Dimensionless, so 190 A cannot dominate 4.9 kg/cm2.
 *   WQL   probabilistic headline. The early-warning use case depends on interval
 *         quality, not point accuracy — which is why Chronos-2 is deployed despite
 *         losing MASE to LGBM-Local by 0.028.
 *   cov80 reported separately, because a model can hold good WQL while being
 *         systematically over-confident, and an under-covering alarm system floods
 *         the control room with false positives.
 */
final class MetricsTest extends TestCase
{
    /**
     * A daily cycle plus a small non-periodic wobble.
     *
     * The wobble is required, not decorative: a pure sine of period 96 has a seasonal
     * difference of exactly zero everywhere, which makes the MASE denominator zero and
     * the metric genuinely undefined. Real process data always carries some.
     *
     * @return list<float>
     */
    private function daily(int $n, float $level = 100.0): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $level + 5.0 * sin(2 * M_PI * $i / 96.0) + (($i * 13) % 7) * 0.1;
        }
        return $out;
    }

    public function testMaseIsZeroForAPerfectForecast(): void
    {
        $actual = $this->daily(96);
        $history = $this->daily(300);
        $this->assertFloatEquals(0.0, Metrics::mase($actual, $actual, $history), 1e-12);
    }

    public function testMaseIsOneWhenTheForecastMatchesSeasonalNaive(): void
    {
        // The definition of the scale. A model at MASE 1.0 is copying last season, not
        // forecasting, and everything below 1.0 is genuine skill.
        $history = [];
        for ($i = 0; $i < 96 * 4; $i++) {
            $history[] = 100.0 + 5.0 * sin(2 * M_PI * $i / 96.0) + (($i * 13) % 7) * 0.3;
        }
        $actual = array_slice($history, 96 * 3, 96);
        $naive = array_slice($history, 96 * 2, 96);

        $mase = Metrics::mase($actual, $naive, array_slice($history, 0, 96 * 3));
        $this->assertFloatEquals(1.0, $mase, 0.35, 'seasonal naive must sit at roughly 1.0 by construction');
    }

    public function testMaseUsesSeasonalNaiveNotLagOne(): void
    {
        // With a strong daily cycle, a lag-1 denominator is far smaller than a
        // seasonal one, so the wrong denominator inflates every score several-fold.
        $history = $this->daily(96 * 3);
        $actual = $this->daily(96);
        $forecast = array_map(static fn(float $v) => $v + 1.0, $actual);

        $seasonal = Metrics::mase($actual, $forecast, $history, Domain::SEASONALITY);
        $lagOne = Metrics::mase($actual, $forecast, $history, 1);
        $this->assertFalse(abs($seasonal - $lagOne) < 1e-6, 'the seasonality argument must actually matter');
    }

    public function testMaseIsScaleFree(): void
    {
        // The reason MASE is the headline: POWER at ~187 kW and HEADER_PRESSURE at
        // ~4.9 kg/cm2 have to be averageable. RMSE would let power dominate entirely.
        $history = $this->daily(300);
        $actual = $this->daily(96);
        $forecast = array_map(static fn(float $v) => $v * 1.01, $actual);

        $scale = static fn(array $a, float $k) => array_map(static fn(float $v) => $v * $k, $a);
        $small = Metrics::mase($actual, $forecast, $history);
        $large = Metrics::mase($scale($actual, 1000), $scale($forecast, 1000), $scale($history, 1000));

        $this->assertFloatEquals($small, $large, 1e-9);
    }

    public function testWqlIsZeroWhenEveryQuantileIsExact(): void
    {
        $actual = [10.0, 11.0, 12.0];
        $quantiles = [];
        foreach (Domain::QUANTILES as $q) {
            $quantiles[(string) $q] = $actual;
        }
        $this->assertFloatEquals(0.0, Metrics::wql($actual, $quantiles), 1e-12);
    }

    public function testWqlPenalisesAsymmetricallyInTheRightDirection(): void
    {
        // Pinball loss must punish a q10 that is too high more than one that is too low.
        $actual = [10.0, 10.0, 10.0];
        $tooHigh = ['0.1' => [12.0, 12.0, 12.0]];
        $tooLow = ['0.1' => [8.0, 8.0, 8.0]];

        $this->assertGreaterThan(
            Metrics::wql($actual, $tooLow),
            Metrics::wql($actual, $tooHigh),
            'the 10th percentile must be punished harder for being above the actual'
        );
    }

    public function testWqlGrowsWithError(): void
    {
        $actual = $this->daily(96);
        $near = [];
        $far = [];
        foreach (Domain::QUANTILES as $q) {
            $near[(string) $q] = array_map(static fn(float $v) => $v + ($q - 0.5) * 2.0, $actual);
            $far[(string) $q] = array_map(static fn(float $v) => $v + ($q - 0.5) * 2.0 + 20.0, $actual);
        }
        $this->assertGreaterThan(Metrics::wql($actual, $near), Metrics::wql($actual, $far));
    }

    public function testCoverageCountsActualsInsideTheBand(): void
    {
        $actual = [];
        $lower = [];
        $upper = [];
        for ($i = 0; $i < 100; $i++) {
            $actual[] = 10.0;
            $lower[] = 9.0;
            $upper[] = 11.0;
        }
        for ($i = 0; $i < 20; $i++) {
            $actual[$i] = 50.0;
        }
        $this->assertFloatEquals(0.80, Metrics::coverage($actual, $lower, $upper), 1e-12);
    }

    public function testCoverageOfAnOverconfidentModelIsBelowNominal(): void
    {
        // LGBM-Local's 80% interval covered 68.4% of actuals. It is confidently wrong,
        // and section 15 is built on interval width — which is why this is reported
        // separately rather than folded into WQL.
        $actual = [];
        $lower = [];
        $upper = [];
        for ($i = 0; $i < 100; $i++) {
            $actual[] = ($i % 3 === 0) ? 20.0 : 10.0;
            $lower[] = 9.5;
            $upper[] = 10.5;
        }
        $this->assertLessThan(0.80, Metrics::coverage($actual, $lower, $upper));
    }

    public function testNullActualsAreExcludedNotTreatedAsZero(): void
    {
        // A gap in the actuals is missing evidence. Scoring it as zero would reward a
        // model for the hours the plant was not reporting.
        $actual = [10.0, null, 12.0];
        $forecast = [10.0, 999.0, 12.0];
        $history = $this->daily(300);

        $this->assertFloatEquals(0.0, Metrics::mase($actual, $forecast, $history), 1e-12);
    }

    public function testRmseAndMaeAreAvailableAsSecondaryDiagnostics(): void
    {
        $actual = [10.0, 20.0, 30.0];
        $forecast = [11.0, 19.0, 33.0];
        $this->assertFloatEquals(sqrt((1 + 1 + 9) / 3), Metrics::rmse($actual, $forecast), 1e-12);
        $this->assertFloatEquals((1 + 1 + 3) / 3, Metrics::mae($actual, $forecast), 1e-12);
    }

    public function testMetricsReturnNullRatherThanNanWhenThereIsNothingToScore(): void
    {
        $this->assertNull(Metrics::mase([], [], []));
        $this->assertNull(Metrics::rmse([], []));
        $this->assertNull(Metrics::coverage([], [], []));
    }

    public function testHorizonBucketsSplitTheErrorGrowthCurve(): void
    {
        // Section 14: the advantage grows exactly where it is needed. The alarm layer
        // runs at 24 h, so the far buckets are the ones that decide the model.
        $errors = [];
        for ($h = 1; $h <= 96; $h++) {
            $errors[$h] = $h * 0.001;
        }
        $buckets = Metrics::horizonBuckets($errors);

        $this->assertTrue(isset($buckets['0-1h'], $buckets['1-4h'], $buckets['4-12h'], $buckets['12-24h']));
        $this->assertGreaterThan($buckets['0-1h'], $buckets['12-24h']);
    }
}
