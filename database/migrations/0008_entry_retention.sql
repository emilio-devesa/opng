-- 0008_entry_retention.sql
--
-- Soft deletion, expiry, visibility and password protection (10.3/10.5).
-- Existing entries remain public, non-expiring and outside the trash. The
-- indexes support the public listing, the trash views and the scheduled purge.
-- Every DDL step checks information_schema first, so an interrupted migration
-- can be re-run safely (ROB-18).

SET @column_deleted_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(
    @column_deleted_at = 0,
    'ALTER TABLE entries ADD COLUMN deleted_at DATETIME NULL AFTER topic',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_expires_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'expires_at'
);
SET @sql := IF(
    @column_expires_at = 0,
    'ALTER TABLE entries ADD COLUMN expires_at DATETIME NULL AFTER deleted_at',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_visibility := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'visibility'
);
SET @sql := IF(
    @column_visibility = 0,
    "ALTER TABLE entries ADD COLUMN visibility ENUM('public', 'unlisted', 'private') NOT NULL DEFAULT 'public' AFTER expires_at",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_protection := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'protection_hash'
);
SET @sql := IF(
    @column_protection = 0,
    'ALTER TABLE entries ADD COLUMN protection_hash VARCHAR(255) NULL AFTER visibility',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_visibility := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_visibility'
);
SET @sql := IF(
    @index_visibility = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_visibility (visibility, deleted_at, expires_at, date, id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_expires := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_expires'
);
SET @sql := IF(
    @index_expires = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_expires (expires_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_deleted := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_deleted'
);
SET @sql := IF(
    @index_deleted = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_deleted (deleted_at, date, id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Normalize legacy rows explicitly. ALTER TABLE already applies the default,
-- but an interrupted migration can leave NULLs behind.
UPDATE entries SET visibility = 'public' WHERE visibility IS NULL;
