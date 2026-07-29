<?php
declare(strict_types=1);

namespace Tbw\EarlyWarning;

/**
 * Immutable tier state for one channel. Carries the consecutive-breach counter, which
 * has to survive across job runs for min_consecutive to mean anything.
 */
final class AlarmState
{
    public function __construct(
        public readonly string $channel,
        public readonly string $tier = 'OK',
        public readonly int $consecutive = 0,
        public readonly ?float $value = null,
        public readonly ?float $threshold = null,
        public readonly string $previousTier = 'OK',
        public readonly bool $changed = false,
        public readonly ?string $ts = null,
    ) {
    }

    public static function initial(string $channel): self
    {
        return new self($channel);
    }

    /** @param array<string,mixed> $row from alarm_state */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['channel'],
            (string) ($row['tier'] ?? 'OK'),
            (int) ($row['consecutive'] ?? 0),
            isset($row['last_value']) && $row['last_value'] !== null ? (float) $row['last_value'] : null,
            null,
            (string) ($row['tier'] ?? 'OK'),
            false,
            isset($row['last_ts']) ? (string) $row['last_ts'] : null,
        );
    }

    public function with(
        ?string $tier = null,
        ?int $consecutive = null,
        ?float $value = null,
        ?float $threshold = null,
        ?bool $changed = null,
        ?string $ts = null,
    ): self {
        $newTier = $tier ?? $this->tier;
        return new self(
            $this->channel,
            $newTier,
            $consecutive ?? $this->consecutive,
            $value ?? $this->value,
            $threshold ?? $this->threshold,
            $this->tier,
            $changed ?? ($newTier !== $this->tier),
            $ts ?? $this->ts,
        );
    }
}
