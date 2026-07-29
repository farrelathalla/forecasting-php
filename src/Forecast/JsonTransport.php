<?php
declare(strict_types=1);

namespace Tbw\Forecast;

use Tbw\Ingest\HttpResponse;

interface JsonTransport
{
    public function postJson(string $url, array $payload, int $timeoutSec = 120): HttpResponse;

    public function getJson(string $url, int $timeoutSec = 10): HttpResponse;
}
