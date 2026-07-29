<?php
declare(strict_types=1);

namespace Tbw\EarlyWarning;

/**
 * Two-sided CUSUM on standardised forecast residuals.
 *
 * Catches the unexpected. Raw residual thresholds false-alarm constantly; CUSUM
 * accumulates small persistent deviations and ignores isolated spikes. One odd reading
 * is a glitch, twenty small consecutive ones are a fault.
 *
 * Two corrections carried over from the notebook, each of which alone produced a ~50%
 * alarm rate:
 *
 *   Centring. CUSUM assumes a zero-mean in-control process, so any constant forecast
 *   bias makes the statistic ramp without limit. The median of z is subtracted per
 *   episode — median, not mean, so a genuine excursion inside the episode does not move
 *   the reference. Detecting constant bias belongs to the SPC charts, not here.
 *
 *   Per-episode reset. Each forecast run is an independent 24 h episode; accumulating
 *   one statistic across concatenated unrelated episodes is meaningless.
 *
 * Fixed, these took the alarm rate from 53% to 4.34%.
 */
final class Cusum
{
    public function __construct(
        private float $k = 0.5,
        private float $h = 8.0,
        private bool $centre = true,
    ) {
    }

    /**
     * One episode.
     *
     * @param list<?float> $z standardised residuals
     * @return array{sp:list<?float>,sm:list<?float>,alarm:list<bool>,offset:float}
     */
    public function run(array $z): array
    {
        $offset = $this->centre ? self::median($z) : 0.0;

        $sp = [];
        $sm = [];
        $alarm = [];
        $upper = 0.0;
        $lower = 0.0;

        foreach ($z as $value) {
            if ($value === null || !is_finite($value)) {
                // A gap in the actuals is not evidence of good behaviour, so the
                // statistic holds rather than decaying towards zero.
                $sp[] = null;
                $sm[] = null;
                $alarm[] = false;
                continue;
            }
            $centred = $value - $offset;
            $upper = max(0.0, $upper + $centred - $this->k);
            $lower = max(0.0, $lower - $centred - $this->k);
            $sp[] = $upper;
            $sm[] = $lower;
            $alarm[] = $upper > $this->h || $lower > $this->h;
        }

        return ['sp' => $sp, 'sm' => $sm, 'alarm' => $alarm, 'offset' => $offset];
    }

    /**
     * Runs each episode from a clean state.
     *
     * @param list<list<?float>> $episodes
     * @return list<array{sp:list<?float>,sm:list<?float>,alarm:list<bool>,offset:float}>
     */
    public function runEpisodes(array $episodes): array
    {
        return array_map(fn(array $episode) => $this->run($episode), $episodes);
    }

    /**
     * Fraction of samples flagged. The operational budget is 2% — above that the control
     * room mutes the system within a week regardless of statistical soundness.
     *
     * @param list<array{alarm:list<bool>}> $results
     */
    public static function alarmRate(array $results): float
    {
        $flagged = 0;
        $total = 0;
        foreach ($results as $result) {
            foreach ($result['alarm'] as $a) {
                $total++;
                if ($a) {
                    $flagged++;
                }
            }
        }
        return $total === 0 ? 0.0 : $flagged / $total;
    }

    public function threshold(): float
    {
        return $this->h;
    }

    /** @param list<?float> $values */
    private static function median(array $values): float
    {
        $known = [];
        foreach ($values as $v) {
            if ($v !== null && is_finite($v)) {
                $known[] = (float) $v;
            }
        }
        if ($known === []) {
            return 0.0;
        }
        sort($known);
        $n = count($known);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $known[$mid] : ($known[$mid - 1] + $known[$mid]) / 2.0;
    }
}
