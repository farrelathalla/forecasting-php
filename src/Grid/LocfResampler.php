<?php
declare(strict_types=1);

namespace Tbw\Grid;

use DateTimeImmutable;
use Tbw\Domain;

/**
 * Report-by-exception reconstruction (F6).
 *
 * The historian polls every 5 s and writes only when the value crosses the deadband,
 * with a 30-minute heartbeat. A missing row means "unchanged", so the grid is rebuilt
 * with last-observation-carried-forward — but bounded, because an unbounded hold turns
 * a three-week outage into a flat line that looks like normal operation.
 */
final class LocfResampler
{
    public function __construct(
        private int $freqMin = Domain::GRID_FREQ_MIN,
        private int $holdLimitMin = Domain::HOLD_LIMIT_MIN,
    ) {
    }

    /**
     * @param list<array{ts:string,value:float}> $points  need not be sorted
     * @return list<array{ts:string,value:?float,is_held:bool}>
     */
    public function resample(array $points, string $from, string $to): array
    {
        $fromTs = (new DateTimeImmutable($from))->getTimestamp();
        $toTs = (new DateTimeImmutable($to))->getTimestamp();
        $step = $this->freqMin * 60;
        $holdSteps = intdiv($this->holdLimitMin, $this->freqMin);

        $fromTs = intdiv($fromTs, $step) * $step;
        $toTs = intdiv($toTs, $step) * $step;

        // Bucket -> last value in that bucket. Last, never mean.
        $byBucket = [];
        $priorValue = null;
        $priorBucket = null;
        foreach ($points as $p) {
            $ts = (new DateTimeImmutable($p['ts']))->getTimestamp();
            $bucket = intdiv($ts, $step) * $step;
            if ($bucket < $fromTs) {
                // Outside the window, but it is the state the window opens with.
                if ($priorBucket === null || $bucket >= $priorBucket) {
                    $priorBucket = $bucket;
                    $priorValue = (float) $p['value'];
                }
                continue;
            }
            if ($bucket > $toTs) {
                continue;
            }
            if (!isset($byBucket[$bucket]) || $ts >= $byBucket[$bucket]['at']) {
                $byBucket[$bucket] = ['at' => $ts, 'value' => (float) $p['value']];
            }
        }

        $out = [];
        $held = $priorValue;
        // How many steps ago the last real observation was. Starts at the limit so a
        // stale prior value cannot be carried further than a fresh one would be.
        $heldFor = $priorValue === null ? PHP_INT_MAX : $this->stepsBetween($priorBucket, $fromTs, $step);

        for ($t = $fromTs; $t <= $toTs; $t += $step) {
            if (isset($byBucket[$t])) {
                $held = $byBucket[$t]['value'];
                $heldFor = 0;
                $out[] = ['ts' => date('Y-m-d H:i:s', $t), 'value' => $held, 'is_held' => false];
                continue;
            }
            if ($held !== null && $heldFor < $holdSteps) {
                $heldFor++;
                $out[] = ['ts' => date('Y-m-d H:i:s', $t), 'value' => $held, 'is_held' => true];
                continue;
            }
            $held = null;
            $out[] = ['ts' => date('Y-m-d H:i:s', $t), 'value' => null, 'is_held' => false];
        }

        return $out;
    }

    private function stepsBetween(?int $fromBucket, int $toBucket, int $step): int
    {
        if ($fromBucket === null) {
            return PHP_INT_MAX;
        }
        return (int) max(0, ($toBucket - $fromBucket) / $step);
    }

    /**
     * 1-minute grid -> MODEL_FREQ grid by mean, matching the notebook
     * (M = G.resample('15min').mean()).
     *
     * The mean is correct here and only here: the input is already gap-filled, so this
     * is no longer the report-by-exception trap F6 warns about — it is a genuine
     * average of known values.
     *
     * @param list<array{ts:string,value:?float,is_held:bool}> $minuteGrid
     * @return list<array{ts:string,value:?float,is_held:bool}>
     */
    public static function downsample(array $minuteGrid, int $targetFreqMin = Domain::MODEL_FREQ_MIN): array
    {
        $step = $targetFreqMin * 60;
        $buckets = [];
        foreach ($minuteGrid as $row) {
            $ts = (new DateTimeImmutable($row['ts']))->getTimestamp();
            $bucket = intdiv($ts, $step) * $step;
            $buckets[$bucket] ??= ['sum' => 0.0, 'n' => 0, 'allHeld' => true];
            if ($row['value'] === null) {
                continue;
            }
            $buckets[$bucket]['sum'] += (float) $row['value'];
            $buckets[$bucket]['n']++;
            if (!($row['is_held'] ?? false)) {
                $buckets[$bucket]['allHeld'] = false;
            }
        }
        ksort($buckets);

        $out = [];
        foreach ($buckets as $bucket => $agg) {
            $out[] = [
                'ts'      => date('Y-m-d H:i:s', $bucket),
                'value'   => $agg['n'] === 0 ? null : $agg['sum'] / $agg['n'],
                'is_held' => $agg['n'] > 0 && $agg['allHeld'],
            ];
        }
        return $out;
    }
}
