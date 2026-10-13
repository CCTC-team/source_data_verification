-- Source Data Verification module tables.
-- Created on system enable with CREATE TABLE IF NOT EXISTS and never dropped,
-- so SDV history survives disabling/re-enabling or upgrading the module.
-- Statements are separated by ';' on their own line end and executed one at a time.

CREATE TABLE IF NOT EXISTS cctc_sdv_form (
    project_id    INT NOT NULL,
    record        VARCHAR(100) NOT NULL,
    event_id      INT NOT NULL,
    instrument    VARCHAR(100) NOT NULL,
    instance      INT NOT NULL DEFAULT 1,
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',
    status_reason TEXT NULL,
    snapshot      MEDIUMTEXT NULL,
    created_at    DATETIME NOT NULL,
    updated_by    VARCHAR(255) NULL,
    updated_at    DATETIME NULL,
    completed_by  VARCHAR(255) NULL,
    completed_at  DATETIME NULL,
    PRIMARY KEY (project_id, record, event_id, instrument, instance),
    KEY idx_status (project_id, status),
    KEY idx_instrument_status (project_id, instrument, status),
    KEY idx_updated (project_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cctc_sdv_field (
    project_id  INT NOT NULL,
    record      VARCHAR(100) NOT NULL,
    event_id    INT NOT NULL,
    instance    INT NOT NULL DEFAULT 1,
    field_name  VARCHAR(100) NOT NULL,
    instrument  VARCHAR(100) NOT NULL,
    status      VARCHAR(20) NOT NULL,
    value_hash  CHAR(64) NOT NULL,
    verified_by VARCHAR(255) NOT NULL,
    verified_at DATETIME NOT NULL,
    PRIMARY KEY (project_id, record, event_id, instance, field_name),
    KEY idx_form (project_id, record, event_id, instrument, instance),
    KEY idx_status (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cctc_sdv_plan (
    project_id  INT NOT NULL,
    field_name  VARCHAR(100) NOT NULL,
    requirement VARCHAR(20) NOT NULL,
    updated_by  VARCHAR(255) NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (project_id, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cctc_sdv_query (
    query_id   INT NOT NULL AUTO_INCREMENT,
    project_id INT NOT NULL,
    record     VARCHAR(100) NOT NULL,
    event_id   INT NOT NULL,
    instrument VARCHAR(100) NOT NULL,
    instance   INT NOT NULL DEFAULT 1,
    field_name VARCHAR(100) NOT NULL,
    status     VARCHAR(20) NOT NULL,
    raised_by  VARCHAR(255) NOT NULL,
    raised_at  DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (query_id),
    KEY idx_form (project_id, record, event_id, instance, instrument),
    KEY idx_status (project_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cctc_sdv_query_msg (
    msg_id        INT NOT NULL AUTO_INCREMENT,
    query_id      INT NOT NULL,
    action        VARCHAR(20) NOT NULL,
    response_code VARCHAR(100) NULL,
    text          TEXT NULL,
    username      VARCHAR(255) NOT NULL,
    ts            DATETIME NOT NULL,
    PRIMARY KEY (msg_id),
    KEY idx_query (query_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cctc_sdv_audit (
    audit_id   BIGINT NOT NULL AUTO_INCREMENT,
    project_id INT NOT NULL,
    ts         DATETIME NOT NULL,
    username   VARCHAR(255) NOT NULL,
    action     VARCHAR(40) NOT NULL,
    record     VARCHAR(100) NULL,
    event_id   INT NULL,
    instrument VARCHAR(100) NULL,
    instance   INT NULL,
    field_name VARCHAR(100) NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NULL,
    detail     TEXT NULL,
    PRIMARY KEY (audit_id),
    KEY idx_form (project_id, record, event_id, instrument, instance),
    KEY idx_ts (project_id, ts),
    KEY idx_action (project_id, action, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
