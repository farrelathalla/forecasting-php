<?php
declare(strict_types=1);

namespace Tbw\Repository;

use Tbw\Db;
use Tbw\EarlyWarning\AlarmState;

final class AlarmRepository
{
    public function __construct(private Db $db)
    {
    }

    public function loadState(string $channel, string $detector): AlarmState
    {
        $row = $this->db->selectOne(
            'SELECT * FROM alarm_state WHERE channel = ? AND detector = ?',
            [$channel, $detector]
        );
        return $row === null ? AlarmState::initial($channel) : AlarmState::fromRow($row);
    }

    public function saveState(AlarmState $state, string $detector, string $ts): void
    {
        $this->db->execute(
            'INSERT INTO alarm_state (channel, detector, tier, consecutive, last_value, last_ts)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 tier = VALUES(tier), consecutive = VALUES(consecutive),
                 last_value = VALUES(last_value), last_ts = VALUES(last_ts)',
            [$state->channel, $detector, $state->tier, $state->consecutive, $state->value, $ts]
        );
    }

    /** Only transitions are recorded. A steady alarm re-logged every cycle is noise. */
    public function recordTransition(AlarmState $state, string $detector, string $ts, array $evidence = []): void
    {
        $this->db->execute(
            'INSERT INTO alarm_event (channel, detector, tier, prev_tier, ts, value, threshold, evidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $state->channel,
                $detector,
                $state->tier,
                $state->previousTier,
                $ts,
                $state->value,
                $state->threshold,
                $evidence === [] ? null : json_encode($evidence, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /** @param list<array<string,mixed>> $states */
    public function upsertSpcStates(array $states, string $ts): int
    {
        if ($states === []) {
            return 0;
        }
        $rows = array_map(
            static fn(array $s) => [
                $s['channel'], $ts, $s['value'], $s['mu'], $s['sigma'],
                $s['lcl'], $s['ucl'], $s['drift_sigma'], $s['tier'],
            ],
            $states
        );
        return $this->db->upsert(
            'spc_state',
            ['channel', 'ts', 'value', 'mu', 'sigma', 'lcl', 'ucl', 'drift_sigma', 'tier'],
            $rows,
            ['value', 'drift_sigma', 'tier']
        );
    }

    public function insertProjection(string $channel, array $projection): void
    {
        $this->db->execute(
            'INSERT INTO projection (channel, slope_per_day, current_value, limit_value, days_to_limit, eta, n_points)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $channel,
                $projection['slope_per_day'],
                $projection['current'],
                $projection['limit'],
                $projection['days_to_limit'],
                $projection['eta'],
                $projection['n_points'],
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function currentStates(): array
    {
        return $this->db->select(
            'SELECT * FROM alarm_state ORDER BY
                FIELD(tier, "ALARM", "WARN", "OK"), channel'
        );
    }

    /** @return list<array<string,mixed>> */
    public function latestSpc(): array
    {
        return $this->db->select(
            'SELECT s.* FROM spc_state s
               JOIN (SELECT channel, MAX(ts) AS mx FROM spc_state GROUP BY channel) m
                 ON m.channel = s.channel AND m.mx = s.ts
              ORDER BY ABS(COALESCE(s.drift_sigma, 0)) DESC'
        );
    }

    /** @return list<array<string,mixed>> */
    public function latestProjections(): array
    {
        // Tie-break on id, not computed_at alone: two projections written inside the
        // same second share a timestamp and would both come back as "latest".
        return $this->db->select(
            'SELECT p.* FROM projection p
               JOIN (SELECT channel, MAX(id) AS mx FROM projection GROUP BY channel) m
                 ON m.channel = p.channel AND m.mx = p.id
              ORDER BY p.days_to_limit IS NULL, p.days_to_limit'
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentEvents(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM alarm_event ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
        );
    }
}
