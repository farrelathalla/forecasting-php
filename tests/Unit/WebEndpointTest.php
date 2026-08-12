<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\DbTestCase;

/**
 * Runs each page and endpoint in a real PHP subprocess against the real database.
 *
 * Cheap, and it catches the class of defect that unit tests structurally cannot: a parse
 * error, a bad require path, or an endpoint that emits an HTML fatal inside what the
 * browser is parsing as JSON.
 */
final class WebEndpointTest extends DbTestCase
{
    private const API = ['status.php', 'forecast.php', 'alarms.php', 'scorecard.php', 'live.php'];

    /** One page. The UI is a forecast chart and an alarm verdict; nothing else earned a tab. */
    private const PAGES = ['index.php'];

    /** @var resource|null */
    private static $server = null;
    private static int $port = 8399;

    /**
     * Serves public/ with PHP's built-in server for the duration of the suite.
     *
     * Spawning `php page.php` directly does not work: the CLI SAPI never populates $_GET
     * from QUERY_STRING, so every query-parameter path would silently test the default
     * branch and pass for the wrong reason. A real HTTP request exercises the code the
     * browser actually hits.
     */
    private static function server(): void
    {
        if (self::$server !== null) {
            return;
        }
        $root = dirname(__DIR__, 2);

        // Log to files, never to pipes. The built-in server writes one access-log line
        // per request to stderr; with an unread pipe the OS buffer fills after a few
        // dozen requests and the server blocks forever on write. That looked exactly
        // like a page failing at random partway through the suite.
        $var = $root . '/var';
        if (!is_dir($var)) {
            mkdir($var, 0777, true);
        }
        $descriptors = [
            1 => ['file', $var . '/test-server.log', 'a'],
            2 => ['file', $var . '/test-server.log', 'a'],
        ];

        // Point the server at the test database, not production. Without this the
        // "survives an empty database" test would read real rows and pass for the
        // wrong reason, and a test run would be reading live plant data.
        $env = [
            'TBW_DB_NAME' => \Tbw\Config::load()->str('db.test_db', 'tbw_forecast_test'),
            'PATH'        => getenv('PATH') ?: '',
            'SystemRoot'  => getenv('SystemRoot') ?: 'C:\\Windows',
            'TEMP'        => getenv('TEMP') ?: '',
            // The built-in server is single-worker by default, so one page waiting on
            // an outbound call blocks every later request in the suite.
            'PHP_CLI_SERVER_WORKERS' => '4',
        ];

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root . '/public'],
            $descriptors,
            $pipes,
            $root . '/public',
            $env
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start the built-in PHP server');
        }
        self::$server = $process;

        register_shutdown_function(static function (): void {
            if (self::$server !== null) {
                proc_terminate(self::$server);
                proc_close(self::$server);
                self::$server = null;
            }
        });

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('the built-in PHP server did not come up');
    }

    private function run(string $script, array $query = []): array
    {
        self::server();

        $url = 'http://127.0.0.1:' . self::$port . '/' . $script;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
        $body = @file_get_contents($url, false, $context);

        return [
            'stdout' => $body === false ? '' : $body,
            'stderr' => '',
            'code'   => $body === false ? 1 : 0,
        ];
    }

    public function testEveryJsonEndpointReturnsValidJson(): void
    {
        foreach (self::API as $script) {
            $result = $this->run('api/' . $script);
            $this->assertSame(0, $result['code'], "api/{$script} exited {$result['code']}: {$result['stderr']}");

            $decoded = json_decode($result['stdout'], true);
            $this->assertTrue(
                is_array($decoded),
                "api/{$script} did not return JSON: " . mb_substr($result['stdout'], 0, 300)
            );
            $this->assertFalse(
                isset($decoded['error']),
                "api/{$script} returned an error: " . (string) ($decoded['error'] ?? '')
            );
        }
    }

    public function testEndpointsSurviveAnEmptyDatabase(): void
    {
        // A fresh install has no rows at all. Every endpoint must return a valid empty
        // structure rather than a fatal, or the first thing a new site sees is a crash.
        $this->truncateAll();

        foreach (self::API as $script) {
            $result = $this->run('api/' . $script);
            $decoded = json_decode($result['stdout'], true);
            $this->assertTrue(is_array($decoded), "api/{$script} failed on an empty database");
        }
    }

    public function testForecastEndpointRejectsAnUnknownTarget(): void
    {
        $result = $this->run('api/forecast.php', ['target' => 'NONSENSE|TBW9']);
        $decoded = json_decode($result['stdout'], true);
        $this->assertTrue(isset($decoded['error']), 'an unknown target must be refused, not guessed');
    }

    public function testForecastEndpointExposesTheUnit(): void
    {
        $result = $this->run('api/forecast.php', ['target' => 'FLOWRATE|TBW1']);
        $decoded = json_decode($result['stdout'], true);
        $this->assertSame('m3/min', $decoded['unit'], 'units confirmed by the plant on 2026-07-29');
    }

    public function testEveryPageRendersWithoutAFatal(): void
    {
        foreach (self::PAGES as $script) {
            $result = $this->run($script);
            $this->assertSame(0, $result['code'], "{$script} exited {$result['code']}: {$result['stderr']}");
            $this->assertStringContains('</html>', $result['stdout'], "{$script} did not finish rendering");
            $this->assertFalse(
                str_contains($result['stdout'], 'Fatal error'),
                "{$script} rendered a fatal error"
            );
            $this->assertFalse(
                str_contains($result['stdout'], 'Warning:'),
                "{$script} rendered a PHP warning"
            );
        }
    }

    public function testPagesRenderOnAnEmptyDatabase(): void
    {
        $this->truncateAll();
        foreach (self::PAGES as $script) {
            $result = $this->run($script);
            $this->assertStringContains('</html>', $result['stdout'], "{$script} failed on an empty database");
            $this->assertFalse(str_contains($result['stdout'], 'Fatal error'), "{$script} fatal on empty database");
        }
    }

    public function testALongSilentAssetIsDatedFromItsOwnLastReading(): void
    {
        // F1: TBW2 is a stopped machine, and the page has to say so with a date rather
        // than leave a blank tile that reads as a sensor fault. The date is derived, not
        // declared -- so this seeds a last reading and expects the page to find it.
        $this->truncateAll();
        $this->db->execute(
            'INSERT INTO grid_15min (asset, signal_name, ts, value, is_held) VALUES (?,?,?,?,?)',
            ['TBW2', 'POWER', '2026-05-14 10:00:00', 180.0, 0]
        );

        $html = $this->run('index.php')['stdout'];

        $this->assertStringContains('TBW2', $html);
        $this->assertStringContains('Sudah mati sejak 14 Mei 2026', $html);
    }

    public function testAnAssetThatStartsReportingAgainIsNoLongerShownAsDead(): void
    {
        // The station has already changed shape once (F1) and could again. Nothing about
        // the strip may be pinned to today's two-pump configuration: a pump that comes
        // back has to appear as running purely because its readings say so.
        $this->truncateAll();
        $at = date('Y-m-d H:i:s', time() - 30);
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES (?,?,?,?), (?,?,?,?)',
            ['TBW2', 'MOTOR_CURRENT', $at, 190.0, 'TBW2', 'POWER', $at, 186.0]
        );

        $html = $this->run('index.php')['stdout'];

        $this->assertStringContains('JALAN', $html);
        $this->assertFalse(
            str_contains($html, 'Sudah mati sejak'),
            'a reporting asset must not still be rendered as long dead'
        );
    }

    public function testLiveEndpointServesPollResolutionNotTheModelGrid(): void
    {
        // The live chart exists because the 15-minute modelling grid only moves four
        // times an hour and therefore never looks alive. This endpoint must serve the
        // raw readings instead, or the whole point is lost.
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES
                (?,?,?,?), (?,?,?,?), (?,?,?,?)',
            [
                'TBW1', 'POWER', date('Y-m-d H:i:s', time() - 180), 183.0,
                'TBW1', 'POWER', date('Y-m-d H:i:s', time() - 120), 184.0,
                'TBW3', 'POWER', date('Y-m-d H:i:s', time() - 60), 171.0,
            ]
        );

        $body = json_decode($this->run('api/live.php', ['signal' => 'POWER', 'minutes' => 60])['stdout'], true);

        $this->assertSame('POWER', $body['signal']);
        $this->assertSame('kW', $body['unit']);
        $this->assertSame(60, $body['resolution_sec']);
        $this->assertCount(2, $body['assets']['TBW1']);
        $this->assertCount(1, $body['assets']['TBW3']);
        $this->assertFalse($body['incremental']);
    }

    public function testLiveEndpointReturnsOnlyNewerPointsWhenGivenSince(): void
    {
        // The page polls every 15 s. Refetching the whole window each time would move
        // the same few hundred rows across the wire for nothing.
        $old = date('Y-m-d H:i:s', time() - 300);
        $new = date('Y-m-d H:i:s', time() - 60);
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES (?,?,?,?), (?,?,?,?)',
            ['TBW1', 'POWER', $old, 183.0, 'TBW1', 'POWER', $new, 186.0]
        );

        $body = json_decode($this->run('api/live.php', ['signal' => 'POWER', 'since' => $old])['stdout'], true);

        $this->assertTrue($body['incremental']);
        $this->assertCount(1, $body['assets']['TBW1'], 'since must be exclusive: the client already holds that point');
        $this->assertFloatEquals(186.0, $body['assets']['TBW1'][0]['value'], 1e-9);
    }

    public function testLiveEndpointRejectsAnUnknownSignal(): void
    {
        $body = json_decode($this->run('api/live.php', ['signal' => 'NONSENSE'])['stdout'], true);
        $this->assertTrue(isset($body['error']));
    }

    public function testLiveEndpointReportsLatestTimestampForIncrementalPolling(): void
    {
        $at = date('Y-m-d H:i:s', time() - 60);
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES (?,?,?,?)',
            ['TBW1', 'FLOWRATE', $at, 33.4]
        );
        $body = json_decode($this->run('api/live.php', ['signal' => 'FLOWRATE'])['stdout'], true);
        $this->assertSame($at, $body['latest_ts']);
        $this->assertSame('m3/min', $body['unit']);
    }

    public function testTheSinglePageCarriesBothTheForecastChartAndTheAlarmVerdict(): void
    {
        // The whole point of collapsing four pages into one: the forecast and the thing
        // that judges it must be readable without a click. If either drifts off this
        // page, the consolidation has quietly undone itself.
        $html = $this->run('index.php')['stdout'];

        $this->assertStringContains('id="chart"', $html);
        $this->assertStringContains('api/forecast.php', $html);
        $this->assertStringContains('Peringatan dini', $html);
        $this->assertStringContains('Seberapa jauh dari normal', $html);
    }

    public function testThePageSpeaksOperatorLanguageNotModellingLanguage(): void
    {
        // The dashboard is read on shift by people who never opened the notebook. Internal
        // channel keys and statistical units were the single biggest complaint about the
        // first version: "dT", "flow_per_kW" and sigma are meaningless in a control room,
        // and a number nobody can read is a number nobody acts on.
        // Seeded rather than borrowed from whatever the suite left behind: the assertion
        // is that these two channels render under their plant names, so the rows have to
        // be there regardless of test order.
        $this->truncateAll();
        $at = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO spc_state (channel, ts, value, mu, sigma, lcl, ucl, drift_sigma, tier) VALUES
                (?,?,?,?,?,?,?,?,?), (?,?,?,?,?,?,?,?,?)',
            [
                'dT|TBW3', $at, 6.675, -7.004, 1.597, -11.794, -2.213, 8.567, 'ALARM',
                'hyd_eff|TBW1', $at, 0.916, 0.882, 0.067, 0.680, 1.084, 0.506, 'OK',
            ]
        );

        $html = $this->run('index.php')['stdout'];

        // Strip hover text before asserting. The exact sigma figure is deliberately kept
        // in title attributes so every number stays traceable to its row -- it just must
        // not be what the operator has to decode to use the page.
        $visible = preg_replace('/title="[^"]*"/', '', $html) ?? $html;

        foreach (['dT|', 'flow_per_kW', 'P_over_I', 'hyd_eff', 'σ', 'sigma'] as $jargon) {
            $this->assertFalse(
                str_contains($visible, $jargon),
                "index.php still shows the internal name '{$jargon}' to the operator"
            );
        }

        $this->assertStringContains('Kenaikan suhu air', $html);
        $this->assertStringContains('Efisiensi pompa', $html);
    }

    public function testAStoppedPumpIsDistinguishedFromADeadFeed(): void
    {
        // Both render as zeroes and they demand opposite responses: one is a pump that is
        // switched off, the other is a telemetry link that died while the page kept
        // cheerfully showing its last reading. The page has to date every value.
        $this->truncateAll();
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES
                (?,?,?,?), (?,?,?,?), (?,?,?,?), (?,?,?,?)',
            [
                'TBW1', 'MOTOR_CURRENT', date('Y-m-d H:i:s', time() - 30), 0.1,
                'TBW1', 'POWER', date('Y-m-d H:i:s', time() - 30), 0.0,
                'TBW3', 'MOTOR_CURRENT', date('Y-m-d H:i:s', time() - 2 * 86400), 0.1,
                'TBW3', 'POWER', date('Y-m-d H:i:s', time() - 2 * 86400), 0.0,
            ]
        );

        $html = $this->run('index.php')['stdout'];

        $this->assertStringContains('data baru saja', $html);
        $this->assertStringContains('data terakhir 2 hari lalu', $html);
    }

    public function testHelperScriptLoadsBeforeAnyInlineScriptThatUsesIt(): void
    {
        // Regression: app.js used to sit at the end of <body>, so every inline chart
        // block ran first and died on "fetchJson is not defined" — leaving blank charts
        // on pages that rendered a perfectly valid HTTP 200. Server-side tests cannot
        // execute JS, so the ordering itself is what gets asserted.
        foreach (self::PAGES as $script) {
            $html = $this->run($script)['stdout'];

            $helperAt = strpos($html, 'assets/app.js');
            $this->assertTrue($helperAt !== false, "{$script} does not load assets/app.js");

            foreach (['fetchJson(', 'drawChart('] as $symbol) {
                $useAt = strpos($html, $symbol);
                if ($useAt === false) {
                    continue;
                }
                $this->assertTrue(
                    $helperAt < $useAt,
                    "{$script} calls {$symbol} before assets/app.js is loaded"
                );
            }
        }
    }

    public function testHelperIsLoadedInTheHead(): void
    {
        $html = $this->run('index.php')['stdout'];
        $headEnd = strpos($html, '</head>');
        $helperAt = strpos($html, 'assets/app.js');
        $this->assertTrue($headEnd !== false && $helperAt !== false);
        $this->assertTrue($helperAt < $headEnd, 'assets/app.js must load in <head>, before any page script');
    }

}
