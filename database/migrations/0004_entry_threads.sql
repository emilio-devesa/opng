-- Open Pastebin NG v1.0: entry threads (F1).
--
-- parent_id links a reply to its direct parent, root_id identifies the whole
-- thread with one index, and depth bounds the tree. Existing rows become
-- roots; legacy "RE: " replies are not linked heuristically because the same
-- topic can be reused and the author may differ.
--
-- The self-referencing foreign key uses ON DELETE SET NULL so deleting one
-- message preserves its replies. root_id intentionally has no foreign key:
-- an orphaned subtree keeps pointing at the deleted root and stays findable.
--
-- Every DDL step checks information_schema first, so a migration interrupted
-- after one statement can be re-run safely (ROB-18).

SET @column_parent := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'parent_id'
);
SET @sql := IF(
    @column_parent = 0,
    'ALTER TABLE entries ADD COLUMN parent_id VARCHAR(255) NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_root := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'root_id'
);
SET @sql := IF(
    @column_root = 0,
    'ALTER TABLE entries ADD COLUMN root_id VARCHAR(255) NULL AFTER parent_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_depth := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND COLUMN_NAME = 'depth'
);
SET @sql := IF(
    @column_depth = 0,
    'ALTER TABLE entries ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER root_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_parent := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_parent'
);
SET @sql := IF(
    @index_parent = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_parent (parent_id, date, id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_root := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND INDEX_NAME = 'idx_entries_root'
);
SET @sql := IF(
    @index_root = 0,
    'ALTER TABLE entries ADD INDEX idx_entries_root (root_id, date, id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_parent := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND CONSTRAINT_NAME = 'fk_entries_parent'
);
SET @sql := IF(
    @fk_parent = 0,
    'ALTER TABLE entries ADD CONSTRAINT fk_entries_parent FOREIGN KEY (parent_id) REFERENCES entries (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE entries SET root_id = id, depth = 0 WHERE root_id IS NULL;
