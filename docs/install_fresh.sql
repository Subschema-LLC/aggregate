-- =============================================================================
-- Aggregate Analytics - Fresh Install Script (MySQL/MariaDB)
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
DROP TABLE IF EXISTS `messenger_messages`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `websites`;
DROP TABLE IF EXISTS `doctrine_migration_versions`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- events
-- Single-table analytics model:
--   - page views are events with event_name = 'view'
--   - custom events use event_name + custom_data JSON
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id`                     INT          NOT NULL AUTO_INCREMENT,
    `website_token`          VARCHAR(191) NOT NULL,
    `event_name`             VARCHAR(191) NOT NULL DEFAULT 'view',
    `url`                    LONGTEXT     NOT NULL,
    `referrer`               LONGTEXT     DEFAULT NULL,
    `daily_ip_hash`          VARCHAR(191) NOT NULL,
    `generalized_user_agent` VARCHAR(191) NOT NULL,
    `screen_width`           INT          DEFAULT NULL,
    `session_id`             VARCHAR(191) DEFAULT NULL,
    `consent_state`          VARCHAR(20)  NOT NULL DEFAULT 'unknown',
    `custom_data`            JSON         DEFAULT NULL,
    `goal_event`             VARCHAR(191) DEFAULT NULL,
    `created_at`             DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`),
    KEY `IDX_EVENTS_WEBSITE_TOKEN` (`website_token`),
    KEY `IDX_EVENTS_SESSION_ID` (`session_id`),
    KEY `IDX_EVENTS_CONSENT_STATE` (`consent_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    ('DoctrineMigrations\\Version20260330010000', NOW(), 0);

-- =============================================================================
-- Done.
-- Next steps:
--   1) configure .env/.env.local (DATABASE_URL, APP_SECRET, MESSENGER_TRANSPORT_DSN)
--   2) configure config/aggregate.yaml (daily_salt_secret, js_namespace, dashboard_enabled)
--   3) create admin user:
--        - web: /install
--        - CLI: php bin/console app:install
--   4) create website token(s): php bin/console app:create-website
-- =============================================================================
