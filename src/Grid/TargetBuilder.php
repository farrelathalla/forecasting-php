<?php
declare(strict_types=1);

namespace Tbw\Grid;

use DateTimeImmutable;
use Tbw\Domain;

/**
 * Turns the per-signal grid into the 9 modelled targets of notebook section 10, plus the
 * operating-state flags and the reset-aware INLET_PRESS auxiliaries.
 *
 * 24 nominal series -> 9 targets (-62%), losing nothing forecastable:
 *   DROP     MOTOR_RPM        CV ~ 0 (F8), kept only as is_running
 *   STATION  HEADER_PRESSURE  3 header tags collapsed to 1 (F7)
 *   POOLED   FLOWRATE, POWER, OUTLET_TEMP, INLET_TEMP x {TBW1, TBW3}
 *   SPECIAL  INLET_PRESS      sawtooth, never forecast as a level (F4)
 *   DERIVED  MOTOR_CURRENT    r ~ 0.98 with POWER (F7)
 */
final class TargetBuilder
{
    private const POOLED_SIGNALS = ['FLOWRATE', 'POWER', 'OUTLET_TEMP', 'INLET_TEMP'];

    /**
     * @param array<string,array<string,?float>> $series  "SIGNAL|ASSET" => ts => value
     * @param list<string>                       $timestamps
     * @return array{
     *   targets: array<string,array<string,?float>>,
     *   running: array<string,array<string,bool>>,
     *   n_running: array<string,int>,
     *   aux: array<string,array<string,?float>>
     * }
     */
    public function build(array $series, array $timestamps): array
    {
        $running = $this->runningFlags($series, $timestamps);

        $targets = [];
        $targets['HEADER_PRESSURE'] = $this->headerPressure($series, $running, $timestamps);

        foreach (self::POOLED_SIGNALS as $signal) {
            foreach (Domain::ACTIVE_ASSETS as $asset) {
                $key = $signal . '|' . $asset;
                $column = [];
                foreach ($timestamps as $ts) {
                    $column[$ts] = self::valueAt($series, $key, $ts);
                }
                $targets[$key] = $column;
            }
        }

        $nRunning = [];
        foreach ($timestamps as $ts) {
            $n = 0;
            foreach ($running as $flags) {
                if ($flags[$ts] ?? false) {
                    $n++;
                }
            }
            $nRunning[$ts] = $n;
        }

        return [
            'targets'   => $targets,
            'running'   => $running,
            'n_running' => $nRunning,
            'aux'       => $this->inletPressAuxiliaries($series, $timestamps),
        ];
    }

    /**
     * F8/notebook: running is read from MOTOR_CURRENT, not MOTOR_RPM. Current updates
     * every 5 s while rpm only arrives on the 30-minute heartbeat, so current registers
     * a stop first. Falls back to rpm when current is missing.
     *
     * @param array<string,array<string,?float>> $series
     * @param list<string> $timestamps
     * @return array<string,array<string,bool>>
     */
    public function runningFlags(array $series, array $timestamps): array
    {
        $out = [];
        foreach (Domain::ACTIVE_ASSETS as $asset) {
            $flags = [];
            foreach ($timestamps as $ts) {
                $amps = self::valueAt($series, 'MOTOR_CURRENT|' . $asset, $ts);
                if ($amps !== null) {
                    $flags[$ts] = $amps > Domain::RUNNING_AMPS;
                    continue;
                }
                $rpm = self::valueAt($series, 'MOTOR_RPM|' . $asset, $ts);
                $flags[$ts] = $rpm !== null && $rpm > Domain::RUNNING_RPM_MIN;
            }
            $out[$asset] = $flags;
        }
        return $out;
    }

    /**
     * F7: OUTLET_PRESSURE is one shared header measured three times, so it collapses to
     * a single station target — the median across running assets. A stopped pump still
     * reports through its own transducer, but that is not the running header, so it is
     * masked out first.
     *
     * @param array<string,array<string,?float>> $series
     * @param array<string,array<string,bool>>   $running
     * @param list<string>                       $timestamps
     * @return array<string,?float>
     */
    public function headerPressure(array $series, array $running, array $timestamps): array
    {
        $out = [];
        foreach ($timestamps as $ts) {
            $values = [];
            foreach (Domain::ACTIVE_ASSETS as $asset) {
                if (!($running[$asset][$ts] ?? false)) {
                    continue;
                }
                $v = self::valueAt($series, 'OUTLET_PRESSURE|' . $asset, $ts);
                if ($v !== null) {
                    $values[] = $v;
                }
            }
            $out[$ts] = $values === [] ? null : self::median($values);
        }
        return $out;
    }

    /**
     * F4: INLET_PRESS is a monotonically accumulating counter cleared on shutdown, not a
     * pressure. Every reset instant coincides with a stoppage of the same asset. So we
     * keep the level for trend projection but expose hours-since-reset and ramp slope,
     * which are the parts that behave.
     *
     * @param array<string,array<string,?float>> $series
     * @param list<string> $timestamps
     * @return array<string,array<string,?float>>
     */
    public function inletPressAuxiliaries(array $series, array $timestamps): array
    {
        $out = [];
        foreach (Domain::ACTIVE_ASSETS as $asset) {
            $key = 'INLET_PRESS|' . $asset;
            $level = [];
            $sinceReset = [];
            $slope = [];

            $lastValue = null;
            $lastResetTs = null;
            $stepHours = Domain::MODEL_FREQ_MIN / 60.0;

            foreach ($timestamps as $ts) {
                $v = self::valueAt($series, $key, $ts);
                $level[$ts] = $v;

                if ($v === null) {
                    $sinceReset[$ts] = null;
                    $slope[$ts] = null;
                    continue;
                }
                $isReset = $lastValue !== null && ($v - $lastValue) < -Domain::RESET_DROP_THRESHOLD;
                if ($isReset || $lastResetTs === null) {
                    $lastResetTs = $ts;
                }
                $sinceReset[$ts] = (new DateTimeImmutable($ts))->getTimestamp()
                    - (new DateTimeImmutable($lastResetTs))->getTimestamp();
                $sinceReset[$ts] /= 3600.0;

                // Units per day. A reset step is not a slope, so it is skipped.
                $slope[$ts] = ($lastValue === null || $isReset)
                    ? null
                    : ($v - $lastValue) / $stepHours * 24.0;

                $lastValue = $v;
            }

            $out['INLET_PRESS_level|' . $asset] = $level;
            $out['IP_hours_since_reset|' . $asset] = $sinceReset;
            $out['IP_slope|' . $asset] = $slope;
        }
        return $out;
    }

    /** @param array<string,array<string,?float>> $series */
    private static function valueAt(array $series, string $key, string $ts): ?float
    {
        $v = $series[$key][$ts] ?? null;
        return $v === null ? null : (float) $v;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
