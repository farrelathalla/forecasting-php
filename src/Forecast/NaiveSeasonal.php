<?php
declare(strict_types=1);

namespace Tbw\Forecast;

use Tbw\Domain;

/**
 * Seasonal naive: the forecast for step h is the observation one season earlier.
 *
 * Kept for two reasons. It is the fallback when the Chronos-2 sidecar is unreachable,
 * and it is the permanent benchmark floor — a model that cannot beat this is copying,
 * not forecasting. It scored MASE 0.6808 in the notebook, in both sessions, which is
 * also what made the two leaderboards mergeable.
 *
 * Intervals come from the empirical spread of seasonal differences in the context, so
 * they widen when the process is genuinely less predictable rather than being assumed.
 */
final class NaiveSeasonal
{
    /**
     * @param list<?float>  $context
     * @param list<float>   $quantileLevels
     * @return array{median:list<float>,quantiles:array<string,list<float>>}
     */
    public function predict(
        array $context,
        int $horizon = Domain::HORIZON,
        int $season = Domain::SEASONALITY,
        array $quantileLevels = Domain::QUANTILES,
    ): array {
        if ($context === []) {
            throw new \InvalidArgumentException('naive seasonal needs a non-empty context');
        }
        $known = array_values(array_filter($context, static fn(?float $v) => $v !== null && is_finite($v)));
        if ($known === []) {
            throw new \InvalidArgumentException('naive seasonal needs at least one known value');
        }

        $n = count($context);
        $lastKnown = $known[count($known) - 1];

        $point = [];
        for ($h = 0; $h < $horizon; $h++) {
            $point[] = $n >= $season
                ? $this->lookBack($context, $n - $season + ($h % $season), $lastKnown)
                : $lastKnown;
        }

        // Residual spread of the seasonal difference, which is exactly the error this
        // predictor makes on its own history.
        $residuals = [];
        for ($i = $season; $i < $n; $i++) {
            $a = $context[$i] ?? null;
            $b = $context[$i - $season] ?? null;
            if ($a !== null && $b !== null && is_finite($a) && is_finite($b)) {
                $residuals[] = $a - $b;
            }
        }
        sort($residuals);

        $quantiles = [];
        foreach ($quantileLevels as $level) {
            $offset = $residuals === [] ? 0.0 : self::quantileOf($residuals, $level);
            $column = [];
            foreach ($point as $h => $value) {
                // Error grows with horizon; sqrt is the random-walk rate and a defensible
                // default when nothing better has been measured.
                $growth = sqrt(1.0 + $h / max(1, $season));
                $column[] = $value + $offset * $growth;
            }
            $quantiles[(string) $level] = $column;
        }

        $medianKey = (string) 0.5;
        return [
            'median'    => $quantiles[$medianKey] ?? $point,
            'quantiles' => $quantiles,
        ];
    }

    /** Walks back a whole season at a time until a known value turns up. */
    private function lookBack(array $context, int $index, float $fallback): float
    {
        $v = $context[$index] ?? null;
        if ($v !== null && is_finite($v)) {
            return (float) $v;
        }
        for ($j = $index - 1; $j >= 0; $j--) {
            $candidate = $context[$j] ?? null;
            if ($candidate !== null && is_finite($candidate)) {
                return (float) $candidate;
            }
        }
        return $fallback;
    }

    /** @param list<float> $sorted */
    public static function quantileOf(array $sorted, float $level): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $position = $level * ($n - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $sorted[$lower];
        }
        $weight = $position - $lower;
        return $sorted[$lower] * (1 - $weight) + $sorted[$upper] * $weight;
    }
}
