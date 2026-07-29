<?php
declare(strict_types=1);

namespace Tbw\Forecast;

/**
 * @phpstan-type Series array{median:list<float>,quantiles:array<string,list<float>>}
 */
final class ForecastResult
{
    /**
     * @param array<string,array{median:list<float>,quantiles:array<string,list<float>>}> $forecasts
     * @param list<string> $skipped targets refused for lack of history, with the reason logged
     */
    public function __construct(
        public readonly string $model,
        public readonly array $forecasts,
        public readonly int $elapsedMs = 0,
        public readonly bool $degraded = false,
        public readonly string $note = '',
        public readonly array $skipped = [],
    ) {
    }
}
