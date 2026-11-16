-- 0007_admin_mfa.sql
--
-- Administrator MFA (10.2). The TOTP secret is stored encrypted with
-- AES-256-GCM by src/includes/mfa.php; recovery codes are stored as HMAC
-- hashes. Every DDL step checks information_schema first, so an interrupted
-- migration can be re-run safely (ROB-18).

SET @column_mfa_secret := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'mfa_secret'
);
SET @sql := IF(
    @column_mfa_secret = 0,
    'ALTER TABLE users ADD COLUMN mfa_secret VARBINARY(255) NULL AFTER used_bytes',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_mfa_enabled := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'mfa_enabled'
);
SET @sql := IF(
    @column_mfa_enabled = 0,
    'ALTER TABLE users ADD COLUMN mfa_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mfa_secret',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_mfa_enrolled := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'mfa_enrolled_at'
);
SET @sql := IF(
    @column_mfa_enrolled = 0,
    'ALTER TABLE users ADD COLUMN mfa_enrolled_at DATETIME NULL AFTER mfa_enabled',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mfa_recovery_user (user_id, used_at),
    CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
