-- =============================================================================
-- Aggregate Analytics production schema parity update
-- Target: aggregate_prod_1 on MariaDB 10.6+
-- Source reference:
--   aggregate_prod_1_2026-07-24_22-42-44.sql
-- Source dump SHA-256:
--   3a79cbfe5757ca37d8ce7f73bb751fb729bbc6ade494293922ee04450c796d55
-- Target application migration:
--   DoctrineMigrations\Version20260724002000
-- =============================================================================
--
-- IMPORTANT OPERATIONAL REQUIREMENTS
--
-- 1. Upgrade production from MariaDB 10.3.39 to MariaDB 10.6 or newer first.
--    The current application and geographic BI view require MariaDB 10.6+.
-- 2. Put the application in maintenance mode and stop every ingestion worker.
-- 3. Take and verify a restorable database backup immediately before running.
-- 4. Run this file without a client "continue on error" / --force option.
-- 5. Run it as a user that may ALTER/CREATE/DROP tables, indexes, views, and
--    temporary stored procedures in aggregate_prod_1.
--
-- DATA LOSS IS INTENTIONAL AND MATCHES Version20260724000500:
-- Existing rows are classified as enhanced during the transition, then every
-- enhanced row without consent_state='granted' is deleted. In the reference
-- dump all 1,645 event rows have consent_state='unknown', so all 1,645 would be
-- deleted. daily_ip_hash is also permanently dropped. Restore from the backup
-- if this is not the intended privacy-policy outcome.
--
-- MariaDB implicitly commits DDL. This script is therefore restartable rather
-- than transactional: every schema operation is guarded where MariaDB permits,
-- views are replaced, cleanup DELETEs are repeatable, and migration markers are
-- written only after their corresponding phase succeeds.
-- =============================================================================

USE `aggregate_prod_1`;

