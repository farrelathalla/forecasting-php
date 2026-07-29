<?php
declare(strict_types=1);

namespace Tbw\Ingest;

use DateTimeImmutable;
use Tbw\Config;
use Tbw\Domain;

/**
 * Reads https://apps.daesang.net/api/mqtt/latest.php.
 *
 * The endpoint is a snapshot: latest value per tag, no history, no range query. That is
 * why this system stores everything it polls — the database is not a cache, it is the
 * only forward history that will exist.
 */
final class SensorApiClient
{
    public function __construct(
        private HttpTransport $transport,
        private string $url,
        private string $token,
        private int $timeoutSec = 20,
    ) {
    }

    public static function fromConfig(?Config $config = null): self
    {
        $config ??= Config::load();
        return new self(
            new CurlTransport($config->bool('api.verify_tls', true)),
            $config->str('api.url'),
            $config->str('api.token'),
            $config->int('api.timeout_sec', 20),
        );
    }

    public function fetchLatest(): FetchResult
    {
        try {
            $response = $this->transport->get(
                $this->url,
                ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
                $this->timeoutSec
            );
        } catch (\Throwable $e) {
            throw new ApiException('sensor API transport failure: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->isOk()) {
            throw new ApiException(
                'sensor API returned HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 200)
            );
        }

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('sensor API returned malformed JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($payload)) {
            throw new ApiException('sensor API returned a non-object payload');
        }
        if (($payload['success'] ?? false) !== true) {
            throw new ApiException('sensor API reported failure: ' . (string) ($payload['message'] ?? 'no message'));
        }
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new ApiException('sensor API payload has no data array');
        }

        $readings = [];
        $sentinels = 0;
        $invalid = 0;

        foreach ($payload['data'] as $row) {
            if (!is_array($row) || !isset($row['tag'], $row['value'], $row['updated_at'])) {
                $invalid++;
                continue;
            }

            $parts = explode('/', (string) $row['tag']);
            if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
                $invalid++;
                continue;
            }
            [, $asset, $signal] = $parts;

            $raw = trim((string) $row['value']);
            if ($raw === '' || !is_numeric($raw)) {
                // Never coerce: (float)'FAULT' is 0.0, which reads as a stopped pump.
                $invalid++;
                continue;
            }
            $value = (float) $raw;

            // F5: unsigned 16-bit wraps from small negative raw values. Rare, but one
            // reading of 65535 against a 90-265 range destroys every statistic downstream.
            if (abs($value) >= Domain::SENTINEL_HI) {
                $sentinels++;
                continue;
            }

            $observedAt = self::parseTimestamp((string) $row['updated_at']);
            if ($observedAt === null) {
                $invalid++;
                continue;
            }

            $readings[] = new Reading($asset, $signal, $observedAt, $value);
        }

        return new FetchResult($readings, $sentinels, $invalid);
    }

    private static function parseTimestamp(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // The API sends 'Y-m-d H:i:s' in plant local time. Accept ISO 8601 too, in case
        // the endpoint is ever changed to match the historian's native nanosecond format.
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', DATE_ATOM] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
