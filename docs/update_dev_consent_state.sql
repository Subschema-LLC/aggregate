-- =============================================================================
-- Aggregate Analytics - Dev DB update for consent_state (MySQL/MariaDB)
-- =============================================================================
-- Applies the schema/data changes needed by:
--   DoctrineMigrations\Version20260330010000
--
-- This variant intentionally does NOT query information_schema, because some
-- hosted DB users can alter app tables but cannot read metadata schemas.
--
-- Usage:
--   mysql -u your_db_user -p your_db_name < docs/update_dev_consent_state.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Ensure migration tracking table exists.
CREATE TABLE IF NOT EXISTS `doctrine_migration_versions` (
    `version`        VARCHAR(191) NOT NULL,
    `executed_at`    DATETIME     DEFAULT NULL,
    `execution_time` INT          DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Check whether this migration is already marked as applied.
SELECT COUNT(*) INTO @migration_applied
FROM `doctrine_migration_versions`
WHERE `version` = 'DoctrineMigrations\\Version20260330010000';

-- -----------------------------------------------------------------------------
-- 1) Schema updates (only when migration is not yet applied)
-- -----------------------------------------------------------------------------
SET @add_col_sql := IF(
  @migration_applied = 0,
  'ALTER TABLE `events` ADD COLUMN `consent_state` VARCHAR(20) NOT NULL DEFAULT ''unknown'' AFTER `session_id`',
  'SELECT ''Migration already marked applied; skipping schema changes'' AS status'
);
PREPARE stmt_add_col FROM @add_col_sql;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

SET @normalize_col_sql := IF(
  @migration_applied = 0,
  'ALTER TABLE `events` MODIFY COLUMN `consent_state` VARCHAR(20) NOT NULL DEFAULT ''unknown''',
  'SELECT ''Skipping column normalization'' AS status'
);
PREPARE stmt_norm_col FROM @normalize_col_sql;
EXECUTE stmt_norm_col;
DEALLOCATE PREPARE stmt_norm_col;

SET @add_idx_sql := IF(
  @migration_applied = 0,
  'ALTER TABLE `events` ADD INDEX `IDX_EVENTS_CONSENT_STATE` (`consent_state`)',
  'SELECT ''Skipping index creation'' AS status'
);
PREPARE stmt_add_idx FROM @add_idx_sql;
EXECUTE stmt_add_idx;
DEALLOCATE PREPARE stmt_add_idx;

-- -----------------------------------------------------------------------------
-- 2) Data updates (only when migration is not yet applied)
-- -----------------------------------------------------------------------------
SET @backfill_granted_sql := IF(
  @migration_applied = 0,
  'UPDATE `events`
   SET `consent_state` = ''granted''
   WHERE `session_id` IS NOT NULL
     AND `session_id` <> ''''
     AND (`consent_state` = ''unknown'' OR `consent_state` IS NULL)',
  'SELECT ''Skipping backfill'' AS status'
);
PREPARE stmt_backfill_granted FROM @backfill_granted_sql;
EXECUTE stmt_backfill_granted;
DEALLOCATE PREPARE stmt_backfill_granted;

SET @normalize_values_sql := IF(
  @migration_applied = 0,
  'UPDATE `events`
   SET `consent_state` = ''unknown''
   WHERE `consent_state` IS NULL
      OR `consent_state` NOT IN (''granted'', ''denied'', ''unknown'')',
  'SELECT ''Skipping value normalization'' AS status'
);
PREPARE stmt_norm_values FROM @normalize_values_sql;
EXECUTE stmt_norm_values;
DEALLOCATE PREPARE stmt_norm_values;

-- -----------------------------------------------------------------------------
-- 3) Mark migration as applied
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `doctrine_migration_versions` (`version`, `executed_at`, `execution_time`) VALUES
    ('DoctrineMigrations\\Version20260330010000', NOW(), 0);

-- =============================================================================
-- Done.
-- =============================================================================
