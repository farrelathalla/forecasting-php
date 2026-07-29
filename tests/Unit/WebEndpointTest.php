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
    private const PAGES = ['index.php', 'forecast.php', 'alarms.php', 'model.php'];

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

    public function testRetiredAssetIsShownAsRetiredNotAsMissingData(): void
    {
        // F1: TBW2 is a stopped machine. A dashboard that shows it as "no data" invites
        // someone to go looking for a sensor fault that does not exist.
        $result = $this->run('index.php');
        $this->assertStringContains('RETIRED', $result['stdout']);
        $this->assertStringContains('TBW2', $result['stdout']);
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

    public function testDashboardCarriesTheLiveChartAndItsControls(): void
    {
        $html = $this->run('index.php')['stdout'];
        $this->assertStringContains('id="live"', $html);
        $this->assertStringContains('api/live.php', $html);
        $this->assertStringContains('id="livesignal"', $html);
        // The 1-minute ceiling is a property of the snapshot API, not of the chart, and
        // the page has to say so or someone will try to fix it in the wrong place.
        $this->assertStringContains('satu titik per menit', $html);
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

    public function testInletPressIsLabelledAsSuspect(): void
    {
        // F4: the tag is mislabelled at source. The UI must not present it as a pressure.
        $result = $this->run('index.php');
        $this->assertStringContains('counter?', $result['stdout']);
    }
}
