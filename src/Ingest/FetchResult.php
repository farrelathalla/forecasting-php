<?php
declare(strict_types=1);

namespace Tbw\Ingest;

/**
 * Readings plus what was thrown away and why. The counts are not decoration: a sudden
 * rise in sentinelsDropped is the firmware defect from F5 getting worse, and that is
 * reportable to instrumentation.
 */
final class FetchResult
{
    /** @param list<Reading> $readings */
    public function __construct(
        public readonly array $readings,
        public readonly int $sentinelsDropped = 0,
        public readonly int $invalidDropped = 0,
    ) {
    }

    public function count(): int
    {
        return count($this->readings);
    }
}
