<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Forecast\NaiveSeasonal;
use Tests\TestCase;

/**
 * Seasonal naive has two jobs here.
 *
 * 1. It is the fallback when the Chronos-2 sidecar is unreachable, so an outage of the
 *    Python service degrades the system instead of stopping it.
 * 2. It is the permanent benchmark floor. Notebook section 6: OUTLET_TEMP has ACF > 0.9
 *    at a one-week lag, so persistence forecasts it almost perfectly at short horizons.
 *    Without a naive baseline in the table, that series flatters every model.
 */
final class NaiveSeasonalTest extends TestCase
{
    /** @return list<float> a clean daily cycle, 96 steps per day */
    private function daily(int $days = 3, float $level = 35.0, float $amplitude = 2.0): array
    {
        $out = [];
        for ($i = 0; $i < $days * 96; $i++) {
            $out[] = $level + $amplitude * sin(2 * M_PI * $i / 96.0);
        }
        return $out;
    }

    public function testRepeatsTheValueOneSeasonAgo(): void
    {
        $context = $this->daily();
        $result = (new NaiveSeasonal())->predict($context, 96, 96, [0.5]);

        $n = count($context);
        for ($h = 0; $h < 96; $h++) {
            $this->assertFloatEquals($context[$n - 96 + $h], $result['median'][$h], 1e-9, "step {$h}");
        }
    }

    public function testWrapsAroundWhenTheHorizonExceedsOneSeason(): void
    {
        $context = $this->daily();
        $result = (new NaiveSeasonal())->predict($context, 192, 96, [0.5]);

        $this->assertCount(192, $result['median']);
        $this->assertFloatEquals($result['median'][0], $result['median'][96], 1e-9);
    }

    public function testQuantilesAreOrderedAndStraddleTheMedian(): void
    {
        $context = $this->daily();
        foreach ($context as $i => $v) {
            $context[$i] = $v + sin($i * 7.3) * 0.4;
        }
        $result = (new NaiveSeasonal())->predict($context, 96, 96, Domain::QUANTILES);

        for ($h = 0; $h < 96; $h++) {
            $previous = null;
            foreach (Domain::QUANTILES as $q) {
                $value = $result['quantiles'][(string) $q][$h];
                if ($previous !== null) {
                    $this->assertTrue($value >= $previous - 1e-9, "quantile crossing at step {$h}, level {$q}");
                }
                $previous = $value;
            }
            $this->assertFloatEquals($result['median'][$h], $result['quantiles']['0.5'][$h], 1e-9);
        }
    }

    public function testIntervalWidensWithNoisierHistory(): void
    {
        $quiet = $this->daily();
        $noisy = $quiet;
        foreach ($noisy as $i => $v) {
            $noisy[$i] = $v + (($i * 37 % 19) - 9) * 0.5;
        }

        $spread = static function (array $r): float {
            $sum = 0.0;
            for ($h = 0; $h < 96; $h++) {
                $sum += $r['quantiles']['0.9'][$h] - $r['quantiles']['0.1'][$h];
            }
            return $sum / 96;
        };

        $model = new NaiveSeasonal();
        $this->assertGreaterThan(
            $spread($model->predict($quiet, 96, 96, Domain::QUANTILES)),
            $spread($model->predict($noisy, 96, 96, Domain::QUANTILES)),
            'a noisier history must produce wider intervals'
        );
    }

    public function testSkipsNullsWhenLookingBackOneSeason(): void
    {
        $context = $this->daily();
        $context[count($context) - 96] = null;

        $result = (new NaiveSeasonal())->predict($context, 96, 96, [0.5]);
        $this->assertNotNull($result['median'][0]);
        $this->assertTrue(is_finite($result['median'][0]));
    }

    public function testFallsBackToTheLastValueWhenHistoryIsShorterThanASeason(): void
    {
        $result = (new NaiveSeasonal())->predict([10.0, 11.0, 12.0], 4, 96, [0.5]);
        $this->assertCount(4, $result['median']);
        foreach ($result['median'] as $v) {
            $this->assertFloatEquals(12.0, $v, 1e-9);
        }
    }

    public function testRefusesAnEmptyContext(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            (new NaiveSeasonal())->predict([], 96, 96, [0.5]);
        });
    }

    public function testAllNullContextIsRefused(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            (new NaiveSeasonal())->predict([null, null, null], 96, 96, [0.5]);
        });
    }
}
