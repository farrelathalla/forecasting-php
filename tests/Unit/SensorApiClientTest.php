<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Ingest\ApiException;
use Tbw\Ingest\SensorApiClient;
use Tests\Support\FakeTransport;
use Tests\Support\Payloads;
use Tests\TestCase;

final class SensorApiClientTest extends TestCase
{
    private function client(FakeTransport $transport): SensorApiClient
    {
        return new SensorApiClient($transport, 'https://example.test/latest.php', 'test-token');
    }

    public function testParsesLiveShapeIntoSixteenReadings(): void
    {
        $client = $this->client(FakeTransport::json(Payloads::live()));
        $result = $client->fetchLatest();

        $this->assertCount(16, $result->readings);
        $first = $result->readings[0];
        $this->assertSame('TBW1', $first->asset);
        $this->assertSame('FLOWRATE', $first->signal);
        $this->assertFloatEquals(33.4, $first->value, 1e-9);
        $this->assertSame('2026-07-29 13:29:10', $first->observedAt->format('Y-m-d H:i:s'));
    }

    public function testSendsBearerToken(): void
    {
        $transport = FakeTransport::json(Payloads::live());
        $this->client($transport)->fetchLatest();
        $headers = $transport->calls[0]['headers'];
        $this->assertSame('Bearer test-token', $headers['Authorization'] ?? null);
    }

    public function testSplitsTagIntoAssetAndSignal(): void
    {
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['AIR_INSTRUMENT/TBW3/OUTLET_TEMP', '44.7', '2026-07-29 13:29:10'],
        ])));
        $r = $client->fetchLatest()->readings[0];
        $this->assertSame('TBW3', $r->asset);
        $this->assertSame('OUTLET_TEMP', $r->signal);
    }

    public function testDropsSentinelReadingsFromRegisterUnderflow(): void
    {
        // F5: 47 readings sit within a few counts of 2^16 on INLET_PRESS. One of them
        // against a 90-265 range inflates variance by orders of magnitude and flattened
        // the ACF to 0.02 in an early profiling pass. Drop before anything else sees them.
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['AIR_INSTRUMENT/TBW1/INLET_PRESS', '65535', '2026-07-29 13:29:10'],
            ['AIR_INSTRUMENT/TBW1/INLET_PRESS', '65529', '2026-07-29 13:30:10'],
            ['AIR_INSTRUMENT/TBW1/INLET_PRESS', '65527', '2026-07-29 13:31:10'],
            ['AIR_INSTRUMENT/TBW1/INLET_PRESS', '201',   '2026-07-29 13:32:10'],
        ])));
        $result = $client->fetchLatest();

        $this->assertCount(1, $result->readings);
        $this->assertFloatEquals(201.0, $result->readings[0]->value);
        $this->assertSame(3, $result->sentinelsDropped);
    }

    public function testRejectsNonNumericValueInsteadOfCoercingToZero(): void
    {
        // A silent (float)'FAULT' === 0.0 would look like a stopped pump.
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['AIR_INSTRUMENT/TBW1/POWER', 'FAULT', '2026-07-29 13:29:10'],
            ['AIR_INSTRUMENT/TBW1/FLOWRATE', '', '2026-07-29 13:29:10'],
            ['AIR_INSTRUMENT/TBW1/INLET_TEMP', '38.6', '2026-07-29 13:29:10'],
        ])));
        $result = $client->fetchLatest();

        $this->assertCount(1, $result->readings);
        $this->assertSame('INLET_TEMP', $result->readings[0]->signal);
        $this->assertSame(2, $result->invalidDropped);
    }

    public function testThrowsWhenApiReportsFailure(): void
    {
        $transport = FakeTransport::json(['success' => false, 'message' => 'token expired', 'data' => []]);
        $e = $this->assertThrows(ApiException::class, fn() => $this->client($transport)->fetchLatest());
        $this->assertStringContains('token expired', $e->getMessage());
    }

    public function testThrowsOnHttpErrorStatusWithStatusInMessage(): void
    {
        $transport = FakeTransport::raw('Unauthorized', 401);
        $e = $this->assertThrows(ApiException::class, fn() => $this->client($transport)->fetchLatest());
        $this->assertStringContains('401', $e->getMessage());
    }

    public function testThrowsOnMalformedJson(): void
    {
        $transport = FakeTransport::raw('<html>gateway timeout</html>', 200);
        $this->assertThrows(ApiException::class, fn() => $this->client($transport)->fetchLatest());
    }

    public function testThrowsOnTransportFailure(): void
    {
        $transport = FakeTransport::throwing(new \RuntimeException('connection refused'));
        $e = $this->assertThrows(ApiException::class, fn() => $this->client($transport)->fetchLatest());
        $this->assertStringContains('connection refused', $e->getMessage());
    }

    public function testAcceptsRetiredAssetWithoutCrashing(): void
    {
        // F1 says TBW2 stopped permanently and the live API confirms it is absent.
        // If it ever comes back the poller must store it, not fall over.
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['AIR_INSTRUMENT/TBW2/POWER', '180.1', '2026-07-29 13:29:10'],
        ])));
        $r = $client->fetchLatest()->readings[0];
        $this->assertSame('TBW2', $r->asset);
    }

    public function testIgnoresUnparseableTagShape(): void
    {
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['GARBAGE', '1.0', '2026-07-29 13:29:10'],
            ['AIR_INSTRUMENT/TBW1/POWER', '183.5', '2026-07-29 13:29:10'],
        ])));
        $result = $client->fetchLatest();
        $this->assertCount(1, $result->readings);
        $this->assertSame(1, $result->invalidDropped);
    }

    public function testRejectsUnparseableTimestamp(): void
    {
        $client = $this->client(FakeTransport::json(Payloads::custom([
            ['AIR_INSTRUMENT/TBW1/POWER', '183.5', 'not-a-date'],
        ])));
        $result = $client->fetchLatest();
        $this->assertCount(0, $result->readings);
        $this->assertSame(1, $result->invalidDropped);
    }
}
