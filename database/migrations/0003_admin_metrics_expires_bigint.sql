-- 0003_admin_metrics_expires_bigint.sql
--
-- admin_metrics_cache.expires_at stored a Unix timestamp in a 32-bit INT.
-- Use BIGINT so sessions/caches created by this schema never hit the 2038
-- limit (ROB-08). MODIFY is idempotent, which keeps manual re-runs safe.

ALTER TABLE admin_metrics_cache
    MODIFY expires_at BIGINT UNSIGNED NOT NULL;
