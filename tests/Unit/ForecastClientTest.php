<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Forecast\ForecastClient;
use Tbw\Forecast\ForecastException;
use Tests\Support\FakeJsonTransport;
use Tests\TestCase;

final class ForecastClientTest extends TestCase
{
    /** @return array<string,list<?float>> two targets, full context */
    private function contexts(int $n = 1344): array
    {
        $make = static function (float $level) use ($n): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = $level + 2.0 * sin(2 * M_PI * $i / 96.0);
            }
            return $out;
        };
        return ['POWER|TBW1' => $make(186.0), 'INLET_TEMP|TBW1' => $make(35.0)];
    }

    /** @param list<string> $ids */
    private function response(array $ids, int $horizon = 96, string $model = 'amazon/chronos-2'): array
    {
        $forecasts = [];
        foreach ($ids as $id) {
            $quantiles = [];
            foreach (Domain::QUANTILES as $q) {
                $column = [];
                for ($h = 0; $h < $horizon; $h++) {
                    $column[] = 100.0 + ($q - 0.5) * 10.0 + $h * 0.01;
                }
                $quantiles[(string) $q] = $column;
            }
            $forecasts[] = ['id' => $id, 'median' => $quantiles['0.5'], 'quantiles' => $quantiles];
        }
        return [
            'model' => $model, 'device' => 'cpu', 'elapsed_ms' => 5500,
            'prediction_length' => $horizon, 'quantile_levels' => Domain::QUANTILES,
            'forecasts' => $forecasts,
        ];
    }

    private function client(FakeJsonTransport $transport, bool $fallback = true): ForecastClient
    {
        return new ForecastClient($transport, 'http://127.0.0.1:8008', 30, $fallback);
    }

    public function testSendsEveryTargetAsOneSeries(): void
    {
        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1', 'INLET_TEMP|TBW1']));
        $this->client($transport)->forecast($this->contexts(), 96);

        $sent = $transport->calls[0]['payload'];
        $this->assertCount(2, $sent['series']);
        $this->assertSame('POWER|TBW1', $sent['series'][0]['id']);
        $this->assertSame(96, $sent['prediction_length']);
        $this->assertCount(1344, $sent['series'][0]['values']);
    }

    public function testGapsAreSentAsNullNotZero(): void
    {
        // The seeded extract ends 2026-07-22 and live polling starts later, so the
        // context genuinely has a hole. Chronos-2 handles null natively; a zero would
        // read as a stopped pump and be the one number guaranteed to be wrong.
        $contexts = $this->contexts();
        $contexts['POWER|TBW1'][10] = null;
        $contexts['POWER|TBW1'][11] = null;

        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1', 'INLET_TEMP|TBW1']));
        $this->client($transport)->forecast($contexts, 96);

        $values = $transport->calls[0]['payload']['series'][0]['values'];
        $this->assertNull($values[10]);
        $this->assertNull($values[11]);
    }

    public function testParsesQuantilesPerTarget(): void
    {
        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1', 'INLET_TEMP|TBW1']));
        $result = $this->client($transport)->forecast($this->contexts(), 96);

        $this->assertSame('chronos-2', $result->model);
        $this->assertFalse($result->degraded);
        $this->assertCount(2, $result->forecasts);
        $this->assertCount(96, $result->forecasts['POWER|TBW1']['median']);
        $this->assertCount(9, $result->forecasts['POWER|TBW1']['quantiles']);
        $this->assertCount(96, $result->forecasts['POWER|TBW1']['quantiles']['0.9']);
        $this->assertSame(5500, $result->elapsedMs);
    }

    public function testFallsBackToNaiveWhenTheSidecarIsUnreachable(): void
    {
        // An outage of the Python service must degrade the system, not stop it. This is
        // the single most important behaviour for leaving the thing running unattended.
        $transport = FakeJsonTransport::throwing(new \RuntimeException('curl: connection refused'));
        $result = $this->client($transport)->forecast($this->contexts(), 96);

        $this->assertSame('naive-seasonal', $result->model);
        $this->assertTrue($result->degraded);
        $this->assertCount(2, $result->forecasts);
        $this->assertCount(96, $result->forecasts['POWER|TBW1']['median']);
        $this->assertStringContains('connection refused', $result->note);
    }

    public function testFallsBackOnServerError(): void
    {
        $transport = FakeJsonTransport::raw('{"detail":"model not loaded"}', 503);
        $result = $this->client($transport)->forecast($this->contexts(), 96);
        $this->assertSame('naive-seasonal', $result->model);
        $this->assertTrue($result->degraded);
    }

    public function testFallbackCanBeDisabledSoFailuresAreLoud(): void
    {
        $transport = FakeJsonTransport::throwing(new \RuntimeException('connection refused'));
        $this->assertThrows(
            ForecastException::class,
            fn() => $this->client($transport, false)->forecast($this->contexts(), 96)
        );
    }

    public function testRejectsAResponseWithTheWrongNumberOfSteps(): void
    {
        // Half a forecast stored as if it were whole would silently shorten every
        // horizon bucket in the scorecard. Refuse the run instead.
        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1', 'INLET_TEMP|TBW1'], 48));
        $result = $this->client($transport)->forecast($this->contexts(), 96);
        $this->assertTrue($result->degraded, 'a short forecast must be treated as a failure');
        $this->assertSame('naive-seasonal', $result->model);
    }

    public function testRejectsAResponseMissingATarget(): void
    {
        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1']));
        $result = $this->client($transport)->forecast($this->contexts(), 96);
        $this->assertTrue($result->degraded);
    }

    public function testSkipsTargetsWithTooLittleHistoryRatherThanGuessing(): void
    {
        $contexts = $this->contexts();
        $contexts['SHORT|TBW1'] = array_fill(0, 10, 1.0);

        $transport = FakeJsonTransport::json($this->response(['POWER|TBW1', 'INLET_TEMP|TBW1']));
        $result = $this->client($transport)->forecast($contexts, 96);

        $this->assertFalse(isset($result->forecasts['SHORT|TBW1']));
        $this->assertContains('SHORT|TBW1', $result->skipped);
        $this->assertFalse($result->degraded);
    }

    public function testHealthCheckReportsSidecarState(): void
    {
        $transport = FakeJsonTransport::json(['status' => 'ok', 'model' => 'amazon/chronos-2', 'device' => 'cpu']);
        $health = $this->client($transport)->health();
        $this->assertSame('ok', $health['status']);
    }

    public function testHealthCheckReportsUnavailableInsteadOfThrowing(): void
    {
        $transport = FakeJsonTransport::throwing(new \RuntimeException('connection refused'));
        $health = $this->client($transport)->health();
        $this->assertSame('unavailable', $health['status']);
    }
}
