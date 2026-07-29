<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Config;
use Tbw\Domain;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testAutoloaderResolvesNamespacedClasses(): void
    {
        $this->assertTrue(class_exists(Config::class));
        $this->assertTrue(class_exists(Domain::class));
    }

    public function testReadsNestedKeysWithDotNotation(): void
    {
        $c = Config::fromArray(['db' => ['host' => '127.0.0.1', 'port' => 3306]]);
        $this->assertSame('127.0.0.1', $c->get('db.host'));
        $this->assertSame(3306, $c->get('db.port'));
    }

    public function testReturnsDefaultForMissingKey(): void
    {
        $c = Config::fromArray(['db' => ['host' => 'x']]);
        $this->assertSame('fallback', $c->get('db.missing', 'fallback'));
        $this->assertNull($c->get('nothing.here'));
    }

    public function testEnvOverridesFileValues(): void
    {
        $c = Config::fromArray(
            ['db' => ['host' => 'file-host'], 'api' => ['token' => 'file-token']],
            ['TBW_DB_HOST' => 'env-host']
        );
        $this->assertSame('env-host', $c->get('db.host'), 'env must win over the config file');
        $this->assertSame('file-token', $c->get('api.token'), 'unset env must not clobber file value');
    }

    public function testEnvCastsNumericAndBooleanStrings(): void
    {
        $c = Config::fromArray(
            ['db' => ['port' => 3306], 'forecast' => ['enabled' => false]],
            ['TBW_DB_PORT' => '3307', 'TBW_FORECAST_ENABLED' => 'true']
        );
        $this->assertSame(3307, $c->get('db.port'));
        $this->assertSame(true, $c->get('forecast.enabled'));
    }

    public function testShippedConfigFileLoadsAndHasRequiredKeys(): void
    {
        $c = Config::load();
        foreach (['db.host', 'db.name', 'db.user', 'api.url', 'api.token', 'forecast.service_url'] as $key) {
            $this->assertNotNull($c->get($key), "missing config key {$key}");
        }
        $this->assertStringContains('apps.daesang.net', (string) $c->get('api.url'));
    }

    public function testDomainConstantsMatchNotebookInferenceState(): void
    {
        // The notebook's frozen contract. If these drift, forecasts are no longer
        // comparable with the benchmark that chose Chronos-2 in the first place.
        $path = __DIR__ . '/../../../output/deploy_bundle/inference_state.json';
        if (!is_file($path)) {
            skip('inference_state.json not present (repo checked out standalone)');
        }
        $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($state['horizon'], Domain::HORIZON);
        $this->assertSame($state['context'], Domain::CONTEXT);
        $this->assertSame($state['seasonality'], Domain::SEASONALITY);
        $this->assertSame($state['model_freq'], Domain::MODEL_FREQ_MIN . 'min');
        $this->assertEquals($state['quantiles'], Domain::QUANTILES);
        $this->assertEquals($state['targets'], Domain::TARGETS);
    }

    public function testDomainDropsMotorRpmAndInletPressFromTargets(): void
    {
        // F8: MOTOR_RPM has CV ~= 0, including it inflates any benchmark.
        // F4: INLET_PRESS is a sawtooth counter, never forecast as a level.
        foreach (Domain::TARGETS as $t) {
            $this->assertFalse(str_contains($t, 'MOTOR_RPM'), "MOTOR_RPM must not be a target: {$t}");
            $this->assertFalse(str_contains($t, 'INLET_PRESS'), "INLET_PRESS must not be a target: {$t}");
            $this->assertFalse(str_contains($t, 'MOTOR_CURRENT'), "MOTOR_CURRENT is derived, not forecast: {$t}");
            $this->assertFalse(str_contains($t, 'TBW2'), "TBW2 is retired (F1): {$t}");
        }
        $this->assertCount(9, Domain::TARGETS);
        $this->assertContains('HEADER_PRESSURE', Domain::TARGETS);
    }

    public function testSentinelAndHoldLimitCarryForwardFromFindings(): void
    {
        $this->assertSame(10000.0, Domain::SENTINEL_HI, 'F5: 16-bit underflow filter');
        $this->assertSame(60, Domain::HOLD_LIMIT_MIN, 'F6: bounded LOCF hold');
    }
}
