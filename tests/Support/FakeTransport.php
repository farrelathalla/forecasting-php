<?php
declare(strict_types=1);

namespace Tests\Support;

use Tbw\Ingest\HttpResponse;
use Tbw\Ingest\HttpTransport;

/** Scripted transport so ingest tests never touch the production API. */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{url:string,headers:array<string,string>}> */
    public array $calls = [];

    /** @param list<HttpResponse|\Throwable> $queue */
    public function __construct(private array $queue)
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

    public function get(string $url, array $headers = [], int $timeoutSec = 20): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('FakeTransport: no scripted response left');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
