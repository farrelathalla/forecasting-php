<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tbw\Domain;
use Tbw\Ingest\SensorApiClient;
use Tests\TestCase;

/**
 * Hits the real endpoint. Skipped unless: php tests/run.php --integration
 *
 * Kept because the contract that matters most is the live one — a silent change to the
 * tag shape or to updated_at would break ingest in a way no fake transport can catch.
 */
final class LiveApiTest extends TestCase
{
    public function setUp(): void
    {
        if (getenv('TBW_INTEGRATION') !== '1') {
            \skip('use --integration to hit the live API');
        }
    }

    public function testLiveEndpointStillMatchesTheExpectedShape(): void
    {
        $result = SensorApiClient::fromConfig()->fetchLatest();

        $this->assertGreaterThan(0, $result->count());

        $assets = [];
        $signals = [];
        foreach ($result->readings as $r) {
            $assets[$r->asset] = true;
            $signals[$r->signal] = true;
            $this->assertTrue(is_finite($r->value), "non-finite value on {$r->tag()}");
        }

        // F1: two duty pumps. TBW2 stopped permanently on 2026-05-14.
        foreach (Domain::ACTIVE_ASSETS as $asset) {
            $this->assertTrue(isset($assets[$asset]), "active asset {$asset} missing from live API");
        }
        foreach (Domain::SIGNALS as $signal) {
            $this->assertTrue(isset($signals[$signal]), "signal {$signal} missing from live API");
        }
    }

    public function testLiveValuesSitInThePlausibleRangesFromTheExtract(): void
    {
        $expected = [
            'FLOWRATE'        => [10.0, 60.0],
            'INLET_TEMP'      => [15.0, 60.0],
            'MOTOR_CURRENT'   => [50.0, 400.0],
            'MOTOR_RPM'       => [0.0, 100.0],
            'OUTLET_PRESSURE' => [1.0, 12.0],
            'OUTLET_TEMP'     => [15.0, 70.0],
            'POWER'           => [50.0, 400.0],
        ];

        foreach (SensorApiClient::fromConfig()->fetchLatest()->readings as $r) {
            if (!isset($expected[$r->signal])) {
                continue;
            }
            [$lo, $hi] = $expected[$r->signal];
            $this->assertTrue(
                $r->value >= $lo && $r->value <= $hi,
                sprintf('%s = %.3f is outside the expected %.1f..%.1f', $r->tag(), $r->value, $lo, $hi)
            );
        }
    }
}
