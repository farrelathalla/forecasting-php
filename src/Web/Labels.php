<?php
declare(strict_types=1);

namespace Tbw\Web;

use DateTimeImmutable;

/**
 * Turns internal names into what an operator actually calls the thing.
 *
 * The database keys are modelling names -- dT, flow_per_kW, POWER|TBW1 -- and they have to
 * stay that way, because every number on the page has to trace back to a row and to a
 * finding. But a control room reads "kenaikan suhu air", not "dT", and nobody outside a
 * statistics class reads sigma. Translation belongs here, in one place, rather than
 * scattered through the template where two pages would drift apart.
 */
final class Labels
{
    /**
     * Physics channels. `help` is the one-line explanation shown under the name -- these
     * are relationships between signals, so the name alone never tells the whole story.
     */
    private const CHANNELS = [
        'dT' => [
            'name' => 'Kenaikan suhu air',
            'help' => 'Suhu air keluar dikurangi suhu air masuk',
            'unit' => '°C',
        ],
        'P_over_I' => [
            'name' => 'Daya banding arus',
            'help' => 'Daya listrik dibagi arus motor',
            'unit' => 'kW/A',
        ],
        'flow_per_kW' => [
            'name' => 'Air per listrik',
            'help' => 'Debit air yang dihasilkan tiap 1 kW listrik',
            'unit' => 'm³/min per kW',
        ],
        'hyd_eff' => [
            'name' => 'Efisiensi pompa',
            'help' => 'Tenaga air yang dihasilkan dibanding listrik yang dipakai',
            'unit' => '',
        ],
    ];

    /** Forecast targets, as named in the chart selector. */
    private const SIGNALS = [
        'HEADER_PRESSURE' => 'Tekanan header stasiun',
        'FLOWRATE'        => 'Debit air',
        'POWER'           => 'Daya listrik',
        'OUTLET_TEMP'     => 'Suhu air keluar',
        'INLET_TEMP'      => 'Suhu air masuk',
        'MOTOR_CURRENT'   => 'Arus motor',
        'MOTOR_RPM'       => 'Putaran motor',
        'INLET_PRESS'     => 'Penghitung inlet',
        'OUTLET_PRESSURE' => 'Tekanan keluar',
    ];

    /** Units, rewritten for display (the database stores ASCII forms like m3/min). */
    private const UNITS = [
        'm3/min' => 'm³/min',
        'kg/cm2' => 'kg/cm²',
    ];

    /** "dT|TBW1" -> ["Kenaikan suhu air", "TBW1"] */
    public static function channel(string $key): array
    {
        [$base, $asset] = array_pad(explode('|', $key), 2, null);
        $meta = self::CHANNELS[$base] ?? ['name' => $base, 'help' => '', 'unit' => ''];

        return [
            'name'  => $meta['name'],
            'help'  => $meta['help'],
            'unit'  => $meta['unit'],
            'asset' => $asset,
        ];
    }

    /** "POWER|TBW1" -> "Daya listrik — TBW1" */
    public static function target(string $key): string
    {
        [$signal, $asset] = array_pad(explode('|', $key), 2, null);
        $name = self::SIGNALS[$signal] ?? $signal;

        return $asset === null ? $name : $name . ' — ' . $asset;
    }

    public static function unit(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        return self::UNITS[$raw] ?? $raw;
    }

    /**
     * How far a channel sits from its healthy baseline, in words.
     *
     * The underlying number is a sigma distance, and it stays in the data and in the
     * hover text because the tiering is derived from it. What it must not do is be the
     * headline: "+7.20 sigma" tells an operator nothing they can act on, while "jauh di
     * atas normal" tells them the direction and the size at a glance.
     *
     * @return array{label:string,short:string,fill:float}
     */
    public static function drift(?float $z): array
    {
        if ($z === null) {
            return ['label' => 'belum terukur', 'short' => '—', 'fill' => 0.0];
        }

        $magnitude = abs($z);
        $direction = $z >= 0 ? 'di atas' : 'di bawah';

        // 6 sigma saturates the bar. Beyond that the bar stops growing but the tier and
        // the hover number still separate a bad channel from a catastrophic one.
        $fill = min($magnitude / 6.0, 1.0);

        if ($magnitude < 1.0) {
            return ['label' => 'normal', 'short' => 'Normal', 'fill' => $fill];
        }
        if ($magnitude < 2.0) {
            return ['label' => 'sedikit ' . $direction . ' normal', 'short' => 'Sedikit ' . $direction, 'fill' => $fill];
        }
        if ($magnitude < 3.0) {
            return ['label' => 'mulai menjauh ' . $direction . ' normal', 'short' => 'Mulai menjauh', 'fill' => $fill];
        }
        return ['label' => 'jauh ' . $direction . ' normal', 'short' => 'Jauh ' . $direction, 'fill' => $fill];
    }

    /** Status words for a tier, in the operator's language rather than the model's. */
    public static function tier(string $tier): string
    {
        return match ($tier) {
            'ALARM' => 'Perlu diperiksa',
            'WARN'  => 'Diawasi',
            default => 'Aman',
        };
    }

    /**
     * "2 menit lalu", "3 hari lalu".
     *
     * The page leans on this to answer the question the old dashboard could not: a pump
     * reading zero because it is switched off looks identical to a pump reading zero
     * because its feed died three days ago, unless the page says how old the number is.
     */
    public static function ago(?string $ts, ?DateTimeImmutable $now = null): string
    {
        if ($ts === null || $ts === '') {
            return 'belum ada data';
        }

        $now ??= new DateTimeImmutable();
        $seconds = $now->getTimestamp() - (new DateTimeImmutable($ts))->getTimestamp();

        if ($seconds < 0) {
            return 'baru saja';
        }
        if ($seconds < 90) {
            return 'baru saja';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' menit lalu';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' jam lalu';
        }
        return intdiv($seconds, 86400) . ' hari lalu';
    }

    /** A feed is stale once it has missed far more than the 5 s historian tick (F6). */
    public static function isStale(?string $ts, int $minutes = 30, ?DateTimeImmutable $now = null): bool
    {
        if ($ts === null || $ts === '') {
            return true;
        }
        $now ??= new DateTimeImmutable();
        return ($now->getTimestamp() - (new DateTimeImmutable($ts))->getTimestamp()) > $minutes * 60;
    }

    /**
     * A machine silent this long is not a feed hiccup, it is a machine that is out of
     * service. Seven days is comfortably past any outage the station has recorded, so
     * nothing that is merely stopped for the weekend gets written off.
     */
    public const DORMANT_DAYS = 7;

    public static function isDormant(?string $ts, ?DateTimeImmutable $now = null): bool
    {
        if ($ts === null || $ts === '') {
            return true;
        }
        $now ??= new DateTimeImmutable();
        return ($now->getTimestamp() - (new DateTimeImmutable($ts))->getTimestamp()) > self::DORMANT_DAYS * 86400;
    }

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** "2026-05-14 10:00:00" -> "14 Mei 2026" */
    public static function dateId(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return 'tanggal tidak diketahui';
        }
        $d = new DateTimeImmutable($ts);

        return $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y');
    }
}
