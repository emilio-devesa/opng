-- 0005_storage_counters.sql
--
-- Keep the per-account byte usage and the global entry count in O(1) counters
-- (PERF-10/PERF-17). `users.used_bytes` mirrors SUM(OCTET_LENGTH(text)) for the
-- account and `storage_counters.used_entries` mirrors COUNT(*) for the global
-- counter row. Both are maintained inside the same transaction as the write,
-- and `tools/recount_storage.php` can rebuild them from the authoritative data.
--
-- Every DDL step checks information_schema first, so an interrupted migration
-- can be re-run safely (ROB-18).

SET @column_user_bytes := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'used_bytes'
);
SET @sql := IF(
    @column_user_bytes = 0,
    'ALTER TABLE users ADD COLUMN used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_token_expires',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_used_entries := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'storage_counters'
      AND COLUMN_NAME = 'used_entries'
);
SET @sql := IF(
    @column_used_entries = 0,
    'ALTER TABLE storage_counters ADD COLUMN used_entries BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER used_bytes',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE users u
LEFT JOIN (
    SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes
    FROM entries
    WHERE user_id IS NOT NULL
    GROUP BY user_id
) AS entry_usage ON entry_usage.user_id = u.id
SET u.used_bytes = COALESCE(entry_usage.used_bytes, 0);

UPDATE storage_counters
SET used_entries = (SELECT COUNT(*) FROM entries)
WHERE counter_key = 'global';
