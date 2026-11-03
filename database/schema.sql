-- Open Pastebin NG v1.0 canonical schema.
--
-- This file documents the expected end state. Fresh installations use
-- src/install.php (which also applies database/migrations) and upgrades use
-- `php tools/migrate.php`.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(50) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    reset_token VARCHAR(255) NULL,
    reset_token_expires DATETIME NULL,
    KEY idx_users_created (created_at, id),
    KEY idx_users_role (role, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entries (
    id VARCHAR(255) PRIMARY KEY,
    user_id INT NULL,
    date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    language VARCHAR(20) NOT NULL DEFAULT '',
    text MEDIUMTEXT,
    topic VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_entries_date (date),
    KEY idx_entries_cursor (date, id),
    KEY idx_entries_user (user_id),
    KEY idx_entries_topic (topic, id),
    KEY idx_entries_language (language, id),
    FULLTEXT KEY ft_entries_topic (topic),
    CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    action VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (action, key_hash),
    KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_metrics_cache (
    cache_key VARCHAR(32) NOT NULL PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    KEY idx_admin_metrics_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_counters (
    counter_key VARCHAR(32) NOT NULL PRIMARY KEY,
    used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id INT NULL,
    action VARCHAR(32) NOT NULL,
    target VARCHAR(255) NOT NULL DEFAULT '',
    ip_hash CHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at, id),
    KEY idx_audit_actor (actor_id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO storage_counters (counter_key, used_bytes)
SELECT 'global', COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries;
