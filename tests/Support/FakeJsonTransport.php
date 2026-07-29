<?php
declare(strict_types=1);

namespace Tests\Support;

use Tbw\Ingest\HttpResponse;
use Tbw\Forecast\JsonTransport;

final class FakeJsonTransport implements JsonTransport
{
    /** @var list<array{url:string,payload:array}> */
    public array $calls = [];

    /** @param list<HttpResponse|\Throwable> $queue */
    public function __construct(private array $queue, private bool $repeatLast = true)
    {
    }

    public static function json(array $payload, int $status = 200): self
    {
        return new self([new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    public static function raw(string $body, int $status = 200): self
    {
        return new self([new HttpResponse($status, $body)]);
    }

    public static function throwing(\Throwable $e): self
    {
        return new self([$e]);
    }

    public function postJson(string $url, array $payload, int $timeoutSec = 120): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];
        return $this->next();
    }

    public function getJson(string $url, int $timeoutSec = 10): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'payload' => []];
        return $this->next();
    }

    private function next(): HttpResponse
    {
        $next = count($this->queue) === 1 && $this->repeatLast ? $this->queue[0] : array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('FakeJsonTransport: no scripted response left');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
