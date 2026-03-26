-- =============================================================================
-- Aggregate Analytics - MySQL Install Script
-- =============================================================================
-- Run this SQL against a blank database to set up the schema from scratch.
-- This is equivalent to running all Doctrine migrations in sequence.
--
-- Prerequisites (run as MySQL root or via Plesk Databases panel):
--   CREATE DATABASE your_db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   CREATE USER 'your_db_user'@'localhost' IDENTIFIED BY 'your_password';
--   GRANT ALL PRIVILEGES ON your_db_name.* TO 'your_db_user'@'localhost';
--   FLUSH PRIVILEGES;
--
-- Then run:
--   mysql -u your_db_user -p your_db_name < docs/install.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- websites
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `websites` (
    `id`           INT          NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(191) NOT NULL,
    `domain`       VARCHAR(191) NOT NULL,
    `public_token` VARCHAR(191) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `UNIQ_WEBSITES_PUBLIC_TOKEN` (`public_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- events
-- Single table for all analytics data. A "view" (page view / screen view) is
-- stored as event_name = 'view'. Custom events use the name sent by the client.
--
-- Useful BI queries:
--   Page views:    SELECT * FROM events WHERE event_name = 'view'
--   Custom events: SELECT * FROM events WHERE event_name != 'view'
--   Sessions:      SELECT COUNT(DISTINCT session_id) FROM events WHERE event_name = 'view'
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id`                     INT          NOT NULL AUTO_INCREMENT,
    `website_id`             INT          NOT NULL,
    `event_name`             VARCHAR(191) NOT NULL DEFAULT 'view',
    `url`                    LONGTEXT     NOT NULL,
    `referrer`               LONGTEXT     DEFAULT NULL,
    `daily_ip_hash`          VARCHAR(191) NOT NULL,
    `generalized_user_agent` VARCHAR(191) NOT NULL,
    `screen_width`           INT          DEFAULT NULL,
    `session_id`             VARCHAR(191) DEFAULT NULL,
    `custom_data`            JSON         DEFAULT NULL,
    `created_at`             DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (`id`),
    KEY `IDX_EVENTS_WEBSITE_ID`  (`website_id`),
    KEY `IDX_EVENTS_SESSION_ID`  (`session_id`),
    CONSTRAINT `FK_EVENTS_WEBSITE`
        FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- users  (dashboard login)
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
-- messenger_messages  (async queue - auto-created but included for completeness)
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
-- Tells Symfony that all migrations have already been applied via this script.
-- Without this, running `php bin/console doctrine:migrations:migrate` would
-- try to re-run all migrations and fail because the tables already exist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `doctrine_migration_versions` (
    `version`        VARCHAR(191) NOT NULL,
    `executed_at`    DATETIME     DEFAULT NULL,
    `execution_time` INT          DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `doctrine_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES
    ('DoctrineMigrations\\Version20251008220000', NOW(), 0),
    ('DoctrineMigrations\\Version20260318000000', NOW(), 0),
    ('DoctrineMigrations\\Version20260318100000', NOW(), 0),
    ('DoctrineMigrations\\Version20260325000000', NOW(), 0);

SET foreign_key_checks = 1;

-- =============================================================================
-- Done.
-- Next step: run the setup script or configure config/aggregate.yaml and
-- create your first website:
--   php bin/console app:create-website
-- =============================================================================
