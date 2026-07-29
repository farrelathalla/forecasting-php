<?php
declare(strict_types=1);

namespace Tbw\Scoring;

use Tbw\Domain;

/**
 * Forecast accuracy metrics, matching notebook section 6.
 *
 * Every method ignores null actuals rather than treating them as zero. A gap is missing
 * evidence, and scoring it as zero would reward a model for the hours the plant was not
 * reporting.
 */
final class Metrics
{
    /**
     * Mean Absolute Scaled Error against a seasonal-naive denominator.
     *
     * The headline point metric because it is dimensionless: POWER at ~187 kW and
     * HEADER_PRESSURE at ~4.9 kg/cm2 have to be averageable across the panel, and RMSE
     * would let power dominate the mean entirely. Below 1.0 beats seasonal naive.
     *
     * @param list<?float> $actual
     * @param list<?float> $forecast
     * @param list<?float> $history training values used for the scaling denominator
     */
    public static function mase(
        array $actual,
        array $forecast,
        array $history,
        int $seasonality = Domain::SEASONALITY,
    ): ?float {
        $mae = self::mae($actual, $forecast);
        if ($mae === null) {
            return null;
        }

        $diffs = [];
        $n = count($history);
        for ($i = $seasonality; $i < $n; $i++) {
            $a = $history[$i] ?? null;
            $b = $history[$i - $seasonality] ?? null;
            if ($a !== null && $b !== null && is_finite($a) && is_finite($b)) {
                $diffs[] = abs($a - $b);
            }
        }
        if ($diffs === []) {
            return null;
        }
        $scale = array_sum($diffs) / count($diffs);
        if ($scale < 1e-12) {
            return null;
        }
        return $mae / $scale;
    }

    /**
     * Weighted quantile loss: mean pinball loss across the quantile levels.
     *
     * The probabilistic headline. The early-warning use case depends on interval quality
     * rather than point accuracy, which is why Chronos-2 deploys despite losing MASE to
     * LGBM-Local by 0.028 — it wins WQL, coverage and cross-fold stability at once.
     *
     * @param list<?float> $actual
     * @param array<string,list<?float>> $quantiles level => column
     */
    public static function wql(array $actual, array $quantiles): ?float
    {
        $total = 0.0;
        $count = 0;

        foreach ($quantiles as $level => $column) {
            $q = (float) $level;
            foreach ($actual as $i => $y) {
                $f = $column[$i] ?? null;
                if ($y === null || $f === null || !is_finite($y) || !is_finite($f)) {
                    continue;
                }
                // Pinball: under-forecasting and over-forecasting are not punished the
                // same, and the asymmetry is what makes the quantile meaningful.
                $total += $y >= $f ? $q * ($y - $f) : (1 - $q) * ($f - $y);
                $count++;
            }
        }
        return $count === 0 ? null : $total / $count;
    }

    /**
     * Empirical coverage of a prediction interval.
     *
     * Reported separately from WQL on purpose: a model can hold good WQL while being
     * systematically over-confident, and an under-covering forecaster inflates the
     * standardised residual until the CUSUM never stops alarming.
     *
     * @param list<?float> $actual
     * @param list<?float> $lower
     * @param list<?float> $upper
     */
    public static function coverage(array $actual, array $lower, array $upper): ?float
    {
        $inside = 0;
        $count = 0;
        foreach ($actual as $i => $y) {
            $lo = $lower[$i] ?? null;
            $hi = $upper[$i] ?? null;
            if ($y === null || $lo === null || $hi === null) {
                continue;
            }
            $count++;
            if ($y >= $lo && $y <= $hi) {
                $inside++;
            }
        }
        return $count === 0 ? null : $inside / $count;
    }

    /**
     * @param list<?float> $actual
     * @param list<?float> $forecast
     */
    public static function mae(array $actual, array $forecast): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($actual as $i => $y) {
            $f = $forecast[$i] ?? null;
            if ($y === null || $f === null || !is_finite($y) || !is_finite($f)) {
                continue;
            }
            $sum += abs($y - $f);
            $count++;
        }
        return $count === 0 ? null : $sum / $count;
    }

    /**
     * @param list<?float> $actual
     * @param list<?float> $forecast
     */
    public static function rmse(array $actual, array $forecast): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($actual as $i => $y) {
            $f = $forecast[$i] ?? null;
            if ($y === null || $f === null || !is_finite($y) || !is_finite($f)) {
                continue;
            }
            $sum += ($y - $f) ** 2;
            $count++;
        }
        return $count === 0 ? null : sqrt($sum / $count);
    }

    /**
     * @param list<?float> $actual
     * @param list<?float> $forecast
     */
    public static function smape(array $actual, array $forecast): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($actual as $i => $y) {
            $f = $forecast[$i] ?? null;
            if ($y === null || $f === null) {
                continue;
            }
            $denominator = (abs($y) + abs($f)) / 2.0;
            if ($denominator < 1e-12) {
                continue;
            }
            $sum += abs($y - $f) / $denominator;
            $count++;
        }
        return $count === 0 ? null : $sum / $count * 100.0;
    }

    /**
     * Error growth across the horizon. Section 14 showed TimesFM wins the first hour and
     * Chronos-2 wins everything past it; since the alarm layer runs at 24 h, the far
     * buckets are the ones that decide the deployed model.
     *
     * @param array<int,float> $errorsByStep horizon step (1-based) => error
     * @return array<string,?float>
     */
    public static function horizonBuckets(array $errorsByStep): array
    {
        $buckets = ['0-1h' => [], '1-4h' => [], '4-12h' => [], '12-24h' => []];
        foreach ($errorsByStep as $step => $error) {
            if ($error === null || !is_finite($error)) {
                continue;
            }
            $minutes = $step * Domain::MODEL_FREQ_MIN;
            $key = match (true) {
                $minutes <= 60   => '0-1h',
                $minutes <= 240  => '1-4h',
                $minutes <= 720  => '4-12h',
                default          => '12-24h',
            };
            $buckets[$key][] = (float) $error;
        }
        $out = [];
        foreach ($buckets as $key => $values) {
            $out[$key] = $values === [] ? null : array_sum($values) / count($values);
        }
        return $out;
    }

    /**
     * Standard deviation across runs. Reported alongside the mean because it decides
     * the argument: LGBM-Local's 6.4% MASE edge over Chronos-2 is smaller than its own
     * cross-fold SD of 0.690, so the edge is not real.
     *
     * @param list<?float> $values
     */
    public static function sd(array $values): ?float
    {
        $known = [];
        foreach ($values as $v) {
            if ($v !== null && is_finite($v)) {
                $known[] = (float) $v;
            }
        }
        $n = count($known);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($known) / $n;
        $sum = 0.0;
        foreach ($known as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / ($n - 1));
    }

    /** @param list<?float> $values */
    public static function mean(array $values): ?float
    {
        $known = [];
        foreach ($values as $v) {
            if ($v !== null && is_finite($v)) {
                $known[] = (float) $v;
            }
        }
        return $known === [] ? null : array_sum($known) / count($known);
    }
}
