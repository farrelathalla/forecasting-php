-- TBW realtime forecasting schema (MariaDB 10.4+, XAMPP default).
--
-- Every result table carries a UNIQUE key so re-running a job overwrites instead of
-- appending. This is deliberate: the notebook's append-only RESULTS list silently
-- reported PatchTST at n=81 instead of 72 and made its score untrustworthy.
--
-- Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS reading_raw (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset         VARCHAR(16)  NOT NULL,
    signal_name   VARCHAR(32)  NOT NULL,
    observed_at   DATETIME     NOT NULL COMMENT 'updated_at from the historian, never local clock',
    value         DOUBLE       NOT NULL,
    ingested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reading (asset, signal_name, observed_at),
    KEY idx_reading_at (observed_at),
    KEY idx_reading_sig (signal_name, asset, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15-minute grid, one row per (asset, signal). value NULL means genuinely unknown:
-- the gap exceeded HOLD_LIMIT_MIN and LOCF was stopped (F6).
CREATE TABLE IF NOT EXISTS grid_15min (
    asset         VARCHAR(16)  NOT NULL,
    signal_name   VARCHAR(32)  NOT NULL,
    ts            DATETIME     NOT NULL,
    value         DOUBLE       NULL,
    is_held       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = carried forward, not a fresh reading',
    source        VARCHAR(8)   NOT NULL DEFAULT 'live' COMMENT 'live | seed',
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grid (asset, signal_name, ts),
    KEY idx_grid_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The 9 modelled targets (notebook section 10). 24 nominal series -> 9.
CREATE TABLE IF NOT EXISTS target_15min (
    target        VARCHAR(48)  NOT NULL,
    ts            DATETIME     NOT NULL,
    value         DOUBLE       NULL,
    source        VARCHAR(8)   NOT NULL DEFAULT 'live',
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_target (target, ts),
    KEY idx_target_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Physics relationships: dT, P_over_I, flow_per_kW, hyd_eff. Not forecast — monitored.
-- These catch the plausible-looking fault: a signal inside its normal range whose
-- relationship to the others has broken.
CREATE TABLE IF NOT EXISTS physics_15min (
    channel       VARCHAR(48)  NOT NULL COMMENT 'e.g. dT|TBW1',
    ts            DATETIME     NOT NULL,
    value         DOUBLE       NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_physics (channel, ts),
    KEY idx_physics_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forecast_run (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    model             VARCHAR(32)  NOT NULL,
    origin_ts         DATETIME     NOT NULL COMMENT 'first forecast step; context ends strictly before this',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    elapsed_ms        INT UNSIGNED NOT NULL DEFAULT 0,
    degraded          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = sidecar unreachable, naive fallback used',
    context_coverage  DOUBLE       NOT NULL DEFAULT 0 COMMENT 'fraction of context steps that were real observations',
    n_targets         SMALLINT     NOT NULL DEFAULT 0,
    note              VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_run (model, origin_ts),
    KEY idx_run_origin (origin_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forecast_point (
    run_id        BIGINT UNSIGNED NOT NULL,
    target        VARCHAR(48)  NOT NULL,
    ts            DATETIME     NOT NULL,
    horizon_step  SMALLINT     NOT NULL COMMENT '1..96',
    q10 DOUBLE NULL, q20 DOUBLE NULL, q30 DOUBLE NULL, q40 DOUBLE NULL,
    q50 DOUBLE NULL, q60 DOUBLE NULL, q70 DOUBLE NULL, q80 DOUBLE NULL, q90 DOUBLE NULL,
    UNIQUE KEY uq_point (run_id, target, ts),
    KEY idx_point_ts (ts, target),
    CONSTRAINT fk_point_run FOREIGN KEY (run_id) REFERENCES forecast_run (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Scored only once the actuals have matured. cov80 is stored separately from wql on
-- purpose: a model can hold good WQL while being systematically over-confident, and an
-- under-covering forecaster is what makes the alarm layer scream (notebook section 15).
CREATE TABLE IF NOT EXISTS forecast_score (
    run_id        BIGINT UNSIGNED NOT NULL,
    target        VARCHAR(48)  NOT NULL,
    model         VARCHAR(32)  NOT NULL,
    origin_ts     DATETIME     NOT NULL,
    mase          DOUBLE       NULL,
    wql           DOUBLE       NULL,
    cov80         DOUBLE       NULL,
    rmse          DOUBLE       NULL,
    mae           DOUBLE       NULL,
    n_points      SMALLINT     NOT NULL DEFAULT 0,
    scored_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_score (run_id, target),
    KEY idx_score_model (model, origin_ts),
    CONSTRAINT fk_score_run FOREIGN KEY (run_id) REFERENCES forecast_run (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SPC against control limits frozen from the healthy window 2026-05-20 -> 2026-06-05.
-- Limits are never recomputed from running data: slow degradation would drag its own
-- baseline along and the alarm would never fire.
CREATE TABLE IF NOT EXISTS spc_state (
    channel       VARCHAR(48)  NOT NULL,
    ts            DATETIME     NOT NULL,
    value         DOUBLE       NULL,
    mu            DOUBLE       NOT NULL,
    sigma         DOUBLE       NOT NULL,
    lcl           DOUBLE       NOT NULL,
    ucl           DOUBLE       NOT NULL,
    drift_sigma   DOUBLE       NULL,
    tier          VARCHAR(8)   NOT NULL DEFAULT 'OK',
    UNIQUE KEY uq_spc (channel, ts),
    KEY idx_spc_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Huber trend to an operating limit. Huber, not OLS: trips would drag the slope.
CREATE TABLE IF NOT EXISTS projection (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel        VARCHAR(48)  NOT NULL,
    computed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    slope_per_day  DOUBLE       NULL,
    current_value  DOUBLE       NULL,
    limit_value    DOUBLE       NULL,
    days_to_limit  DOUBLE       NULL COMMENT 'NULL when the trend moves away from the limit',
    eta            DATETIME     NULL,
    n_points       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_proj (channel, computed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tier transitions only, with the evidence that caused them. An alarm without its
-- trigger value is not actionable.
CREATE TABLE IF NOT EXISTS alarm_event (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel       VARCHAR(48)  NOT NULL,
    detector      VARCHAR(16)  NOT NULL COMMENT 'cusum | spc | projection',
    tier          VARCHAR(8)   NOT NULL COMMENT 'OK | WARN | ALARM',
    prev_tier     VARCHAR(8)   NOT NULL DEFAULT 'OK',
    ts            DATETIME     NOT NULL,
    value         DOUBLE       NULL,
    threshold     DOUBLE       NULL,
    evidence      TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alarm (channel, ts),
    KEY idx_alarm_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current tier per channel plus the consecutive-breach counter that AlarmPolicy needs
-- to survive across job runs (min_consecutive).
CREATE TABLE IF NOT EXISTS alarm_state (
    channel       VARCHAR(48)  NOT NULL,
    detector      VARCHAR(16)  NOT NULL,
    tier          VARCHAR(8)   NOT NULL DEFAULT 'OK',
    consecutive   INT          NOT NULL DEFAULT 0,
    last_value    DOUBLE       NULL,
    last_ts       DATETIME     NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (channel, detector)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_run (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job           VARCHAR(32)  NOT NULL,
    started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   DATETIME     NULL,
    status        VARCHAR(12)  NOT NULL DEFAULT 'running' COMMENT 'running | ok | error',
    rows_affected INT          NOT NULL DEFAULT 0,
    message       TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
