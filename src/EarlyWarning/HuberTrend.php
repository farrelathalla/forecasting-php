<?php
declare(strict_types=1);

namespace Tbw\EarlyWarning;

use DateTimeImmutable;

/**
 * Robust linear trend, fitted by iteratively reweighted least squares with Huber weights.
 *
 * Catches the scheduled: a drift extrapolated to an operating limit becomes a date, and
 * a date can go on a work order. An alarm light cannot.
 *
 * Huber, not OLS. The station has 16 stoppage episodes in 90 days, each of which drops
 * readings to zero for a while. Ordinary least squares chases those points and reports a
 * trend that mostly describes the trips. The advantage is asserted in the tests, not
 * assumed.
 */
final class HuberTrend
{
    public function __construct(
        private float $delta = 1.345,
        private int $maxIterations = 50,
        private float $tolerance = 1e-10,
    ) {
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     * @return array{slope:float,intercept:float,n:int}
     */
    public function fit(array $x, array $y): array
    {
        $n = count($x);
        if ($n !== count($y)) {
            throw new \InvalidArgumentException('x and y must be the same length');
        }
        if ($n < 2) {
            throw new \InvalidArgumentException('need at least two points to fit a trend');
        }

        $fit = self::ols($x, $y);
        $slope = $fit['slope'];
        $intercept = $fit['intercept'];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $residuals = [];
            for ($i = 0; $i < $n; $i++) {
                $residuals[] = $y[$i] - ($intercept + $slope * $x[$i]);
            }

            // MAD-based scale: the same robustness argument as the slope itself.
            $scale = self::mad($residuals);
            if ($scale < 1e-12) {
                break;
            }

            $sw = 0.0;
            $swx = 0.0;
            $swy = 0.0;
            $swxx = 0.0;
            $swxy = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $standardised = $residuals[$i] / $scale;
                $w = abs($standardised) <= $this->delta ? 1.0 : $this->delta / abs($standardised);
                $sw += $w;
                $swx += $w * $x[$i];
                $swy += $w * $y[$i];
                $swxx += $w * $x[$i] * $x[$i];
                $swxy += $w * $x[$i] * $y[$i];
            }

            $denominator = $sw * $swxx - $swx * $swx;
            if (abs($denominator) < 1e-15) {
                break;
            }
            $newSlope = ($sw * $swxy - $swx * $swy) / $denominator;
            $newIntercept = ($swy - $newSlope * $swx) / $sw;

            $moved = abs($newSlope - $slope) + abs($newIntercept - $intercept);
            $slope = $newSlope;
            $intercept = $newIntercept;
            if ($moved < $this->tolerance) {
                break;
            }
        }

        return ['slope' => $slope, 'intercept' => $intercept, 'n' => $n];
    }

    /**
     * @param list<float>  $x
     * @param list<?float> $y
     * @return array{slope:float,intercept:float,n:int}
     */
    public function fitSeries(array $x, array $y): array
    {
        $cleanX = [];
        $cleanY = [];
        foreach ($y as $i => $value) {
            if ($value !== null && is_finite($value) && isset($x[$i])) {
                $cleanX[] = (float) $x[$i];
                $cleanY[] = (float) $value;
            }
        }
        return $this->fit($cleanX, $cleanY);
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     * @return array{slope:float,intercept:float,n:int}
     */
    public static function ols(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 2) {
            throw new \InvalidArgumentException('need at least two points');
        }
        $sx = array_sum($x);
        $sy = array_sum($y);
        $sxx = 0.0;
        $sxy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxx += $x[$i] * $x[$i];
            $sxy += $x[$i] * $y[$i];
        }
        $denominator = $n * $sxx - $sx * $sx;
        if (abs($denominator) < 1e-15) {
            return ['slope' => 0.0, 'intercept' => $sy / $n, 'n' => $n];
        }
        $slope = ($n * $sxy - $sx * $sy) / $denominator;
        return ['slope' => $slope, 'intercept' => ($sy - $slope * $sx) / $n, 'n' => $n];
    }

    /**
     * Days until the trend reaches the limit, or null when it never will.
     *
     * Null rather than a negative or infinite number: an ETA is going onto a work order,
     * and a nonsense date there is worse than no date.
     */
    public static function daysToLimit(float $slopePerDay, float $current, float $limit): ?float
    {
        $gap = $limit - $current;
        if (abs($slopePerDay) < 1e-12) {
            return null;
        }
        // Already past it, or heading the other way.
        if ($gap === 0.0 || ($gap > 0) !== ($slopePerDay > 0)) {
            return null;
        }
        $days = $gap / $slopePerDay;
        return $days > 0 ? $days : null;
    }

    /**
     * Fits a channel's recent history and projects it to an operating limit.
     *
     * @param list<array{ts:string,value:?float}> $series
     * @return array{slope_per_day:?float,current:?float,limit:float,days_to_limit:?float,eta:?string,n_points:int}
     */
    public static function project(array $series, float $limit, ?self $model = null): array
    {
        $model ??= new self();

        $x = [];
        $y = [];
        $base = null;
        $lastTs = null;
        foreach ($series as $row) {
            if ($row['value'] === null || !is_finite($row['value'])) {
                continue;
            }
            $ts = (new DateTimeImmutable($row['ts']))->getTimestamp();
            $base ??= $ts;
            $x[] = ($ts - $base) / 86400.0;
            $y[] = (float) $row['value'];
            $lastTs = $row['ts'];
        }

        if (count($x) < 2) {
            return [
                'slope_per_day' => null, 'current' => $y[0] ?? null, 'limit' => $limit,
                'days_to_limit' => null, 'eta' => null, 'n_points' => count($x),
            ];
        }

        $fit = $model->fit($x, $y);
        $current = $y[count($y) - 1];
        $days = self::daysToLimit($fit['slope'], $current, $limit);

        $eta = null;
        if ($days !== null && $lastTs !== null) {
            $eta = (new DateTimeImmutable($lastTs))
                ->modify('+' . (int) round($days * 86400) . ' seconds')
                ->format('Y-m-d H:i:s');
        }

        return [
            'slope_per_day' => $fit['slope'],
            'current'       => $current,
            'limit'         => $limit,
            'days_to_limit' => $days,
            'eta'           => $eta,
            'n_points'      => $fit['n'],
        ];
    }

    /** @param list<float> $values */
    private static function mad(array $values): float
    {
        $absolute = array_map(static fn(float $v) => abs($v), $values);
        sort($absolute);
        $n = count($absolute);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $absolute[$mid] : ($absolute[$mid - 1] + $absolute[$mid]) / 2.0;
        // 1.4826 makes MAD a consistent estimator of sigma under normality.
        return $median * 1.4826;
    }
}
