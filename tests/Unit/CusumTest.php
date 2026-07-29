<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\EarlyWarning\Cusum;
use Tests\TestCase;

/**
 * Two bugs in the notebook each produced a ~50% alarm rate, and both are pinned here as
 * regression tests. Fixing them took the rate from 53% to 4.34%.
 *
 *   1. CUSUM assumes a zero-mean in-control process. Any constant forecast bias makes
 *      the statistic ramp without limit and alarm forever. Detecting constant bias is
 *      the SPC charts' job, not this detector's.
 *   2. Each forecast run is an independent 24 h episode. Accumulating one CUSUM across
 *      concatenated unrelated episodes is meaningless.
 */
final class CusumTest extends TestCase
{
    /** @return list<float> deterministic pseudo-noise, mean ~ 0 */
    private function noise(int $n, float $scale = 1.0, int $seed = 1): array
    {
        $out = [];
        $state = $seed;
        for ($i = 0; $i < $n; $i++) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            $out[] = ((($state >> 8) % 2001) / 1000.0 - 1.0) * $scale;
        }
        return $out;
    }

    public function testInControlProcessDoesNotAlarm(): void
    {
        $result = (new Cusum(0.5, 20.0))->run($this->noise(96));
        $this->assertFalse(in_array(true, $result['alarm'], true), 'zero-mean noise must stay quiet');
    }

    public function testConstantBiasWouldRampWithoutCentring(): void
    {
        // The failure mode, demonstrated. A steady +1.5 sigma offset with centring off
        // sends the statistic through any threshold and it never comes back.
        $biased = array_map(static fn(float $v) => $v + 1.5, $this->noise(96));
        $result = (new Cusum(0.5, 20.0, false))->run($biased);
        $this->assertTrue(in_array(true, $result['alarm'], true), 'uncentred CUSUM must ramp on constant bias');
    }

    public function testCentringAbsorbsConstantBias(): void
    {
        $biased = array_map(static fn(float $v) => $v + 1.5, $this->noise(96));
        $result = (new Cusum(0.5, 20.0, true))->run($biased);
        $this->assertFalse(in_array(true, $result['alarm'], true), 'centred CUSUM must ignore a constant offset');
    }

    public function testStillDetectsARealShiftMidEpisode(): void
    {
        // The guard on the guard: the two fixes above must not turn this into a detector
        // that never fires. A sustained step in the second half has to be caught.
        $z = $this->noise(96, 0.3);
        for ($i = 48; $i < 96; $i++) {
            $z[$i] += 3.0;
        }
        $result = (new Cusum(0.5, 20.0, true))->run($z);
        $this->assertTrue(in_array(true, $result['alarm'], true), 'a sustained shift must be detected');
    }

    public function testIgnoresAnIsolatedSpike(): void
    {
        // One odd reading is a glitch. Raw residual thresholds false-alarm on it
        // constantly, which is the whole reason CUSUM is used instead.
        $z = $this->noise(96, 0.2);
        $z[40] = 12.0;
        $result = (new Cusum(0.5, 20.0, true))->run($z);
        $this->assertFalse(in_array(true, $result['alarm'], true));
    }

    public function testAccumulatesManySmallPersistentDeviations(): void
    {
        $z = array_fill(0, 96, 0.0);
        for ($i = 30; $i < 96; $i++) {
            $z[$i] = 1.2;
        }
        $result = (new Cusum(0.5, 8.0, false))->run($z);
        $this->assertTrue(in_array(true, $result['alarm'], true), 'twenty small consecutive deviations are a fault');
    }

    public function testEpisodesResetTheStatistic(): void
    {
        // Each fold is an independent 24 h forecast. Carrying the statistic across
        // concatenated episodes accumulates unrelated evidence.
        $drifting = array_fill(0, 96, 1.0);
        $episodes = [$drifting, $drifting, $drifting];

        $withReset = (new Cusum(0.5, 20.0, false))->runEpisodes($episodes);
        $concatenated = (new Cusum(0.5, 20.0, false))->run(array_merge(...$episodes));

        $maxReset = max(array_map(static fn(array $e) => max($e['sp']), $withReset));
        $maxConcat = max($concatenated['sp']);
        $this->assertLessThan($maxConcat, $maxReset, 'resetting must bound the statistic per episode');
    }

    public function testEpisodeResultsKeepTheirOrderAndLength(): void
    {
        $episodes = [$this->noise(96), $this->noise(96, 1.0, 7)];
        $result = (new Cusum())->runEpisodes($episodes);
        $this->assertCount(2, $result);
        $this->assertCount(96, $result[0]['sp']);
        $this->assertCount(96, $result[1]['alarm']);
    }

    public function testDetectsNegativeShiftsToo(): void
    {
        $z = $this->noise(96, 0.3);
        for ($i = 48; $i < 96; $i++) {
            $z[$i] -= 3.0;
        }
        $result = (new Cusum(0.5, 20.0, true))->run($z);
        $this->assertTrue(in_array(true, $result['alarm'], true), 'the lower arm must work as well as the upper');
    }

    public function testStatisticsAreNeverNegative(): void
    {
        $result = (new Cusum())->run($this->noise(200));
        foreach ($result['sp'] as $v) {
            $this->assertTrue($v >= 0.0);
        }
        foreach ($result['sm'] as $v) {
            $this->assertTrue($v >= 0.0);
        }
    }

    public function testAlarmRateHelperCountsFlaggedSamples(): void
    {
        $episodes = [[0.0, 0.0, 0.0, 0.0]];
        $this->assertFloatEquals(0.0, Cusum::alarmRate((new Cusum())->runEpisodes($episodes)));
    }

    public function testNullResidualsAreSkippedNotTreatedAsZero(): void
    {
        // A gap in the actuals is not evidence of good behaviour.
        $z = [null, null, 5.0, 5.0, 5.0, 5.0, 5.0, 5.0];
        $result = (new Cusum(0.5, 3.0, false))->run($z);
        $this->assertCount(8, $result['sp']);
        $this->assertNull($result['sp'][0]);
        $this->assertTrue(in_array(true, $result['alarm'], true));
    }

    public function testHandlesAnEmptySeries(): void
    {
        $result = (new Cusum())->run([]);
        $this->assertCount(0, $result['sp']);
        $this->assertFalse(in_array(true, $result['alarm'], true));
    }
}
