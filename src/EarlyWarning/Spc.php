<?php
declare(strict_types=1);

namespace Tbw\EarlyWarning;

use Tbw\Config;

/**
 * Statistical process control on the physics channels.
 *
 * Catches the fault that looks fine: a signal inside its normal range whose relationship
 * to the others has broken. Physics ratios have a CV of a few percent against ~3% for
 * raw POWER, so a fixed threshold gives very few false positives.
 *
 * Control limits are FROZEN, loaded from config/spc_limits.csv, fitted on the healthy
 * window 2026-05-20 -> 2026-06-05. They are never refitted on running data — that is the
 * classic SPC mistake, where slow degradation drags its own baseline along and the alarm
 * never fires. The immutability is enforced by a test, not by discipline.
 *
 * Drift is reported in sigma units only. Never as a percentage of the mean: dT's mean
 * crosses zero, so a percentage on that channel is meaningless.
 */
final class Spc
{
    /** @param array<string,array{mu:float,sigma:float,lcl:float,ucl:float}> $limits */
    public function __construct(
        private readonly array $limits,
        private float $warnSigma = 2.0,
        private float $alarmSigma = 3.0,
    ) {
    }

    public static function fromCsv(string $path, ?Config $config = null): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("SPC limits not found: {$path}");
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        $header = fgetcsv($handle);
        $index = array_flip(array_map('strval', (array) $header));

        $limits = [];
        while (($row = fgetcsv($handle)) !== false) {
            $channel = (string) ($row[0] ?? '');
            if ($channel === '') {
                continue;
            }
            $limits[$channel] = [
                'mu'    => (float) $row[$index['mu']],
                'sigma' => (float) $row[$index['sigma']],
                'lcl'   => (float) $row[$index['lcl']],
                'ucl'   => (float) $row[$index['ucl']],
            ];
        }
        fclose($handle);

        $config ??= Config::load();
        return new self(
            $limits,
            $config->float('alarm.warn_sigma', 3.0) - 1.0,
            $config->float('alarm.alarm_sigma', 4.0) - 1.0,
        );
    }

    public static function default(?Config $config = null): self
    {
        return self::fromCsv(dirname(__DIR__, 2) . '/config/spc_limits.csv', $config);
    }

    /** @return array<string,array{mu:float,sigma:float,lcl:float,ucl:float}> */
    public function limits(): array
    {
        return $this->limits;
    }

    /**
     * @return array{channel:string,value:?float,mu:float,sigma:float,lcl:float,ucl:float,
     *               drift_sigma:?float,tier:string,breached:bool}|null
     */
    public function evaluate(string $channel, ?float $value): ?array
    {
        $limit = $this->limits[$channel] ?? null;
        if ($limit === null) {
            return null;
        }

        $drift = null;
        if ($value !== null && is_finite($value) && abs($limit['sigma']) > 1e-12) {
            $drift = ($value - $limit['mu']) / $limit['sigma'];
        }

        $magnitude = $drift === null ? 0.0 : abs($drift);
        $tier = 'OK';
        if ($magnitude >= $this->alarmSigma) {
            $tier = 'ALARM';
        } elseif ($magnitude >= $this->warnSigma) {
            $tier = 'WARN';
        }

        return [
            'channel'     => $channel,
            'value'       => $value,
            'mu'          => $limit['mu'],
            'sigma'       => $limit['sigma'],
            'lcl'         => $limit['lcl'],
            'ucl'         => $limit['ucl'],
            'drift_sigma' => $drift,
            'tier'        => $tier,
            'breached'    => $value !== null && ($value < $limit['lcl'] || $value > $limit['ucl']),
        ];
    }

    /**
     * @param array<string,?float> $values channel => current value
     * @return array<string,array<string,mixed>>
     */
    public function evaluateAll(array $values): array
    {
        $out = [];
        foreach ($values as $channel => $value) {
            $state = $this->evaluate((string) $channel, $value);
            if ($state !== null) {
                $out[(string) $channel] = $state;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_keys($this->limits);
    }
}
