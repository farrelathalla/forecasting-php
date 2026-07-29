<?php
declare(strict_types=1);

namespace Tbw\Web;

use Tbw\Config;

/** Shared page chrome. Plain PHP templating — no engine, nothing to install. */
final class Page
{
    private const NAV = [
        'index.php'    => 'Dashboard',
        'forecast.php' => 'Forecast',
        'alarms.php'   => 'Peringatan Dini',
        'model.php'    => 'Kesehatan Model',
    ];

    public static function open(string $title, string $active = 'index.php'): void
    {
        $config = Config::load();
        $appTitle = $config->str('app.title', 'TBW Forecast');
        $escaped = htmlspecialchars($title, ENT_QUOTES);
        $appEscaped = htmlspecialchars($appTitle, ENT_QUOTES);

        echo <<<HTML
        <!doctype html>
        <html lang="id">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$escaped} — {$appEscaped}</title>
        <link rel="stylesheet" href="assets/app.css">
        <script src="assets/app.js"></script>
        </head>
        <body>
        <header class="topbar">
          <div class="brand">
            <span class="dot"></span>
            <strong>TBW</strong> Pump Station
            <span class="sub">Driyorejo, Gresik</span>
          </div>
          <nav>
        HTML;

        foreach (self::NAV as $href => $label) {
            $class = $href === $active ? ' class="active"' : '';
            echo "<a href=\"{$href}\"{$class}>{$label}</a>";
        }

        echo <<<HTML
          </nav>
          <div class="clock" id="clock"></div>
        </header>
        <main>
        HTML;
    }

    public static function close(): void
    {
        $year = date('Y');
        echo <<<HTML
        </main>
        <footer>
          <p>
            Chronos-2 zero-shot &middot; horizon 24 jam &middot; grid 15 menit &middot;
            batas SPC dibekukan dari jendela sehat 2026-05-20 &rarr; 2026-06-05
          </p>
          <p class="muted">
            Satuan: FLOWRATE m&sup3;/min &middot; POWER kW &middot; MOTOR_CURRENT A &middot;
            OUTLET_PRESSURE kg/cm&sup2; &middot; suhu &deg;C.
            <strong>INLET_PRESS bukan tekanan</strong> &mdash; lihat catatan F4 di PLAN.md.
          </p>
          <p class="muted">&copy; {$year} &middot; dibangun di atas temuan notebook riset TBW</p>
        </footer>
        </body>
        </html>
        HTML;
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** Formats a number, or an em dash when it is genuinely unknown. */
    public static function num(mixed $v, int $decimals = 2, string $suffix = ''): string
    {
        if ($v === null || $v === '' || (is_float($v) && !is_finite($v))) {
            return '<span class="na">&mdash;</span>';
        }
        return number_format((float) $v, $decimals) . self::e($suffix);
    }

    public static function tierClass(string $tier): string
    {
        return match ($tier) {
            'ALARM' => 'tier-alarm',
            'WARN'  => 'tier-warn',
            default => 'tier-ok',
        };
    }
}