SET @aggregate_old_time_zone = @@SESSION.time_zone;
SET SESSION time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Preflight: refuse the wrong database generation or unsupported server.
-- A failed CALL must stop the SQL client; do not use --force.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_aggregate_preflight_20260724`;

DELIMITER //
CREATE PROCEDURE `_aggregate_preflight_20260724`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_server_core VARCHAR(32);
    DECLARE v_server_major INT DEFAULT 0;
    DECLARE v_server_minor INT DEFAULT 0;

    IF DATABASE() <> 'aggregate_prod_1' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Wrong database: expected aggregate_prod_1';
    END IF;

    IF VERSION() NOT LIKE '%MariaDB%' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unsupported server: this update targets MariaDB 10.6+';
    END IF;

    SET v_server_core = SUBSTRING_INDEX(VERSION(), '-', 1);
    SET v_server_major = CAST(SUBSTRING_INDEX(v_server_core, '.', 1) AS UNSIGNED);
    SET v_server_minor = CAST(
        SUBSTRING_INDEX(SUBSTRING_INDEX(v_server_core, '.', 2), '.', -1)
        AS UNSIGNED
    );

    IF v_server_major < 10 OR (v_server_major = 10 AND v_server_minor < 6) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MariaDB 10.6+ is required; upgrade the server before applying this update';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_TYPE = 'BASE TABLE'
       AND TABLE_NAME IN (
           'doctrine_migration_versions',
           'events',
           'messenger_messages',
           'users'
       );

    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected schema: required production tables are missing';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'events'
       AND COLUMN_NAME IN (
           'id',
           'website_token',
           'event_name',
           'url',
           'referrer',
           'generalized_user_agent',
           'screen_width',
           'session_id',
           'consent_state',
           'custom_data',
           'goal_event',
           'created_at'
       );

    IF v_count <> 12 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected events schema: stable columns do not match the reference dump';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'events'
       AND COLUMN_NAME IN ('daily_ip_hash', 'privacy_mode');

    IF v_count = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected events schema: neither legacy nor parity marker column exists';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `events`
     WHERE `custom_data` IS NOT NULL
       AND JSON_VALID(`custom_data`) = 0;

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid events.custom_data JSON must be repaired before this update';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `users`
     WHERE JSON_VALID(`roles`) = 0;

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid users.roles JSON must be repaired before this update';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `doctrine_migration_versions`
     WHERE `version` IN (
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330010000')
     );

    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected migration history: both production baseline versions are required';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `doctrine_migration_versions`
     WHERE `version` NOT IN (
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330010000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260529000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000250'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000500'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001500'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724002000')
     );

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected migration history: this parity script may be stale';
    END IF;
END//
DELIMITER ;

CALL `_aggregate_preflight_20260724`();
DROP PROCEDURE `_aggregate_preflight_20260724`;

-- Report the destructive cleanup scope before changing anything.
SELECT
    VERSION() AS `server_version`,
    DATABASE() AS `database_name`;

SELECT
    COUNT(*) AS `events_before_update`,
    SUM(CASE WHEN `consent_state` = 'granted' THEN 1 ELSE 0 END)
        AS `legacy_events_with_granted_consent`,
    SUM(CASE WHEN `consent_state` IS NULL OR `consent_state` <> 'granted' THEN 1 ELSE 0 END)
        AS `legacy_events_scheduled_for_privacy_deletion`
FROM `events`;

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260529000000
-- consent_state already exists in the reference production schema.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260529000000'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724000000
-- Backfill legacy rows as enhanced before switching the ingestion default.
-- -----------------------------------------------------------------------------
ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `visitor_id` VARCHAR(191) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `privacy_mode` VARCHAR(20) NOT NULL DEFAULT 'enhanced',
    ADD COLUMN IF NOT EXISTS `device_class` VARCHAR(20) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS `viewport_bucket` VARCHAR(20) NOT NULL DEFAULT 'unknown';

ALTER TABLE `events`
    MODIFY COLUMN `generalized_user_agent` VARCHAR(191) DEFAULT NULL,
    MODIFY COLUMN `custom_data` JSON DEFAULT NULL COMMENT '(DC2Type:json)',
    MODIFY COLUMN `consent_state` VARCHAR(20) DEFAULT NULL AFTER `created_at`;

-- The old production dump predates DBAL's MariaDB JSON type comments and
-- JSON_VALID constraints. Normalizing both mapped JSON fields makes Doctrine
-- introspect them exactly as the freshly migrated local schema does.
ALTER TABLE `users`
    MODIFY COLUMN `roles` JSON NOT NULL COMMENT '(DC2Type:json)';

ALTER TABLE `events`
    ADD INDEX IF NOT EXISTS `IDX_EVENTS_VISITOR_ID` (`visitor_id`),
    ADD INDEX IF NOT EXISTS `IDX_EVENTS_CONSENT_STATE` (`consent_state`),
    ADD INDEX IF NOT EXISTS `IDX_EVENTS_PRIVACY_SITE_CREATED`
        (`privacy_mode`, `website_token`, `created_at`);

CREATE TABLE IF NOT EXISTS `analytics_privacy_settings` (
    `id`                       INT      NOT NULL,
    `anonymous_min_cell_count` INT      NOT NULL DEFAULT 5,
    `updated_at`               DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop the irreversible legacy identifier only after the replacement schema
-- has been created successfully.
ALTER TABLE `events`
    DROP COLUMN IF EXISTS `daily_ip_hash`;

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000000'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724000250
-- Anonymous is the fail-safe default for all newly ingested events.
-- -----------------------------------------------------------------------------
ALTER TABLE `events`
    MODIFY COLUMN `privacy_mode` VARCHAR(20) NOT NULL DEFAULT 'anonymous';

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000250'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724000500
-- This deliberately purges legacy non-consented analytics and queued tracker
-- envelopes. With the reference dump, this DELETE removes all 1,645 events.
-- -----------------------------------------------------------------------------
DELETE FROM `events`
WHERE `privacy_mode` = 'enhanced'
  AND (`consent_state` IS NULL OR `consent_state` <> 'granted');

SET @aggregate_deleted_events = ROW_COUNT();

DELETE FROM `messenger_messages`
WHERE `body` LIKE '%TrackEventMessage%'
   OR `headers` LIKE '%TrackEventMessage%';

SET @aggregate_deleted_messages = ROW_COUNT();

SELECT
    @aggregate_deleted_events AS `privacy_events_deleted`,
    @aggregate_deleted_messages AS `queued_tracker_messages_deleted`;

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000500'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724001000
-- Hourly anonymous BI view with configurable minimum cell suppression.
-- -----------------------------------------------------------------------------
INSERT INTO `analytics_privacy_settings`
    (`id`, `anonymous_min_cell_count`, `updated_at`)
SELECT 1, 5, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM `analytics_privacy_settings`
    WHERE `id` = 1
);

DROP VIEW IF EXISTS `bi_anonymous_pageviews_v1`;
DROP VIEW IF EXISTS `bi_anonymous_events_v1`;

CREATE VIEW `bi_anonymous_events_v1` AS
SELECT
    `events`.`website_token`,
    `events`.`created_at` AS `event_hour`,
    `events`.`event_name`,
    `events`.`url` AS `page_path`,
    COALESCE(`events`.`referrer`, 'unknown') AS `referrer_channel`,
    `events`.`device_class`,
    `events`.`viewport_bucket`,
    COUNT(*) AS `event_count`
FROM `events`
CROSS JOIN `analytics_privacy_settings` AS `privacy`
WHERE `privacy`.`id` = 1
  AND `privacy`.`anonymous_min_cell_count` BETWEEN 2 AND 1000
  AND `events`.`privacy_mode` = 'anonymous'
  AND `events`.`created_at` < DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00')
GROUP BY
    `events`.`website_token`,
    `events`.`created_at`,
    `events`.`event_name`,
    `events`.`url`,
    COALESCE(`events`.`referrer`, 'unknown'),
    `events`.`device_class`,
    `events`.`viewport_bucket`,
    `privacy`.`anonymous_min_cell_count`
HAVING COUNT(*) >= `privacy`.`anonymous_min_cell_count`;

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001000'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724001500
-- Coarse geographic area plus its independent, higher BI threshold.
-- -----------------------------------------------------------------------------
ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `geo_area` VARCHAR(16) DEFAULT NULL;

ALTER TABLE `analytics_privacy_settings`
    ADD COLUMN IF NOT EXISTS `anonymous_geo_min_cell_count`
        INT NOT NULL DEFAULT 25;

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001500'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- DoctrineMigrations\Version20260724002000
-- Completed-day geography view with primary and complementary suppression.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS `bi_anonymous_geo_events_v1`;

CREATE VIEW `bi_anonymous_geo_events_v1` AS
WITH `geo_counts` AS (
    SELECT
        `events`.`website_token`,
        CAST(`events`.`created_at` AS DATE) AS `event_day`,
        `events`.`event_name`,
        CASE
            WHEN `events`.`geo_area` LIKE 'country:%' THEN 'country'
            ELSE 'continent'
        END AS `geo_level`,
        `events`.`geo_area`,
        COUNT(*) AS `event_count`,
        `privacy`.`anonymous_geo_min_cell_count` AS `minimum_cell_count`
    FROM `events`
    CROSS JOIN `analytics_privacy_settings` AS `privacy`
    WHERE `privacy`.`id` = 1
      AND `privacy`.`anonymous_geo_min_cell_count` BETWEEN 10 AND 1000
      AND `events`.`privacy_mode` = 'anonymous'
      AND (
          `events`.`geo_area` LIKE 'country:__'
          OR `events`.`geo_area` LIKE 'continent:__'
      )
      AND `events`.`created_at` < UTC_DATE()
    GROUP BY
        `events`.`website_token`,
        CAST(`events`.`created_at` AS DATE),
        `events`.`event_name`,
        CASE
            WHEN `events`.`geo_area` LIKE 'country:%' THEN 'country'
            ELSE 'continent'
        END,
        `events`.`geo_area`,
        `privacy`.`anonymous_geo_min_cell_count`
),
`ranked_geo_counts` AS (
    SELECT
        `website_token`,
        `event_day`,
        `event_name`,
        `geo_level`,
        `geo_area`,
        `event_count`,
        `minimum_cell_count`,
        SUM(
            CASE WHEN `event_count` < `minimum_cell_count` THEN 1 ELSE 0 END
        ) OVER (
            PARTITION BY `website_token`, `event_day`, `event_name`, `geo_level`
        ) AS `primary_suppressed_count`,
        ROW_NUMBER() OVER (
            PARTITION BY `website_token`, `event_day`, `event_name`, `geo_level`
            ORDER BY
                CASE WHEN `event_count` >= `minimum_cell_count` THEN 0 ELSE 1 END,
                `event_count`,
                `geo_area`
        ) AS `visible_rank`
    FROM `geo_counts`
),
`classified_geo_counts` AS (
    SELECT
        `website_token`,
        `event_day`,
        `event_name`,
        `geo_level`,
        `geo_area`,
        `event_count`,
        `minimum_cell_count`,
        CASE
            WHEN `event_count` < `minimum_cell_count` THEN 1
            WHEN `primary_suppressed_count` = 1 AND `visible_rank` = 1 THEN 1
            ELSE 0
        END AS `pool_in_other`
    FROM `ranked_geo_counts`
)
SELECT
    `website_token`,
    `event_day`,
    `event_name`,
    `geo_area`,
    `event_count`
FROM `classified_geo_counts`
WHERE `event_count` >= `minimum_cell_count`
  AND `pool_in_other` = 0
UNION ALL
SELECT
    `website_token`,
    `event_day`,
    `event_name`,
    CASE
        WHEN `geo_level` = 'country' THEN 'country:other'
        ELSE 'continent:other'
    END AS `geo_area`,
    SUM(`event_count`) AS `event_count`
FROM `classified_geo_counts`
WHERE `pool_in_other` = 1
GROUP BY
    `website_token`,
    `event_day`,
    `event_name`,
    `geo_level`,
    `minimum_cell_count`
HAVING SUM(`event_count`) >= `minimum_cell_count`;

INSERT IGNORE INTO `doctrine_migration_versions`
    (`version`, `executed_at`, `execution_time`)
VALUES
    (CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724002000'), CURRENT_TIMESTAMP, 0);

-- -----------------------------------------------------------------------------
-- Postflight: fail unless the resulting logical schema and cleanup invariants
-- match the current Doctrine model and all nine migrations are recorded.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_aggregate_postflight_20260724`;

