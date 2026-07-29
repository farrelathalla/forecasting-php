<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Grid\TargetBuilder;
use Tests\TestCase;

/**
 * Notebook section 10: 24 nominal series -> 9 modelled targets, losing nothing
 * forecastable. Each rule here is a finding, not a preference.
 */
final class TargetBuilderTest extends TestCase
{
    private const T1 = '2026-07-29 13:00:00';
    private const T2 = '2026-07-29 13:15:00';

    /** A full, healthy two-asset panel at one timestamp. */
    private function panel(array $overrides = []): array
    {
        $base = [
            'FLOWRATE|TBW1' => 33.4, 'FLOWRATE|TBW3' => 29.5,
            'INLET_PRESS|TBW1' => 201.0, 'INLET_PRESS|TBW3' => 78.0,
            'INLET_TEMP|TBW1' => 38.6, 'INLET_TEMP|TBW3' => 41.2,
            'MOTOR_CURRENT|TBW1' => 183.5, 'MOTOR_CURRENT|TBW3' => 183.9,
            'MOTOR_RPM|TBW1' => 45.9, 'MOTOR_RPM|TBW3' => 45.9,
            'OUTLET_PRESSURE|TBW1' => 5.19, 'OUTLET_PRESSURE|TBW3' => 5.17,
            'OUTLET_TEMP|TBW1' => 43.8, 'OUTLET_TEMP|TBW3' => 44.7,
            'POWER|TBW1' => 183.5, 'POWER|TBW3' => 171.4,
        ];
        $merged = array_merge($base, $overrides);

        $series = [];
        foreach ($merged as $key => $value) {
            $series[$key] = [self::T1 => $value];
        }
        return $series;
    }

    public function testProducesExactlyTheNineNotebookTargets(): void
    {
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        $this->assertCount(9, $out['targets']);
        foreach (Domain::TARGETS as $t) {
            $this->assertTrue(isset($out['targets'][$t]), "missing target {$t}");
        }
    }

    public function testCollapsesOutletPressureIntoOneStationTarget(): void
    {
        // F7: one shared header measured three times. corr(TBW1,TBW3)=0.964,
        // median |diff| under 1% of a 4.9 level. Modelling it per asset triple-counts
        // an easy series and corrupts any averaged benchmark.
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        $this->assertFloatEquals(5.18, $out['targets']['HEADER_PRESSURE'][self::T1], 1e-9);

        foreach (array_keys($out['targets']) as $t) {
            $this->assertFalse(str_contains($t, 'OUTLET_PRESSURE'), 'per-asset header pressure must not survive');
        }
    }

    public function testHeaderPressureUsesOnlyRunningAssets(): void
    {
        // A stopped pump still reports a header reading through its own transducer, but
        // that reading is not the running station's header. Including it drags the median.
        $panel = $this->panel(['MOTOR_CURRENT|TBW3' => 0.0, 'OUTLET_PRESSURE|TBW3' => 0.2]);
        $out = (new TargetBuilder())->build($panel, [self::T1]);
        $this->assertFloatEquals(5.19, $out['targets']['HEADER_PRESSURE'][self::T1], 1e-9);
    }

    public function testHeaderPressureIsNullWhenNothingIsRunning(): void
    {
        $panel = $this->panel(['MOTOR_CURRENT|TBW1' => 0.0, 'MOTOR_CURRENT|TBW3' => 0.0]);
        $out = (new TargetBuilder())->build($panel, [self::T1]);
        $this->assertNull($out['targets']['HEADER_PRESSURE'][self::T1], 'null, never 0.0');
    }

    public function testDetectsRunningFromMotorCurrentNotRpm(): void
    {
        // Current updates every 5 s; rpm only on the 30-min heartbeat. Current sees a
        // stop first, which is what the notebook uses (CFG.RUNNING_AMPS = 50).
        $panel = $this->panel(['MOTOR_CURRENT|TBW1' => 12.0, 'MOTOR_RPM|TBW1' => 45.9]);
        $out = (new TargetBuilder())->build($panel, [self::T1]);
        $this->assertFalse($out['running']['TBW1'][self::T1]);
        $this->assertTrue($out['running']['TBW3'][self::T1]);
        $this->assertSame(1, $out['n_running'][self::T1]);
    }

