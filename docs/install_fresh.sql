-- =============================================================================
-- Aggregate Analytics - Fresh Install Script (MySQL 8.0+ / MariaDB 10.6+)
-- =============================================================================
-- Use this when you want a clean reset in an empty/new deployment.
-- It drops known app tables, recreates the baseline schema, and marks the
-- baseline migration as applied.
--
-- WARNING: Destructive. This removes existing app data in listed tables.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET FOREIGN_KEY_CHECKS = 0;

-- Drop current/legacy app tables if present
DROP VIEW IF EXISTS `bi_dim_event_name_v1`;
DROP VIEW IF EXISTS `bi_dim_goal_event_v1`;
DROP VIEW IF EXISTS `bi_dim_referrer_channel_v1`;
DROP VIEW IF EXISTS `bi_dim_device_class_v1`;
DROP VIEW IF EXISTS `bi_dim_viewport_bucket_v1`;
DROP VIEW IF EXISTS `bi_dim_geo_area_v1`;
DROP VIEW IF EXISTS `bi_glossary_values_v1`;
DROP VIEW IF EXISTS `bi_glossary_columns_v1`;
DROP VIEW IF EXISTS `analytics_archived_pageviews_v1`;
DROP VIEW IF EXISTS `analytics_archived_events_v1`;
DROP VIEW IF EXISTS `analytics_archived_goals_v1`;
DROP VIEW IF EXISTS `bi_anonymous_goals_v1`;
DROP VIEW IF EXISTS `bi_anonymous_geo_events_v1`;
DROP VIEW IF EXISTS `bi_anonymous_events_v1`;
DROP VIEW IF EXISTS `bi_anonymous_pageviews_v1`;
DROP TABLE IF EXISTS `messenger_messages`;
DROP TABLE IF EXISTS `analytics_archive_geo_events`;
DROP TABLE IF EXISTS `analytics_archive_goals`;
DROP TABLE IF EXISTS `analytics_archive_events`;
DROP TABLE IF EXISTS `analytics_maintenance_lock`;
DROP TABLE IF EXISTS `analytics_bigquery_sync`;
DROP TABLE IF EXISTS `processing_tasks`;
DROP TABLE IF EXISTS `audit_trail`;
DROP TABLE IF EXISTS `analytics_glossary`;
DROP TABLE IF EXISTS `analytics_privacy_settings`;
DROP TABLE IF EXISTS `anonymous_pageview_aggregates`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `websites`;
DROP TABLE IF EXISTS `doctrine_migration_versions`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- events
-- Unified event store:
--   - anonymous rows contain coarse dimensions and a UTC-hour timestamp
--   - enhanced rows may contain consented session/visitor detail
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id`                     INT          NOT NULL AUTO_INCREMENT,
    `website_token`          VARCHAR(191) NOT NULL,
    `event_name`             VARCHAR(191) NOT NULL DEFAULT 'view',
    `url`                    LONGTEXT     NOT NULL,
    `referrer`               LONGTEXT     DEFAULT NULL,
    `privacy_mode`           VARCHAR(20)  NOT NULL DEFAULT 'anonymous',
    `device_class`           VARCHAR(20)  NOT NULL DEFAULT 'unknown',
    `viewport_bucket`        VARCHAR(20)  NOT NULL DEFAULT 'unknown',
    `geo_area`               VARCHAR(16)  DEFAULT NULL,
    `generalized_user_agent` VARCHAR(191) DEFAULT NULL,
    `screen_width`           INT          DEFAULT NULL,
    `session_id`             VARCHAR(191) DEFAULT NULL,
    `visitor_id`             VARCHAR(191) DEFAULT NULL,
    `consent_state`          VARCHAR(20)  DEFAULT NULL,
    `custom_data`            JSON         DEFAULT NULL,
    `goal_event`             VARCHAR(191) DEFAULT NULL,
    `created_at`             DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`),
    KEY `IDX_EVENTS_WEBSITE_TOKEN` (`website_token`),
    KEY `IDX_EVENTS_SESSION_ID` (`session_id`),
    KEY `IDX_EVENTS_VISITOR_ID` (`visitor_id`),
    KEY `IDX_EVENTS_CONSENT_STATE` (`consent_state`),
    KEY `IDX_EVENTS_PRIVACY_SITE_CREATED` (`privacy_mode`, `website_token`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database-backed BI disclosure thresholds. The admin dashboard updates
-- singleton row 1 directly; the BI views read it at query time.
CREATE TABLE IF NOT EXISTS `analytics_privacy_settings` (
    `id`                           INT      NOT NULL,
    `anonymous_min_cell_count`     INT      NOT NULL DEFAULT 5,
    `anonymous_geo_min_cell_count` INT      NOT NULL DEFAULT 25,
    `updated_at`                   DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `analytics_privacy_settings`
    (`id`, `anonymous_min_cell_count`, `anonymous_geo_min_cell_count`, `updated_at`)
VALUES
    (1, 5, 25, NOW());

-- BI consumers should use this thresholded view instead of raw anonymous rows.
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

-- Approved anonymous goals are exposed only as completed-day, thresholded
-- counts. The raw events table remains restricted from routine BI consumers.
CREATE VIEW `bi_anonymous_goals_v1` AS
SELECT
    `events`.`website_token`,
    CAST(`events`.`created_at` AS DATE) AS `event_day`,
    `events`.`goal_event`,
    COUNT(*) AS `event_count`
FROM `events`
CROSS JOIN `analytics_privacy_settings` AS `privacy`
WHERE `privacy`.`id` = 1
  AND `privacy`.`anonymous_min_cell_count` BETWEEN 2 AND 1000
  AND `events`.`privacy_mode` = 'anonymous'
  AND `events`.`goal_event` IS NOT NULL
  AND `events`.`created_at` < UTC_DATE()
GROUP BY
    `events`.`website_token`,
    CAST(`events`.`created_at` AS DATE),
    `events`.`goal_event`,
    `privacy`.`anonymous_min_cell_count`
HAVING COUNT(*) >= `privacy`.`anonymous_min_cell_count`;

-- Geography is deliberately exposed through a separate completed-day view
-- with fewer dimensions, a higher threshold, and complementary suppression.
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
        SUM(CASE WHEN `event_count` < `minimum_cell_count` THEN 1 ELSE 0 END)
            OVER (PARTITION BY `website_token`, `event_day`, `event_name`, `geo_level`)
            AS `primary_suppressed_count`,
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

-- -----------------------------------------------------------------------------
-- Declared BI glossary metadata. Never populate this table from event values.
-- Views stay empty until: php bin/console app:analytics:glossary:sync
-- Grant routine BI readers the views only, never the backing table.
-- Binary collation preserves distinct case-sensitive declared codes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analytics_glossary` (
    `entry_type`          VARCHAR(16)  NOT NULL,
    `subject`             VARCHAR(64)  NOT NULL,
    `code`                VARCHAR(191) NOT NULL,
    `locale`              VARCHAR(35)  NOT NULL,
    `label`               VARCHAR(191) NOT NULL,
    `label_locale`        VARCHAR(35)  DEFAULT NULL,
    `group_label`         VARCHAR(191) DEFAULT NULL,
    `description`         LONGTEXT     DEFAULT NULL,
    `description_locale`  VARCHAR(35)  DEFAULT NULL,
    `sort_order`          INT          NOT NULL,
    `is_default_locale`   SMALLINT     NOT NULL,
    `source`              VARCHAR(16)  NOT NULL,
    `synced_at`           DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`entry_type`, `subject`, `code`, `locale`),
    KEY `IDX_GLOSSARY_DEFAULT` (`entry_type`, `subject`, `is_default_locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

DROP VIEW IF EXISTS bi_dim_event_name_v1;
CREATE VIEW bi_dim_event_name_v1 AS
SELECT
    code AS event_name,
    label AS event_name_label,
    group_label AS event_name_group,
    description AS event_name_description,
    sort_order AS event_name_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'event_name' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_dim_goal_event_v1;
CREATE VIEW bi_dim_goal_event_v1 AS
SELECT
    code AS goal_event,
    label AS goal_event_label,
    group_label AS goal_event_group,
    description AS goal_event_description,
    sort_order AS goal_event_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'goal_event' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_dim_referrer_channel_v1;
CREATE VIEW bi_dim_referrer_channel_v1 AS
SELECT
    code AS referrer_channel,
    label AS referrer_channel_label,
    group_label AS referrer_channel_group,
    description AS referrer_channel_description,
    sort_order AS referrer_channel_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'referrer_channel' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_dim_device_class_v1;
CREATE VIEW bi_dim_device_class_v1 AS
SELECT
    code AS device_class,
    label AS device_class_label,
    group_label AS device_class_group,
    description AS device_class_description,
    sort_order AS device_class_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'device_class' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_dim_viewport_bucket_v1;
CREATE VIEW bi_dim_viewport_bucket_v1 AS
SELECT
    code AS viewport_bucket,
    label AS viewport_bucket_label,
    group_label AS viewport_bucket_group,
    description AS viewport_bucket_description,
    sort_order AS viewport_bucket_sort
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'viewport_bucket' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_dim_geo_area_v1;
CREATE VIEW bi_dim_geo_area_v1 AS
SELECT
    code AS geo_area,
    label AS geo_area_label,
    group_label AS geo_area_group,
    description AS geo_area_description,
    sort_order AS geo_area_sort,
    CASE WHEN code LIKE 'country:%' THEN 'country' ELSE 'continent' END AS geo_level
FROM analytics_glossary
WHERE entry_type = 'value' AND subject = 'geo_area' AND is_default_locale = 1;

DROP VIEW IF EXISTS bi_glossary_values_v1;
CREATE VIEW bi_glossary_values_v1 AS
SELECT
    subject AS dimension,
    code,
    locale,
    label,
    label_locale,
    CASE WHEN label_locale = locale THEN 0 ELSE 1 END AS is_fallback,
    group_label,
    description,
    sort_order,
    is_default_locale
FROM analytics_glossary
WHERE entry_type = 'value';

DROP VIEW IF EXISTS bi_glossary_columns_v1;
CREATE VIEW bi_glossary_columns_v1 AS
SELECT
    subject AS object_name,
    code AS column_name,
    locale,
    label,
    label_locale,
    CASE WHEN label_locale = locale THEN 0 ELSE 1 END AS is_fallback,
    description,
    is_default_locale
FROM analytics_glossary
WHERE entry_type = 'column';

-- -----------------------------------------------------------------------------
-- users (dashboard login)
-- Optional if running API-only/headless mode with dashboard disabled.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(180) NOT NULL,
    `roles`      JSON         NOT NULL,
    `password`   VARCHAR(255) NOT NULL,
    `created_at` DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`),
    UNIQUE KEY `UNIQ_IDENTIFIER_USERNAME` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- messenger_messages (async queue mode only)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messenger_messages` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `body`         LONGTEXT     NOT NULL,
    `headers`      LONGTEXT     NOT NULL,
    `queue_name`   VARCHAR(190) NOT NULL,
    `created_at`   DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    `available_at` DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    `delivered_at` DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`),
    KEY `IDX_75EA56E0FB7336F0` (`queue_name`),
    KEY `IDX_75EA56E0E3BD61CE` (`available_at`),
    KEY `IDX_75EA56E016BA31DB` (`delivered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- doctrine_migration_versions
-- Marks migrations as already applied for this schema.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `doctrine_migration_versions` (
    `version`        VARCHAR(191) NOT NULL,
    `executed_at`    DATETIME     DEFAULT NULL,
    `execution_time` INT          DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `doctrine_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES
    ('DoctrineMigrations\\Version20260330000000', NOW(), 0),
    ('DoctrineMigrations\\Version20260330010000', NOW(), 0),
    ('DoctrineMigrations\\Version20260529000000', NOW(), 0),
    ('DoctrineMigrations\\Version20260724000000', NOW(), 0),
    ('DoctrineMigrations\\Version20260724000250', NOW(), 0),
    ('DoctrineMigrations\\Version20260724000500', NOW(), 0),
    ('DoctrineMigrations\\Version20260724001000', NOW(), 0),
    ('DoctrineMigrations\\Version20260724001500', NOW(), 0),
    ('DoctrineMigrations\\Version20260724002000', NOW(), 0),
    ('DoctrineMigrations\\Version20260828000000', NOW(), 0),
    ('DoctrineMigrations\\Version20260928000000', NOW(), 0);

-- =============================================================================
-- Done.
-- Next steps:
--   1) configure .env/.env.local (DATABASE_URL, APP_SECRET, MESSENGER_TRANSPORT_DSN)
--   2) apply newer migrations: php bin/console doctrine:migrations:migrate -n
--   3) configure config/aggregate.yaml and review config/goals.yaml
--      then clear production cache and run: php bin/console app:analytics:glossary:sync
--      (repeat sync after each migration run or glossary/goal configuration change)
--   4) configure BI disclosure thresholds in the admin dashboard if defaults are unsuitable
--   5) create admin user:
--        - web: /install
--        - CLI: php bin/console app:install
--   6) create website token(s): php bin/console app:create-website
-- =============================================================================
