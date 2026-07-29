<?php
declare(strict_types=1);

namespace Tbw\Ingest;

use DateTimeImmutable;

final class Reading
{
    public function __construct(
        public readonly string $asset,
        public readonly string $signal,
        public readonly DateTimeImmutable $observedAt,
        public readonly float $value,
    ) {
    }

    public static function of(string $asset, string $signal, string $observedAt, float $value): self
    {
        return new self($asset, $signal, new DateTimeImmutable($observedAt), $value);
    }

    public function tag(): string
    {
        return $this->signal . '|' . $this->asset;
    }
}
