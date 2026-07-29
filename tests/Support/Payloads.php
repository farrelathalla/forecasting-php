<?php
declare(strict_types=1);

namespace Tests\Support;

/** Payload shapes copied from a live call to apps.daesang.net on 2026-07-29 13:29. */
final class Payloads
{
    public static function live(string $observedAt = '2026-07-29 13:29:10'): array
    {
        $values = [
            'TBW1' => [
                'FLOWRATE' => '33.4', 'INLET_PRESS' => '201', 'INLET_TEMP' => '38.6',
                'MOTOR_CURRENT' => '183.5', 'MOTOR_RPM' => '45.9',
                'OUTLET_PRESSURE' => '5.19', 'OUTLET_TEMP' => '43.8', 'POWER' => '183.5',
            ],
            'TBW3' => [
                'FLOWRATE' => '29.5', 'INLET_PRESS' => '78', 'INLET_TEMP' => '41.2',
                'MOTOR_CURRENT' => '183.9', 'MOTOR_RPM' => '45.9',
                'OUTLET_PRESSURE' => '5.17', 'OUTLET_TEMP' => '44.7', 'POWER' => '171.4',
            ],
        ];
        $data = [];
        foreach ($values as $asset => $signals) {
            foreach ($signals as $signal => $value) {
                $data[] = [
                    'tag'        => "AIR_INSTRUMENT/{$asset}/{$signal}",
                    'value'      => $value,
                    'updated_at' => $observedAt,
                ];
            }
        }
        return ['success' => true, 'message' => 'ok', 'count' => count($data), 'data' => $data];
    }

    /** @param list<array{string,string,string}> $rows tag/value/updated_at triples */
    public static function custom(array $rows): array
    {
        $data = array_map(
            static fn(array $r) => ['tag' => $r[0], 'value' => $r[1], 'updated_at' => $r[2]],
            $rows
        );
        return ['success' => true, 'message' => 'ok', 'count' => count($data), 'data' => $data];
    }
}
