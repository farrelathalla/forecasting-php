<?php
declare(strict_types=1);

namespace Tbw\Forecast;

use Tbw\Ingest\CurlTransport;
use Tbw\Ingest\HttpResponse;

/** The sidecar lives on localhost, so TLS verification never applies. */
final class CurlJsonTransport implements JsonTransport
{
    private CurlTransport $curl;

    public function __construct()
    {
        $this->curl = new CurlTransport(false);
    }

    public function postJson(string $url, array $payload, int $timeoutSec = 120): HttpResponse
    {
        return $this->curl->postJson($url, $payload, $timeoutSec);
    }

    public function getJson(string $url, int $timeoutSec = 10): HttpResponse
    {
        return $this->curl->get($url, ['Accept' => 'application/json'], $timeoutSec);
    }
}
