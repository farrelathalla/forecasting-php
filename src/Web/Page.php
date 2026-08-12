<?php
declare(strict_types=1);

namespace Tbw\Web;

use Tbw\Config;

/**
 * Shared page chrome. Plain PHP templating — no engine, nothing to install.
 *
 * There is no nav because there is one page. Splitting a forecast chart and its alarm
 * table across four tabs made an operator click to answer a single question.
 */
final class Page
{
    public static function open(string $title): void
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
        <link rel="icon" type="image/webp" href="assets/img/favicon.webp">
        <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192.png">
        <link rel="icon" href="favicon.ico" sizes="any">
        <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
        <link rel="stylesheet" href="assets/app.css">
        <script src="assets/app.js"></script>
        </head>
        <body>
        <header class="topbar">
          <img class="brand-logo" src="assets/img/logo-daesang.webp"
               alt="Daesang Ingredients Indonesia" width="40" height="39">
          <div class="brand">
            <span class="dot"></span>
            <strong>TBW</strong> Pump Station
            <span class="sub">Driyorejo, Gresik &middot; peramalan &amp; peringatan dini</span>
          </div>
          <div class="clock" id="clock"></div>
        </header>
        <main>
        HTML;
    }

    public static function close(): void
    {
        // Deliberately bare. Everything that used to live down here -- model name,
        // horizon, grid resolution, the unit table -- was provenance for the people who
        // built the system, printed at the people who operate it. The facts that change
        // how a shift is run are on the page itself, in the row they belong to.
        echo <<<HTML
        </main>
        <footer>
          <p>TBW Pump Station &middot; Driyorejo, Gresik &middot; halaman menyegarkan sendiri tiap 5 menit</p>
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
