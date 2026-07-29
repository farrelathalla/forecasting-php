<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Grid\LocfResampler;
use Tests\TestCase;

/**
 * The most important tests in the project.
 *
 * F6: the historian polls every 5 s and writes only on deadband crossing, with a 30-min
 * heartbeat. Absence of a row therefore means "the value did not change", not "the value
 * was unknown". Resampling with mean() averages only the written minutes and silently
 * drops the constant stretches; the correct operator is last-observation-carried-forward
 * with a bounded hold.
 */
final class LocfResamplerTest extends TestCase
{
    private function resampler(int $holdLimitMin = 60): LocfResampler
    {
        return new LocfResampler(1, $holdLimitMin);
    }

    /** @param list<array{string,float}> $points */
    private function points(array $points): array
    {
        return array_map(static fn(array $p) => ['ts' => $p[0], 'value' => $p[1]], $points);
    }

    public function testTakesTheLastSampleInABucketNotTheMean(): void
    {
        // 186 and 190 in the same minute must yield 190, not 188. Averaging would
        // invent a value the instrument never reported.
        $out = $this->resampler()->resample(
            $this->points([
                ['2026-07-29 13:00:10', 186.0],
                ['2026-07-29 13:00:40', 188.0],
                ['2026-07-29 13:00:55', 190.0],
            ]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:00:00'
        );
        $this->assertCount(1, $out);
        $this->assertFloatEquals(190.0, $out[0]['value']);
        $this->assertFalse($out[0]['is_held']);
    }

    public function testLabelsBucketsOnTheGridBoundaryNotTheSampleTime(): void
    {
        $out = $this->resampler()->resample(
            $this->points([['2026-07-29 13:00:37', 5.0]]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:02:00'
        );
        $this->assertSame('2026-07-29 13:00:00', $out[0]['ts']);
        $this->assertSame('2026-07-29 13:01:00', $out[1]['ts']);
        $this->assertSame('2026-07-29 13:02:00', $out[2]['ts']);
    }

    public function testCarriesTheLastObservationForwardAcrossAShortGap(): void
    {
        $out = $this->resampler(60)->resample(
            $this->points([['2026-07-29 13:00:00', 33.4]]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:30:00'
        );
        $this->assertCount(31, $out);
        foreach ($out as $i => $row) {
            $this->assertFloatEquals(33.4, $row['value'], 1e-9, "step {$i} lost the held value");
        }
        $this->assertFalse($out[0]['is_held'], 'the observed minute is not a held value');
        $this->assertTrue($out[1]['is_held'], 'filled minutes must be flagged as held');
    }

    public function testStopsHoldingOnceTheGapExceedsTheLimit(): void
    {
        // Without the bound, TBW1's three-week outage would have its last reading
        // propagated across the whole gap and look like normal operation.
        $out = $this->resampler(60)->resample(
            $this->points([['2026-07-29 13:00:00', 33.4]]),
            '2026-07-29 13:00:00',
            '2026-07-29 14:30:00'
        );
        $this->assertCount(91, $out);
        $this->assertFloatEquals(33.4, $out[60]['value'], 1e-9, '60 minutes of hold is still in bounds');
        $this->assertNull($out[61]['value'], 'past the hold limit the value is unknown, not stale');
        $this->assertNull($out[90]['value']);
    }

    public function testUnknownIsNullNotZero(): void
    {
        // Storing 0.0 for an unknown value reads as a stopped pump and poisons every
        // statistic that follows, including the physics ratios.
        $out = $this->resampler(15)->resample(
            $this->points([['2026-07-29 14:00:00', 33.4]]),
            '2026-07-29 13:00:00',
            '2026-07-29 14:00:00'
        );
        $this->assertNull($out[0]['value']);
        $this->assertFalse($out[0]['value'] === 0.0);
    }

    public function testResumesAfterTheGapWhenDataReturns(): void
    {
        $out = $this->resampler(10)->resample(
            $this->points([
                ['2026-07-29 13:00:00', 1.0],
                ['2026-07-29 13:30:00', 2.0],
            ]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:31:00'
        );
        $this->assertFloatEquals(1.0, $out[10]['value']);
        $this->assertNull($out[11]['value']);
        $this->assertNull($out[29]['value']);
        $this->assertFloatEquals(2.0, $out[30]['value']);
        $this->assertFalse($out[30]['is_held']);
    }

    public function testIgnoresSamplesBeforeTheWindowButUsesThemAsPriorState(): void
    {
        // A poll window that starts mid-hold must still know the last value, otherwise
        // every aggregation run would begin with a spurious gap.
        $out = $this->resampler(60)->resample(
            $this->points([
                ['2026-07-29 12:50:00', 7.0],
                ['2026-07-29 13:05:00', 8.0],
            ]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:05:00'
        );
        $this->assertCount(6, $out);
        $this->assertFloatEquals(7.0, $out[0]['value']);
        $this->assertTrue($out[0]['is_held']);
        $this->assertFloatEquals(8.0, $out[5]['value']);
    }

    public function testEmptyInputYieldsAllNullGrid(): void
    {
        $out = $this->resampler()->resample([], '2026-07-29 13:00:00', '2026-07-29 13:02:00');
        $this->assertCount(3, $out);
        foreach ($out as $row) {
            $this->assertNull($row['value']);
        }
    }

    public function testUnsortedInputIsHandled(): void
    {
        $out = $this->resampler()->resample(
            $this->points([
                ['2026-07-29 13:02:00', 3.0],
                ['2026-07-29 13:00:00', 1.0],
                ['2026-07-29 13:01:00', 2.0],
            ]),
            '2026-07-29 13:00:00',
            '2026-07-29 13:02:00'
        );
        $this->assertFloatEquals(1.0, $out[0]['value']);
        $this->assertFloatEquals(2.0, $out[1]['value']);
        $this->assertFloatEquals(3.0, $out[2]['value']);
    }

    public function testDownsamplesOneMinuteGridToFifteenWithMean(): void
    {
        // Matches the notebook: raw -> 1-min LOCF -> 15-min mean. The mean is correct at
        // this stage precisely because the 1-min grid is already gap-filled, so it is no
        // longer the report-by-exception trap that F6 warns about.
        $minute = [];
        for ($i = 0; $i < 30; $i++) {
            $minute[] = [
                'ts'      => (new \DateTimeImmutable('2026-07-29 13:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s'),
                'value'   => $i < 15 ? 10.0 : 20.0,
                'is_held' => false,
            ];
        }
        $out = LocfResampler::downsample($minute, 15);
        $this->assertCount(2, $out);
        $this->assertSame('2026-07-29 13:00:00', $out[0]['ts']);
        $this->assertFloatEquals(10.0, $out[0]['value']);
        $this->assertFloatEquals(20.0, $out[1]['value']);
    }

    public function testDownsampleTreatsAnAllNullBucketAsNull(): void
    {
        $minute = [];
        for ($i = 0; $i < 15; $i++) {
            $minute[] = [
                'ts'      => (new \DateTimeImmutable('2026-07-29 13:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s'),
                'value'   => null,
                'is_held' => false,
            ];
        }
        $out = LocfResampler::downsample($minute, 15);
        $this->assertCount(1, $out);
        $this->assertNull($out[0]['value']);
    }

    public function testDownsampleMarksBucketHeldOnlyWhenEveryMinuteWasHeld(): void
    {
        $minute = [];
        for ($i = 0; $i < 15; $i++) {
            $minute[] = [
                'ts'      => (new \DateTimeImmutable('2026-07-29 13:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s'),
                'value'   => 5.0,
                'is_held' => $i !== 3,
            ];
        }
        $out = LocfResampler::downsample($minute, 15);
        $this->assertFalse($out[0]['is_held'], 'one fresh reading in the bucket makes it a real observation');
    }
}
