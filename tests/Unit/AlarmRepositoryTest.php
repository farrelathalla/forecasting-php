<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\EarlyWarning\AlarmPolicy;
use Tbw\EarlyWarning\AlarmState;
use Tbw\Repository\AlarmRepository;
use Tests\DbTestCase;

final class AlarmRepositoryTest extends DbTestCase
{
    private AlarmRepository $alarms;

    public function setUp(): void
    {
        parent::setUp();
        $this->alarms = new AlarmRepository($this->db);
    }

    public function testUnknownChannelStartsAtOk(): void
    {
        $state = $this->alarms->loadState('dT|TBW1', 'spc');
        $this->assertSame('OK', $state->tier);
        $this->assertSame(0, $state->consecutive);
    }

    public function testConsecutiveCounterSurvivesAcrossJobRuns(): void
    {
        // min_consecutive only means anything if the count outlives the process. Each
        // evaluate run is a separate PHP invocation, so this has to round-trip the DB.
        $policy = new AlarmPolicy(3.0, 4.0, 4, 0.5);
        $ts = new \DateTimeImmutable('2026-07-29 14:00:00');

        for ($i = 0; $i < 4; $i++) {
            $at = $ts->modify("+{$i} hours")->format('Y-m-d H:i:s');
            $state = $this->alarms->loadState('dT|TBW3', 'spc');
            $state = $policy->classify($state, 6.27);
            $this->alarms->saveState($state, 'spc', $at);
        }

        $final = $this->alarms->loadState('dT|TBW3', 'spc');
        $this->assertSame('ALARM', $final->tier);
        $this->assertSame(4, $final->consecutive);
    }

    public function testOnlyTransitionsAreRecordedAsEvents(): void
    {
        $policy = new AlarmPolicy(3.0, 4.0, 1, 0.5);
        $ts = new \DateTimeImmutable('2026-07-29 14:00:00');

        for ($i = 0; $i < 5; $i++) {
            $at = $ts->modify("+{$i} hours")->format('Y-m-d H:i:s');
            $state = $this->alarms->loadState('dT|TBW1', 'spc');
            $state = $policy->classify($state, 6.0);
            $this->alarms->saveState($state, 'spc', $at);
            if ($state->changed) {
                $this->alarms->recordTransition($state, 'spc', $at);
            }
        }

        // A steady alarm re-logged every cycle is noise, not information.
        $this->assertSame(1, $this->count('alarm_event'));
    }

    public function testEventKeepsTheEvidenceThatTriggeredIt(): void
    {
        $policy = new AlarmPolicy(3.0, 4.0, 1, 0.5);
        $state = $policy->classify(AlarmState::initial('dT|TBW3'), 8.57);
        $this->alarms->recordTransition($state, 'spc', '2026-07-29 14:00:00', ['drift_sigma' => 8.57, 'mu' => -7.0]);

        $row = $this->db->selectOne('SELECT * FROM alarm_event');
        $this->assertSame('ALARM', $row['tier']);
        $this->assertSame('OK', $row['prev_tier']);
        $evidence = json_decode((string) $row['evidence'], true);
        $this->assertFloatEquals(8.57, $evidence['drift_sigma'], 1e-9);
    }

    public function testSpcStatesUpsertRatherThanAccumulate(): void
    {
        $state = [
            'channel' => 'dT|TBW1', 'value' => 4.0, 'mu' => -3.18, 'sigma' => 2.02,
            'lcl' => -9.26, 'ucl' => 2.89, 'drift_sigma' => 3.55, 'tier' => 'WARN',
        ];
        $this->alarms->upsertSpcStates([$state], '2026-07-29 14:00:00');
        $this->alarms->upsertSpcStates([$state], '2026-07-29 14:00:00');
        $this->assertSame(1, $this->count('spc_state'));
    }

    public function testCurrentStatesAreOrderedBySeverity(): void
    {
        $this->alarms->saveState(AlarmState::initial('a')->with(tier: 'OK'), 'spc', '2026-07-29 14:00:00');
        $this->alarms->saveState(AlarmState::initial('b')->with(tier: 'ALARM'), 'spc', '2026-07-29 14:00:00');
        $this->alarms->saveState(AlarmState::initial('c')->with(tier: 'WARN'), 'spc', '2026-07-29 14:00:00');

        $rows = $this->alarms->currentStates();
        $this->assertSame('ALARM', $rows[0]['tier']);
        $this->assertSame('WARN', $rows[1]['tier']);
        $this->assertSame('OK', $rows[2]['tier']);
    }

    public function testProjectionsKeepTheirHistoryButOnlyTheLatestIsRead(): void
    {
        $base = ['slope_per_day' => 0.1, 'current' => 5.0, 'limit' => 9.0, 'days_to_limit' => 40.0,
                 'eta' => '2026-09-07 00:00:00', 'n_points' => 100];
        $this->alarms->insertProjection('dT|TBW1', $base);
        $this->alarms->insertProjection('dT|TBW1', array_merge($base, ['days_to_limit' => 20.0]));

        $this->assertSame(2, $this->count('projection'));
        $latest = $this->alarms->latestProjections();
        $this->assertCount(1, $latest);
    }
}
