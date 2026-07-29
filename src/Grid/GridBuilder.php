<?php
declare(strict_types=1);

namespace Tbw\Grid;

use DateTimeImmutable;
use Tbw\Db;
use Tbw\Domain;
use Tbw\Physics\PhysicsCalculator;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;

/**
 * raw readings -> 1-min LOCF grid -> 15-min mean -> 9 targets + physics channels.
 *
 * The two-stage shape is not incidental. F6 says a missing row means "unchanged", so the
 * first stage must be LOCF with a bounded hold. Only once the 1-min grid is gap-filled
 * does taking a mean become legitimate, which is exactly what the notebook does
 * (G.resample('1min').last().ffill(limit=60) then M = G.resample('15min').mean()).
 */
final class GridBuilder
{
    public function __construct(
        private ReadingRepository $readings,
        private GridRepository $grid,
        private LocfResampler $resampler = new LocfResampler(),
        private TargetBuilder $targets = new TargetBuilder(),
        private PhysicsCalculator $physics = new PhysicsCalculator(),
    ) {
    }

    public static function make(Db $db): self
    {
        return new self(new ReadingRepository($db), new GridRepository($db));
    }

    /**
     * @return array{grid:int,targets:int,physics:int,steps:int}
     */
    public function rebuild(string $from, string $to, string $source = 'live'): array
    {
        $from = self::floorTo($from, Domain::MODEL_FREQ_MIN);
        // $to is deliberately NOT floored: the readings that belong to the bucket in
        // progress arrive after its label, so truncating here would build the newest
        // bucket out of an empty minute range and emit nulls for live data.
        if ($from > $to) {
            return ['grid' => 0, 'targets' => 0, 'physics' => 0, 'steps' => 0];
        }

        // Reach back one hold window so a bucket at the very start of the range still
        // knows the reading it should be carrying forward.
        $lookback = (new DateTimeImmutable($from))
            ->modify('-' . Domain::HOLD_LIMIT_MIN . ' minutes')
            ->format('Y-m-d H:i:s');

        $raw = $this->readings->allInRange($lookback, $to);

        $byTag = [];
        foreach ($raw as $row) {
            $key = $row['signal_name'] . '|' . $row['asset'];
            $byTag[$key][] = ['ts' => (string) $row['observed_at'], 'value' => (float) $row['value']];
        }

        $gridRows = [];
        $series = [];
        $timestamps = null;

        foreach (Domain::SIGNALS as $signal) {
            foreach (array_merge(Domain::ACTIVE_ASSETS, Domain::RETIRED_ASSETS) as $asset) {
                $key = $signal . '|' . $asset;
                $points = $byTag[$key] ?? [];
                // A retired asset with no data at all is skipped rather than written as
                // a wall of nulls -- TBW2 is a stopped machine, not a broken sensor (F1).
                if ($points === [] && in_array($asset, Domain::RETIRED_ASSETS, true)) {
                    continue;
                }

                $minute = $this->resampler->resample($points, $lookback, $to);
                $fifteen = LocfResampler::downsample($minute, Domain::MODEL_FREQ_MIN);

                $column = [];
                foreach ($fifteen as $row) {
                    if ($row['ts'] < $from) {
                        continue;
                    }
                    $column[$row['ts']] = $row['value'];
                    $gridRows[] = [
                        'asset'   => $asset,
                        'signal'  => $signal,
                        'ts'      => $row['ts'],
                        'value'   => $row['value'],
                        'is_held' => $row['is_held'],
                    ];
                }
                $series[$key] = $column;
                $timestamps ??= array_keys($column);
            }
        }

        if ($timestamps === null || $timestamps === []) {
            return ['grid' => 0, 'targets' => 0, 'physics' => 0, 'steps' => 0];
        }

        $built = $this->targets->build($series, $timestamps);

        // Physics needs the collapsed header and the raw current, so it reads from the
        // targets and the grid together.
        $physicsInput = $series;
        foreach ($built['targets'] as $name => $column) {
            $physicsInput[$name] = $column;
        }
        $channels = $this->physics->compute($physicsInput, $built['running'], $timestamps);

        foreach ($built['aux'] as $name => $column) {
            $channels[$name] = $column;
        }
        foreach ($built['running'] as $asset => $flags) {
            $channels['is_running|' . $asset] = array_map(
                static fn(bool $f) => $f ? 1.0 : 0.0,
                $flags
            );
        }
        $channels['n_running'] = array_map(static fn(int $n) => (float) $n, $built['n_running']);

        return [
            'grid'    => $this->grid->upsertGrid($gridRows, $source),
            'targets' => $this->grid->upsertTargets($built['targets'], $source),
            'physics' => $this->grid->upsertPhysics($channels),
            'steps'   => count($timestamps),
        ];
    }

    public static function floorTo(string $ts, int $freqMin): string
    {
        $seconds = (new DateTimeImmutable($ts))->getTimestamp();
        $step = $freqMin * 60;
        return date('Y-m-d H:i:s', intdiv($seconds, $step) * $step);
    }
}
