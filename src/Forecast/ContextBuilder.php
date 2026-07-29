<?php
declare(strict_types=1);

namespace Tbw\Forecast;

use DateTimeImmutable;
use Tbw\Domain;
use Tbw\Repository\GridRepository;

/**
 * Assembles the model context: the last CONTEXT steps of each target, dense on the
 * 15-minute grid, ending strictly before the origin.
 *
 * Two properties matter and both were bugs in the notebook first:
 *
 *   Strictly before. FEAT.loc[:cutoff_ts] is inclusive in pandas, which leaked the first
 *   test point into the context and made predict_df reject the future frame.
 *
 *   Dense. Chronos-2 assumes a regular grid. Handing it a compacted series -- rows only
 *   where data exists -- shifts every lag and destroys the daily seasonality that F9
 *   showed is the strongest structure in INLET_TEMP.
 */
final class ContextBuilder
{
    public function __construct(private GridRepository $grid)
    {
    }

    /**
     * @param list<string> $targets
     * @return array<string,list<?float>> target => context values, oldest first
     */
    public function build(array $targets, string $originTs, int $steps = Domain::CONTEXT): array
    {
        $origin = new DateTimeImmutable($originTs);
        $from = $origin->modify('-' . ($steps * Domain::MODEL_FREQ_MIN) . ' minutes');

        $timeline = [];
        for ($i = 0; $i < $steps; $i++) {
            $timeline[] = $from->modify('+' . ($i * Domain::MODEL_FREQ_MIN) . ' minutes')->format('Y-m-d H:i:s');
        }
        $lastTs = $timeline[$steps - 1];

        $out = [];
        foreach ($targets as $target) {
            $rows = $this->grid->targetSeries($target, $timeline[0], $lastTs);
            $byTs = [];
            foreach ($rows as $row) {
                $byTs[$row['ts']] = $row['value'];
            }
            $column = [];
            foreach ($timeline as $ts) {
                $v = $byTs[$ts] ?? null;
                $column[] = ($v !== null && is_finite($v)) ? (float) $v : null;
            }
            $out[$target] = $column;
        }
        return $out;
    }

    /**
     * Fraction of the context that carries real observations, averaged over targets.
     * Surfaced on the dashboard so a forecast made on a thin history is never read as
     * if it were made on a full one.
     *
     * @param array<string,list<?float>> $contexts
     */
    public static function coverage(array $contexts): float
    {
        $known = 0;
        $total = 0;
        foreach ($contexts as $column) {
            foreach ($column as $v) {
                $total++;
                if ($v !== null) {
                    $known++;
                }
            }
        }
        return $total === 0 ? 0.0 : $known / $total;
    }

    /** The origin is the next grid step after the newest complete observation. */
    public static function nextOrigin(?string $lastTargetTs = null): string
    {
        $base = $lastTargetTs === null ? new DateTimeImmutable() : new DateTimeImmutable($lastTargetTs);
        $step = Domain::MODEL_FREQ_MIN * 60;
        $floored = intdiv($base->getTimestamp(), $step) * $step;
        return date('Y-m-d H:i:s', $floored + $step);
    }
}
