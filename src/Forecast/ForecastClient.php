<?php
declare(strict_types=1);

namespace Tbw\Forecast;

use Tbw\Config;
use Tbw\Domain;

/**
 * PHP side of the Chronos-2 sidecar contract.
 *
 * Degrades in tiers rather than failing, which is what makes the system safe to leave
 * running unattended:
 *   1. sidecar healthy          -> Chronos-2, model = 'chronos-2'
 *   2. sidecar down or invalid  -> Naive-Seasonal, model = 'naive-seasonal', degraded = 1
 *   3. context shorter than MIN -> that target is skipped with a reason, never guessed
 *
 * Scores stay comparable across all three because every run records which model produced
 * it. That is the same discipline that let the notebook merge two leaderboard sessions:
 * Naive-Seasonal read 0.6808 in both, so the join was legitimate.
 */
final class ForecastClient
{
    public function __construct(
        private JsonTransport $transport,
        private string $baseUrl,
        private int $timeoutSec = 120,
        private bool $fallbackEnabled = true,
        private NaiveSeasonal $naive = new NaiveSeasonal(),
    ) {
    }

    public static function fromConfig(?Config $config = null): self
    {
        $config ??= Config::load();
        return new self(
            new CurlJsonTransport(),
            rtrim($config->str('forecast.service_url', 'http://127.0.0.1:8008'), '/'),
            $config->int('forecast.timeout_sec', 120),
            $config->bool('forecast.fallback_enabled', true),
        );
    }

    /**
     * @param array<string,list<?float>> $contexts target => context values, oldest first
     * @param list<float> $quantileLevels
     */
    public function forecast(
        array $contexts,
        int $horizon = Domain::HORIZON,
        array $quantileLevels = Domain::QUANTILES,
    ): ForecastResult {
        $usable = [];
        $skipped = [];
        foreach ($contexts as $target => $values) {
            $known = 0;
            foreach ($values as $v) {
                if ($v !== null && is_finite($v)) {
                    $known++;
                }
            }
            if ($known < Domain::MIN_CONTEXT) {
                $skipped[] = $target;
                continue;
            }
            $usable[$target] = $values;
        }

        if ($usable === []) {
            return new ForecastResult('none', [], 0, true, 'no target had enough history', $skipped);
        }

        try {
            return $this->callSidecar($usable, $horizon, $quantileLevels, $skipped);
        } catch (\Throwable $e) {
            if (!$this->fallbackEnabled) {
                throw new ForecastException('forecast service failed: ' . $e->getMessage(), 0, $e);
            }
            return $this->fallback($usable, $horizon, $quantileLevels, $skipped, $e->getMessage());
        }
    }

    /**
     * @param array<string,list<?float>> $contexts
     * @param list<float> $quantileLevels
     * @param list<string> $skipped
     */
    private function callSidecar(array $contexts, int $horizon, array $quantileLevels, array $skipped): ForecastResult
    {
        $series = [];
        foreach ($contexts as $target => $values) {
            $series[] = [
                'id' => (string) $target,
                // Nulls travel as nulls. json_encode maps PHP null to JSON null and the
                // sidecar turns it into NaN, which Chronos-2 handles natively.
                'values' => array_map(
                    static fn(?float $v) => $v !== null && is_finite($v) ? $v : null,
                    array_values($values)
                ),
            ];
        }

        $response = $this->transport->postJson(
            $this->baseUrl . '/forecast',
            [
                'series'            => $series,
                'prediction_length' => $horizon,
                'quantile_levels'   => $quantileLevels,
            ],
            $this->timeoutSec
        );

        if (!$response->isOk()) {
            throw new ForecastException('sidecar HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 300));
        }

        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['forecasts']) || !is_array($payload['forecasts'])) {
            throw new ForecastException('sidecar returned an unexpected payload');
        }

        $forecasts = [];
        foreach ($payload['forecasts'] as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $median = $entry['median'] ?? null;
            $quantiles = $entry['quantiles'] ?? null;
            if ($id === '' || !is_array($median) || !is_array($quantiles)) {
                throw new ForecastException('sidecar returned a malformed forecast entry');
            }
            if (count($median) !== $horizon) {
                // Storing half a forecast as if it were whole would silently shorten
                // every horizon bucket in the scorecard.
                throw new ForecastException(
                    sprintf('sidecar returned %d steps for %s, expected %d', count($median), $id, $horizon)
                );
            }
            $normalised = [];
            foreach ($quantiles as $level => $column) {
                if (!is_array($column) || count($column) !== $horizon) {
                    throw new ForecastException("sidecar returned a short quantile column for {$id}");
                }
                $normalised[(string) $level] = array_map(static fn($v) => (float) $v, array_values($column));
            }
            $forecasts[$id] = [
                'median'    => array_map(static fn($v) => (float) $v, array_values($median)),
                'quantiles' => $normalised,
            ];
        }

        foreach (array_keys($contexts) as $target) {
            if (!isset($forecasts[$target])) {
                throw new ForecastException("sidecar did not return a forecast for {$target}");
            }
        }

        return new ForecastResult(
            self::shortModelName((string) ($payload['model'] ?? 'chronos-2')),
            $forecasts,
            (int) ($payload['elapsed_ms'] ?? 0),
            false,
            '',
            $skipped
        );
    }

    /**
     * @param array<string,list<?float>> $contexts
     * @param list<float> $quantileLevels
     * @param list<string> $skipped
     */
    private function fallback(array $contexts, int $horizon, array $quantileLevels, array $skipped, string $reason): ForecastResult
    {
        $started = microtime(true);
        $forecasts = [];
        foreach ($contexts as $target => $values) {
            $forecasts[(string) $target] = $this->naive->predict(
                array_values($values),
                $horizon,
                Domain::SEASONALITY,
                $quantileLevels
            );
        }
        return new ForecastResult(
            'naive-seasonal',
            $forecasts,
            (int) round((microtime(true) - $started) * 1000),
            true,
            'sidecar unavailable, naive fallback: ' . mb_substr($reason, 0, 200),
            $skipped
        );
    }

    /**
     * Short timeout on purpose. The dashboard calls this on every page load, and a
     * sidecar that is slow or wedged must never be able to hang the UI — an operator
     * staring at a blank page learns less than one reading "sidecar: DOWN".
     *
     * @return array<string,mixed>
     */
    public function health(int $timeoutSec = 3): array
    {
        try {
            $response = $this->transport->getJson($this->baseUrl . '/health', $timeoutSec);
            if (!$response->isOk()) {
                return ['status' => 'unavailable', 'error' => 'HTTP ' . $response->status];
            }
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : ['status' => 'unavailable', 'error' => 'bad payload'];
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e->getMessage()];
        }
    }

    /** 'amazon/chronos-2' -> 'chronos-2', so the leaderboard label matches the notebook. */
    private static function shortModelName(string $model): string
    {
        $parts = explode('/', $model);
        return $parts[count($parts) - 1];
    }
}
