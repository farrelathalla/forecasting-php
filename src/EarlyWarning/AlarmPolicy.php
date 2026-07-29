<?php
declare(strict_types=1);

namespace Tbw\EarlyWarning;

use Tbw\Config;

/**
 * Tiered classification: OK / WARN / ALARM.
 *
 * Tiering matters as much as detection. Equal severity for a sensor glitch and an
 * imminent trip trains operators to ignore both.
 *
 * Three behaviours that are easy to get wrong and are each pinned by a test:
 *
 *   min_consecutive is enforced. In the notebook the field existed but classify() never
 *   read it, so the persistence requirement silently did nothing (roadmap E5).
 *
 *   Hysteresis on the way down. Tiers rise on the first qualifying sample and fall only
 *   once the magnitude drops a margin below the threshold, so an alarm sitting near the
 *   boundary does not flicker and get dismissed as noise.
 *
 *   A missing sample never clears an alarm. Losing the data feed is not recovery.
 */
final class AlarmPolicy
{
    private const SEVERITY = ['OK' => 0, 'WARN' => 1, 'ALARM' => 2];

    public function __construct(
        private float $warnSigma = 3.0,
        private float $alarmSigma = 4.0,
        private int $minConsecutive = 4,
        private float $clearMarginSigma = 0.5,
    ) {
    }

    public static function fromConfig(?Config $config = null): self
    {
        $config ??= Config::load();
        return new self(
            $config->float('alarm.warn_sigma', 3.0),
            $config->float('alarm.alarm_sigma', 4.0),
            $config->int('alarm.min_consecutive', 4),
            $config->float('alarm.clear_margin_sigma', 0.5),
        );
    }

    public function classify(AlarmState $state, ?float $magnitude): AlarmState
    {
        if ($magnitude === null || !is_finite($magnitude)) {
            // Not evidence of recovery. Hold the tier and the counter.
            return $state->with(changed: false);
        }

        $size = abs($magnitude);
        $candidate = $this->tierFor($size);

        // A sample that qualifies for anything above OK extends the run; anything else
        // breaks it.
        $consecutive = self::SEVERITY[$candidate] > 0 ? $state->consecutive + 1 : 0;

        $tier = $state->tier;
        if (self::SEVERITY[$candidate] > self::SEVERITY[$state->tier]) {
            // Rising requires persistence.
            if ($consecutive >= $this->minConsecutive) {
                $tier = $candidate;
            }
        } elseif (self::SEVERITY[$candidate] < self::SEVERITY[$state->tier]) {
            // Falling requires clearing the threshold by the margin.
            if ($size < $this->thresholdFor($state->tier) - $this->clearMarginSigma) {
                $tier = $candidate;
            }
        }

        return $state->with(
            tier: $tier,
            consecutive: $consecutive,
            value: $magnitude,
            threshold: $this->thresholdFor($tier === 'OK' ? $candidate : $tier),
            changed: $tier !== $state->tier,
        );
    }

    private function tierFor(float $size): string
    {
        if ($size >= $this->alarmSigma) {
            return 'ALARM';
        }
        if ($size >= $this->warnSigma) {
            return 'WARN';
        }
        return 'OK';
    }

    private function thresholdFor(string $tier): float
    {
        return match ($tier) {
            'ALARM' => $this->alarmSigma,
            'WARN'  => $this->warnSigma,
            default => $this->warnSigma,
        };
    }

    public static function severity(string $tier): int
    {
        return self::SEVERITY[$tier] ?? 0;
    }

    /**
     * The CUSUM threshold depends on which model is being monitored.
     *
     * Section 15 measured this rather than assumed it: the same detector on the same
     * folds gave a 16.59% alarm rate under LGBM-Local (cov80 68.4%) and 7.78% under
     * Chronos-2 (cov80 84.2%). An under-covering forecaster inflates the standardised
     * residual, the CUSUM ramps, and the alarm never stops. So when the system falls
     * back to Naive-Seasonal the threshold moves with it instead of pretending the
     * calibration is unchanged.
     */
    public static function cusumThreshold(bool $degraded, float $healthy, float $degradedThreshold): float
    {
        return $degraded ? $degradedThreshold : $healthy;
    }
}
