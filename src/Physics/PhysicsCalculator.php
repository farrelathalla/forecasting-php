<?php
declare(strict_types=1);

namespace Tbw\Physics;

use Tbw\Domain;

/**
 * Physics relationships monitored by SPC.
 *
 * Formulas match add_physics() in the notebook builder exactly. That matters more than
 * dimensional elegance: the control limits in config/spc_limits.csv were fitted on these
 * definitions, and a "better" formula would silently invalidate every one of them.
 *
 *   dT          OUTLET_TEMP - INLET_TEMP           the F2 degradation signature
 *   P_over_I    POWER / MOTOR_CURRENT              electrical fault channel (F7)
 *   flow_per_kW FLOWRATE / POWER                   hydraulic efficiency proxy
 *   spec_power  POWER / FLOWRATE                   its reciprocal, kept for readability
 *   hyd_eff     HEADER_PRESSURE * FLOWRATE / POWER head x flow over shaft power
 */
final class PhysicsCalculator
{
    /**
     * @param array<string,array<string,?float>> $series   targets + raw signals, ts-keyed
     * @param array<string,array<string,bool>>   $running  asset => ts => running
     * @param list<string>                       $timestamps
     * @return array<string,array<string,?float>> channel => ts => value
     */
    public function compute(array $series, array $running, array $timestamps): array
    {
        $out = [];

        foreach (array_keys($running) as $asset) {
            $channels = [
                'dT'          => [],
                'P_over_I'    => [],
                'flow_per_kW' => [],
                'spec_power'  => [],
                'hyd_eff'     => [],
            ];

            foreach ($timestamps as $ts) {
                // A ratio computed across a stoppage is arithmetic noise, and letting it
                // through would light up every channel at each start and stop.
                if (!($running[$asset][$ts] ?? false)) {
                    foreach ($channels as $name => $_) {
                        $channels[$name][$ts] = null;
                    }
                    continue;
                }

                $outletTemp = self::at($series, "OUTLET_TEMP|{$asset}", $ts);
                $inletTemp  = self::at($series, "INLET_TEMP|{$asset}", $ts);
                $power      = self::at($series, "POWER|{$asset}", $ts);
                $current    = self::at($series, "MOTOR_CURRENT|{$asset}", $ts);
                $flow       = self::at($series, "FLOWRATE|{$asset}", $ts);
                $header     = self::at($series, 'HEADER_PRESSURE', $ts);

                $channels['dT'][$ts] = ($outletTemp === null || $inletTemp === null)
                    ? null
                    : $outletTemp - $inletTemp;

                $channels['P_over_I'][$ts] = self::ratio($power, $current);
                $channels['flow_per_kW'][$ts] = self::ratio($flow, $power);
                $channels['spec_power'][$ts] = self::ratio($power, $flow);
                $channels['hyd_eff'][$ts] = ($header === null || $flow === null)
                    ? null
                    : self::ratio($header * $flow, $power);
            }

            foreach ($channels as $name => $column) {
                $out[$name . '|' . $asset] = $column;
            }
        }

        return $out;
    }

    /** Channels that carry frozen SPC control limits. */
    public static function spcChannels(): array
    {
        $out = [];
        foreach (Domain::PHYSICS_CHANNELS as $channel) {
            foreach (Domain::ACTIVE_ASSETS as $asset) {
                $out[] = $channel . '|' . $asset;
            }
        }
        return $out;
    }

    /** Division that returns null rather than INF/NAN, so nothing downstream has to guard. */
    private static function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null) {
            return null;
        }
        if (abs($denominator) < 1e-9) {
            return null;
        }
        $r = $numerator / $denominator;
        return is_finite($r) ? $r : null;
    }

    /** @param array<string,array<string,?float>> $series */
    private static function at(array $series, string $key, string $ts): ?float
    {
        $v = $series[$key][$ts] ?? null;
        return $v === null ? null : (float) $v;
    }
}