DELIMITER //
CREATE PROCEDURE `_aggregate_postflight_20260724`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'events';

    IF v_count <> 17 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: events must contain exactly 17 columns';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'events'
       AND COLUMN_NAME = 'daily_ip_hash';

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: daily_ip_hash still exists';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND (
           (TABLE_NAME = 'events'
               AND COLUMN_NAME = 'custom_data'
               AND COLUMN_COMMENT = '(DC2Type:json)')
           OR
           (TABLE_NAME = 'users'
               AND COLUMN_NAME = 'roles'
               AND COLUMN_COMMENT = '(DC2Type:json)')
       );

    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: mapped JSON columns lack DBAL type metadata';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_TYPE = 'CHECK'
       AND (
           (TABLE_NAME = 'events' AND CONSTRAINT_NAME = 'custom_data')
           OR
           (TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'roles')
       );

    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: mapped JSON validity constraints are missing';
    END IF;

    SELECT COUNT(DISTINCT INDEX_NAME)
      INTO v_count
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'events'
       AND INDEX_NAME IN (
           'PRIMARY',
           'IDX_EVENTS_WEBSITE_TOKEN',
           'IDX_EVENTS_SESSION_ID',
           'IDX_EVENTS_VISITOR_ID',
           'IDX_EVENTS_CONSENT_STATE',
           'IDX_EVENTS_PRIVACY_SITE_CREATED'
       );

    IF v_count <> 6 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: one or more events indexes are missing';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'analytics_privacy_settings';

    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: analytics_privacy_settings must contain four columns';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.VIEWS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN (
           'bi_anonymous_events_v1',
           'bi_anonymous_geo_events_v1'
       );

    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: both anonymous BI views are required';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `analytics_privacy_settings`
     WHERE `id` = 1;

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: privacy settings row 1 is required';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `events`
     WHERE `privacy_mode` = 'enhanced'
       AND (`consent_state` IS NULL OR `consent_state` <> 'granted');

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: non-consented enhanced events remain';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `messenger_messages`
     WHERE `body` LIKE '%TrackEventMessage%'
        OR `headers` LIKE '%TrackEventMessage%';

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: queued tracker envelopes remain';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `doctrine_migration_versions`
     WHERE `version` IN (
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260330010000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260529000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000250'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724000500'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001000'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724001500'),
         CONCAT('DoctrineMigrations', CHAR(92), 'Version20260724002000')
     );

    IF v_count <> 9 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Postflight failed: all nine Doctrine migrations must be recorded';
    END IF;
END//
DELIMITER ;

CALL `_aggregate_postflight_20260724`();
DROP PROCEDURE `_aggregate_postflight_20260724`;

SELECT
    (SELECT COUNT(*) FROM `events`) AS `events_after_update`,
    (SELECT COUNT(*) FROM `users`) AS `users_preserved`,
    (SELECT COUNT(*) FROM `messenger_messages`) AS `queued_messages_after_update`,
    (SELECT COUNT(*) FROM `doctrine_migration_versions`) AS `migration_versions_recorded`,
    'schema parity update complete' AS `status`;

SET SESSION time_zone = @aggregate_old_time_zone;

-- Keep the application and workers stopped until the application code and
-- config/aggregate.yaml are deployed, caches are cleared, and smoke checks pass.
