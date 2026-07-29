<?php
declare(strict_types=1);

namespace Tbw\Ingest;

/** Seam so tests can script HTTP without touching the production endpoint. */
interface HttpTransport
{
    /** @param array<string,string> $headers */
    public function get(string $url, array $headers = [], int $timeoutSec = 20): HttpResponse;
}
