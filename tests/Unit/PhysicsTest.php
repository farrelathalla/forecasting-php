<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Physics\PhysicsCalculator;
use Tests\TestCase;

/**
 * Physics ratios are the detector for the plausible-looking fault: a signal that sits
 * inside its normal range while its relationship to the others has broken. Formulas are
 * copied from add_physics() in the notebook builder — the frozen SPC limits are only
 * meaningful against the identical formula.
 */
final class PhysicsTest extends TestCase
{
    private const T = '2026-07-29 13:00:00';

    private function inputs(array $overrides = []): array
    {
        return array_merge([
            'HEADER_PRESSURE'    => 5.18,
            'FLOWRATE|TBW1'      => 33.4,
            'POWER|TBW1'         => 183.5,
            'MOTOR_CURRENT|TBW1' => 183.5,
            'OUTLET_TEMP|TBW1'   => 43.8,
            'INLET_TEMP|TBW1'    => 38.6,
        ], $overrides);
    }

    private function compute(array $values, bool $running = true): array
    {
        $series = [];
        foreach ($values as $k => $v) {
            $series[$k] = [self::T => $v];
        }
        return (new PhysicsCalculator())->compute(
            $series,
            ['TBW1' => [self::T => $running]],
            [self::T]
        );
    }

    public function testDischargeTemperatureRise(): void
    {
        // F2: the degradation signature. dT climbs from -10 C in April to +7 C in July.
        $out = $this->compute($this->inputs());
        $this->assertFloatEquals(5.2, $out['dT|TBW1'][self::T], 1e-9);
    }

    public function testDtIsSignedAndMayBeNegative(): void
    {
        // Outlet colder than inlet is not physically possible across a pump, but it is
        // what the sensors reported in late April. The trend is diagnostic; the absolute
        // level is not. Clamping it at zero would destroy the finding.
        $out = $this->compute($this->inputs(['OUTLET_TEMP|TBW1' => 28.0, 'INLET_TEMP|TBW1' => 38.0]));
        $this->assertFloatEquals(-10.0, $out['dT|TBW1'][self::T], 1e-9);
    }

    public function testPowerOverCurrent(): void
    {
        $out = $this->compute($this->inputs(['POWER|TBW1' => 186.0, 'MOTOR_CURRENT|TBW1' => 190.0]));
        $this->assertFloatEquals(186.0 / 190.0, $out['P_over_I|TBW1'][self::T], 1e-12);
    }

    public function testPowerOverCurrentGuardsAgainstZeroCurrent(): void
    {
        $out = $this->compute($this->inputs(['MOTOR_CURRENT|TBW1' => 0.0]), false);
        $this->assertNull($out['P_over_I|TBW1'][self::T]);
    }

    public function testFlowPerKilowatt(): void
    {
        $out = $this->compute($this->inputs());
        $this->assertFloatEquals(33.4 / 183.5, $out['flow_per_kW|TBW1'][self::T], 1e-12);
    }

    public function testHydraulicEfficiencyProxyMatchesTheNotebookFormula(): void
    {
        // hyd_eff = HEADER_PRESSURE * FLOWRATE / POWER, exactly as add_physics() defines
        // it. A dimensionally "better" formula would not be comparable with the frozen
        // control limits, which is what makes it the wrong choice here.
        $out = $this->compute($this->inputs());
        $this->assertFloatEquals(5.18 * 33.4 / 183.5, $out['hyd_eff|TBW1'][self::T], 1e-12);
    }

    public function testSpecificPower(): void
    {
        $out = $this->compute($this->inputs());
        $this->assertFloatEquals(183.5 / 33.4, $out['spec_power|TBW1'][self::T], 1e-12);
    }

    public function testEveryChannelIsNullWhileTheAssetIsStopped(): void
    {
        // Ratios computed across a stoppage are arithmetic noise. Letting them through
        // would light up every SPC channel at each start and stop, and an alarm that
        // fires on normal operation is an alarm the control room mutes.
        $out = $this->compute($this->inputs(['POWER|TBW1' => 0.0, 'MOTOR_CURRENT|TBW1' => 0.0, 'FLOWRATE|TBW1' => 0.0]), false);
        foreach (['dT|TBW1', 'P_over_I|TBW1', 'flow_per_kW|TBW1', 'hyd_eff|TBW1', 'spec_power|TBW1'] as $channel) {
            $this->assertNull($out[$channel][self::T], "{$channel} must be null while stopped");
        }
    }

    public function testMissingInputYieldsNull(): void
    {
        $inputs = $this->inputs();
        unset($inputs['FLOWRATE|TBW1']);
        $out = $this->compute($inputs);
        $this->assertNull($out['flow_per_kW|TBW1'][self::T]);
        $this->assertNull($out['hyd_eff|TBW1'][self::T]);
        $this->assertFloatEquals(5.2, $out['dT|TBW1'][self::T], 1e-9, 'unrelated channels still compute');
    }

    public function testDivisionByZeroPowerYieldsNullNotInfinity(): void
    {
        $out = $this->compute($this->inputs(['POWER|TBW1' => 0.0]));
        $this->assertNull($out['flow_per_kW|TBW1'][self::T]);
        $this->assertNull($out['hyd_eff|TBW1'][self::T]);
    }

    public function testEmitsChannelsForEveryActiveAsset(): void
    {
        $series = [];
        foreach ($this->inputs() as $k => $v) {
            $series[$k] = [self::T => $v];
        }
        foreach (['FLOWRATE|TBW3' => 29.5, 'POWER|TBW3' => 171.4, 'MOTOR_CURRENT|TBW3' => 183.9,
                  'OUTLET_TEMP|TBW3' => 44.7, 'INLET_TEMP|TBW3' => 41.2] as $k => $v) {
            $series[$k] = [self::T => $v];
        }
        $out = (new PhysicsCalculator())->compute(
            $series,
            ['TBW1' => [self::T => true], 'TBW3' => [self::T => true]],
            [self::T]
        );
        $this->assertFloatEquals(3.5, $out['dT|TBW3'][self::T], 1e-9);
        $this->assertFloatEquals(29.5 / 171.4, $out['flow_per_kW|TBW3'][self::T], 1e-12);
    }
}
