-- 0009_api_tokens.sql
--
-- Read-only API tokens (10.4). Only a SHA-256 hash of the bearer token is
-- stored; the clear token is shown once when it is created. Revocation keeps
-- the row for auditing instead of deleting it.

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(80) NOT NULL DEFAULT '',
    token_hash CHAR(64) NOT NULL,
    scopes VARCHAR(64) NOT NULL DEFAULT 'read',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_api_token_hash (token_hash),
    KEY idx_api_tokens_user (user_id, created_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
