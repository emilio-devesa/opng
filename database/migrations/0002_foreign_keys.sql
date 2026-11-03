-- 0002_foreign_keys.sql
--
-- Add referential integrity for entries.user_id and audit_log.actor_id (ROB-02).
-- Orphan rows are detached first; the dynamic statements make the migration
-- safe to run if the constraints already exist.

UPDATE entries
SET user_id = NULL
WHERE user_id IS NOT NULL
  AND user_id NOT IN (SELECT id FROM users);

UPDATE audit_log
SET actor_id = NULL
WHERE actor_id IS NOT NULL
  AND actor_id NOT IN (SELECT id FROM users);

SET @fk_entries := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'entries'
      AND CONSTRAINT_NAME = 'fk_entries_user'
);
SET @sql := IF(
    @fk_entries = 0,
    'ALTER TABLE entries ADD CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_audit := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND CONSTRAINT_NAME = 'fk_audit_actor'
);
SET @sql := IF(
    @fk_audit = 0,
    'ALTER TABLE audit_log ADD CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
