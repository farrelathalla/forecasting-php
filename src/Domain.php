<?php
declare(strict_types=1);

namespace Tbw;

/**
 * Frozen modelling contract, copied from output/deploy_bundle/inference_state.json.
 *
 * Every value here traces to a finding in ../CLAUDE.md. Changing one silently makes
 * live forecasts incomparable with the backtest that selected Chronos-2, so ConfigTest
 * asserts these against the notebook's own state file.
 */
final class Domain
{
    /** 96 steps of 15 min = 24 h. */
    public const HORIZON = 96;

    /** 1344 steps = 14 days. */
    public const CONTEXT = 1344;

    /** Below this the forecast is refused rather than guessed. 192 steps = 2 days. */
    public const MIN_CONTEXT = 192;

    public const MODEL_FREQ_MIN = 15;

    /** Daily season at 15-min resolution. */
    public const SEASONALITY = 96;

    /** F6: absence of a row means "unchanged", so LOCF — but bounded, or TBW1's
     *  3-week outage propagates its last reading and looks like normal operation. */
    public const HOLD_LIMIT_MIN = 60;

    /** F5: 16-bit register underflow. Readings near 65536 must be dropped before
     *  any statistic; one of them flattens the ACF to zero. */
    public const SENTINEL_HI = 10000.0;

    /** F1: TBW2 stopped permanently on 2026-05-14 and the live API confirms it. */
    public const ERA_SPLIT = '2026-05-14 12:00:00';

    public const QUANTILES = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];

    /** Index of the 0.5 level inside QUANTILES. */
    public const MEDIAN_INDEX = 4;

    /** 24 nominal series -> 9 modelled targets (-62%), per notebook section 10. */
    public const TARGETS = [
        'HEADER_PRESSURE',
        'FLOWRATE|TBW1',
        'FLOWRATE|TBW3',
        'POWER|TBW1',
        'POWER|TBW3',
        'OUTLET_TEMP|TBW1',
        'OUTLET_TEMP|TBW3',
        'INLET_TEMP|TBW1',
        'INLET_TEMP|TBW3',
    ];

    /** Assets carrying usable history. TBW2 is retired, not missing. */
    public const ACTIVE_ASSETS = ['TBW1', 'TBW3'];

    public const RETIRED_ASSETS = ['TBW2'];

    public const SIGNALS = [
        'FLOWRATE', 'INLET_PRESS', 'INLET_TEMP', 'MOTOR_CURRENT',
        'MOTOR_RPM', 'OUTLET_PRESSURE', 'OUTLET_TEMP', 'POWER',
    ];

    /** Units confirmed by the plant, 2026-07-29. Resolves notebook open question 10.2. */
    public const UNITS = [
        'FLOWRATE'        => 'm3/min',
        'INLET_PRESS'     => 'Pa',
        'INLET_TEMP'      => "\u{00B0}C",
        'MOTOR_CURRENT'   => 'A',
        'MOTOR_RPM'       => 'rpm',
        'OUTLET_PRESSURE' => 'kg/cm2',
        'OUTLET_TEMP'     => "\u{00B0}C",
        'POWER'           => 'kW',
    ];

    /** Physics channels monitored by SPC. Not forecast — they are relationships. */
    public const PHYSICS_CHANNELS = ['dT', 'P_over_I', 'flow_per_kW', 'hyd_eff'];

    /** 1 kg/cm2 = 0.980665 bar. OUTLET_PRESSURE arrives as kg/cm2. */
    public const KGCM2_TO_BAR = 0.980665;

    /** Reconstruction grid before downsampling to MODEL_FREQ, matching CFG.GRID_FREQ. */
    public const GRID_FREQ_MIN = 1;

    /**
     * Running detection uses MOTOR_CURRENT, not MOTOR_RPM: rpm is reported on the
     * 30-minute heartbeat while current updates every 5 s, so current sees a stop first.
     * CFG.RUNNING_AMPS in the notebook.
     */
    public const RUNNING_AMPS = 50.0;

    /** MOTOR_RPM above this counts as running (F8: constant 45.9, zero when stopped). */
    public const RUNNING_RPM_MIN = 1.0;

    /** F4: INLET_PRESS sawtooth. A drop this large is a counter reset, not a process move. */
    public const RESET_DROP_THRESHOLD = 40.0;

    public static function assetsFromTarget(string $target): ?string
    {
        $parts = explode('|', $target);
        return $parts[1] ?? null;
    }

    public static function signalFromTarget(string $target): string
    {
        return explode('|', $target)[0];
    }
}
