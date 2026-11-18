-- 0006_entry_author_label.sql
--
-- Store the displayed author on the entry itself (PERF-12). The global listing
-- can then ORDER BY (author_label, id) through a real index instead of sorting
-- the COALESCE(users.username, 'Guest') expression with a filesort. The label
-- is copied from users.username when the entry is created; anonymous and
-- preserved entries use 'Guest'. There is no username-change feature in v1.2,
-- so the denormalized value only needs the insert, migration backfill and the
-- account-preserve path.
--
-- Every DDL step checks information_schema first, so an interrupted migration
-- can be re-run safely (ROB-18).

SET @column_author := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'author_label'
);
SET @sql := IF(
    @column_author = 0,
    "ALTER TABLE entries ADD COLUMN author_label VARCHAR(50) NOT NULL DEFAULT 'Guest' AFTER user_id",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_author := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_author'
);
SET @sql := IF(
    @index_author = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_author (author_label, id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE entries e
LEFT JOIN users u ON u.id = e.user_id
SET e.author_label = COALESCE(u.username, 'Guest');
