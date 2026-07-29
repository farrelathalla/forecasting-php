<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\EarlyWarning\HuberTrend;
use Tests\TestCase;

/**
 * Trend projection turns a drift into a date. A date can be scheduled around; an alarm
 * light cannot.
 *
 * Huber rather than OLS because stoppages drag an ordinary least-squares slope hard —
 * the station has 16 of them and a trip is not a trend.
 */
final class HuberTrendTest extends TestCase
{
    /** @return array{list<float>,list<float>} */
    private function line(int $n, float $slope, float $intercept): array
    {
        $x = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = (float) $i;
            $y[] = $intercept + $slope * $i;
        }
        return [$x, $y];
    }

    public function testRecoversACleanSlope(): void
    {
        [$x, $y] = $this->line(100, 0.25, 3.0);
        $fit = (new HuberTrend())->fit($x, $y);
        $this->assertFloatEquals(0.25, $fit['slope'], 1e-6);
        $this->assertFloatEquals(3.0, $fit['intercept'], 1e-5);
    }

    public function testMatchesOlsOnCleanData(): void
    {
        [$x, $y] = $this->line(100, -0.1, 12.0);
        $huber = (new HuberTrend())->fit($x, $y);
        $ols = HuberTrend::ols($x, $y);
        $this->assertFloatEquals($ols['slope'], $huber['slope'], 1e-5);
    }

    public function testResistsTripOutliersWhereOlsDoesNot(): void
    {
        // This is the entire reason Huber was chosen, so it is asserted rather than
        // assumed. Three stoppages knock the readings to zero; the underlying trend
        // must survive.
        [$x, $y] = $this->line(100, 0.2, 10.0);
        foreach ([20, 55, 80] as $i) {
            $y[$i] = 0.0;
        }

        $huber = (new HuberTrend())->fit($x, $y);
        $ols = HuberTrend::ols($x, $y);

        $huberError = abs($huber['slope'] - 0.2);
        $olsError = abs($ols['slope'] - 0.2);

        $this->assertLessThan(0.02, $huberError, 'Huber must stay close to the true slope');
        $this->assertLessThan($olsError, $huberError, 'Huber must beat OLS on trip-contaminated data');
    }

    public function testProjectsADateWhenTheTrendApproachesTheLimit(): void
    {
        $days = HuberTrend::daysToLimit(0.05784700147069853, 6.096248197050634, 8.967384);
        $this->assertNotNull($days);
        $this->assertFloatEquals(49.63, $days, 0.05, 'must reproduce the notebook projection for dT|TBW1');
    }

    public function testReturnsNullWhenTheTrendMovesAwayFromTheLimit(): void
    {
        // A falling channel will never reach a ceiling. Reporting a negative or infinite
        // ETA would put a nonsense date on a work order.
        $this->assertNull(HuberTrend::daysToLimit(-3.25, 98.29, 265.0));
    }

    public function testReturnsNullWhenAlreadyPastTheLimit(): void
    {
        $this->assertNull(HuberTrend::daysToLimit(0.33, 9.007, 2.577));
    }

    public function testFlatTrendNeverReachesTheLimit(): void
    {
        $this->assertNull(HuberTrend::daysToLimit(0.0, 5.0, 10.0));
    }

    public function testWorksDownwardsTowardsALowerLimit(): void
    {
        $days = HuberTrend::daysToLimit(-0.5, 10.0, 5.0);
        $this->assertFloatEquals(10.0, $days, 1e-9);
    }

    public function testRefusesToFitFewerThanTwoPoints(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            (new HuberTrend())->fit([1.0], [2.0]);
        });
    }

    public function testIgnoresNullObservations(): void
    {
        [$x, $y] = $this->line(50, 0.3, 1.0);
        $yWithGaps = $y;
        $yWithGaps[10] = null;
        $yWithGaps[11] = null;

        $fit = (new HuberTrend())->fitSeries($x, $yWithGaps);
        $this->assertFloatEquals(0.3, $fit['slope'], 1e-6);
        $this->assertSame(48, $fit['n']);
    }

    public function testProjectFromSeriesReturnsSlopePerDayAndEta(): void
    {
        // 96 steps per day at 15-minute resolution, rising 1.0 per day.
        $series = [];
        $start = new \DateTimeImmutable('2026-07-01 00:00:00');
        for ($i = 0; $i < 96 * 10; $i++) {
            $series[] = [
                'ts'    => $start->modify("+{$i} minutes")->format('Y-m-d H:i:s'),
                'value' => 5.0 + $i / 96.0,
            ];
        }
        // Every step is one minute above, so the day rate is 1440/96 = 15 per day.
        $projection = HuberTrend::project($series, 100.0);

        $this->assertNotNull($projection['slope_per_day']);
        $this->assertGreaterThan(0.0, $projection['slope_per_day']);
        $this->assertNotNull($projection['eta']);
        $this->assertSame(960, $projection['n_points']);
    }

    public function testProjectHandlesAnEmptySeries(): void
    {
        $projection = HuberTrend::project([], 10.0);
        $this->assertNull($projection['slope_per_day']);
        $this->assertNull($projection['eta']);
        $this->assertSame(0, $projection['n_points']);
    }
}