    public function testNeverEmitsMotorRpmAsATarget(): void
    {
        // F8: constant 45.9, CV ~ 0. Every model scores near-zero error on it, which
        // inflates any averaged benchmark. Keep it as a flag, never as a target.
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        foreach (array_keys($out['targets']) as $t) {
            $this->assertFalse(str_contains($t, 'MOTOR_RPM'));
        }
    }

    public function testNeverEmitsMotorCurrentAsATarget(): void
    {
        // F7: r = 0.976-0.993 with POWER at fixed speed. Derive it, monitor the ratio.
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        foreach (array_keys($out['targets']) as $t) {
            $this->assertFalse(str_contains($t, 'MOTOR_CURRENT'));
        }
    }

    public function testNeverEmitsInletPressLevelAsATarget(): void
    {
        // F4: a sawtooth that resets at every stop. A level forecaster extrapolates
        // straight through the next reset and is catastrophically wrong exactly then.
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        foreach (array_keys($out['targets']) as $t) {
            $this->assertFalse(str_contains($t, 'INLET_PRESS'));
        }
    }

    public function testEmitsInletPressAsResetAwareAuxiliaryChannels(): void
    {
        $ts = [];
        $values = [];
        // A clean ramp, then a reset, then a fresh ramp.
        $levels = [100.0, 110.0, 120.0, 130.0, 60.0, 70.0, 80.0];
        for ($i = 0; $i < count($levels); $i++) {
            $t = (new \DateTimeImmutable(self::T1))->modify('+' . ($i * 15) . ' minutes')->format('Y-m-d H:i:s');
            $ts[] = $t;
            $values[$t] = $levels[$i];
        }
        $panel = [];
        foreach ($this->panel() as $key => $series) {
            $panel[$key] = array_fill_keys($ts, reset($series));
        }
        $panel['INLET_PRESS|TBW1'] = $values;

        $out = (new TargetBuilder())->build($panel, $ts);

        $this->assertTrue(isset($out['aux']['INLET_PRESS_level|TBW1']));
        $this->assertTrue(isset($out['aux']['IP_hours_since_reset|TBW1']));
        $this->assertTrue(isset($out['aux']['IP_slope|TBW1']));

        $tsr = $out['aux']['IP_hours_since_reset|TBW1'];
        $this->assertFloatEquals(0.0, $tsr[$ts[4]], 1e-9, 'the reset step restarts the counter');
        $this->assertGreaterThan(0.0, $tsr[$ts[3]]);
        $this->assertGreaterThan($tsr[$ts[4]], $tsr[$ts[5]]);
    }

    public function testPassesThroughPooledTargetsUnchanged(): void
    {
        $out = (new TargetBuilder())->build($this->panel(), [self::T1]);
        $this->assertFloatEquals(33.4, $out['targets']['FLOWRATE|TBW1'][self::T1]);
        $this->assertFloatEquals(171.4, $out['targets']['POWER|TBW3'][self::T1]);
        $this->assertFloatEquals(43.8, $out['targets']['OUTLET_TEMP|TBW1'][self::T1]);
        $this->assertFloatEquals(41.2, $out['targets']['INLET_TEMP|TBW3'][self::T1]);
    }

    public function testMissingSignalYieldsNullNotZero(): void
    {
        $panel = $this->panel();
        unset($panel['POWER|TBW1']);
        $out = (new TargetBuilder())->build($panel, [self::T1]);
        $this->assertNull($out['targets']['POWER|TBW1'][self::T1]);
    }

    public function testEveryTargetCoversEveryRequestedTimestamp(): void
    {
        $panel = $this->panel();
        foreach ($panel as $k => $v) {
            $panel[$k] = [self::T1 => $v[self::T1]];
        }
        $out = (new TargetBuilder())->build($panel, [self::T1, self::T2]);
        foreach (Domain::TARGETS as $t) {
            $this->assertCount(2, $out['targets'][$t], "{$t} must be dense over the requested grid");
            $this->assertNull($out['targets'][$t][self::T2]);
        }
    }
}
