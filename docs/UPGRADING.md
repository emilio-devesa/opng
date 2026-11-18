# Upgrading Open Pastebin NG

The supported upgrade path is: back up first, replace the application files
while preserving your configuration, apply pending migrations, run the doctor,
then smoke-test. Database migrations can be irreversible; the backup is the
rollback.

## 1. Read the release notes and take a backup

Read `CHANGELOG.md` and the GitHub release notes for the target version. Note
the minimum PHP/MySQL versions and any schema migration.

```bash
cd /path/to/opng
php tools/backup.php /secure/backups/opng-before-upgrade.sql
sha256sum /secure/backups/opng-before-upgrade.sql > /secure/backups/opng-before-upgrade.sql.sha256
```

Confirm the dump is non-empty and copy it off the host. The dump contains
password hashes and reset tokens; protect it like the database.

## 2. Put the site in maintenance mode (recommended)

Either stop the web server, use the hosting panel's maintenance mode, or add a
temporary `src/maintenance.php`/server rewrite that returns `503`. Do not leave
an administrator mid-write while files and schema are half-updated.

## 3. Replace the files

Preserve these items from the old installation:

```text
src/config.php                  # your credentials and environment overrides
src/assets/vendor/              # only if you replaced the bundled runtime
any custom branding or extra assets you added
```

Then extract the new artifact and copy everything else:

```bash
rsync -a --delete \
  --exclude 'src/config.php' \
  --exclude 'src/assets/vendor/' \
  /extracted/opng-<version>/ /path/to/opng/
```

Do not copy `src/config.example.php` over `src/config.php`. Keep `tools/` and
`database/` outside the HTTP document root.

## 4. Apply schema migrations

Use an account with DDL privileges (the migration account), then revoke it:

```bash
cd /path/to/opng
OPNG_INSTALL_DB_USER=opng_migrator \
OPNG_INSTALL_DB_PASS='replace-migration-secret' \
php tools/install.php
```

`tools/install.php` is the full installer. It runs the idempotent bootstrap
and every pending file in `database/migrations/` in filename order, recording
each one in `schema_migrations`. A lighter option, when the database already
matches the v1.0 bootstrap, is:

```bash
php tools/migrate.php
```

`tools/migrate.php` only applies pending migrations and uses the runtime
credentials in `src/config.php`, so those credentials need DDL for that run.
Prefer `tools/install.php` with the dedicated migration account.

Both commands are safe to rerun. A failed migration stops the command and is
not recorded; fix the reported problem and resume.

### From 0.9.x to 1.0

- Migration `0001_baseline` records the existing schema.
- `0002_foreign_keys` adds `entries.user_id`/`audit_log.actor_id` foreign keys
  after cleaning orphans; review the installer output.
- `0003_admin_metrics_expires_bigint` widens the metrics timestamp.
- `0004_entry_threads` adds `entries.parent_id`, `root_id` and `depth`,
  backfills existing rows as thread roots (`root_id = id`, `depth = 0`) and
  adds the thread indexes plus `ON DELETE SET NULL` parent foreign key.

Legacy pastes whose topic starts with `RE: ` are **not** linked automatically.
Review candidates and link only explicit pairs:

```bash
php tools/link_legacy_replies.php --dry-run
php tools/link_legacy_replies.php --apply --reply=REPLY_ID --parent=PARENT_ID
```

Each applied pair is written to the audit log.

### From 1.1.x to 1.2

- `0005_storage_counters` adds `users.used_bytes` and
  `storage_counters.used_entries`, then backfills both from `entries`. After the
  upgrade, per-account quota checks and the dashboard totals are O(1) instead of
  scanning the account's pastes or the whole table.
- `0006_entry_author_label` adds `entries.author_label` and
  `idx_entries_author (author_label, id)`, backfilling existing rows from
  `users.username` (`Guest` for anonymous pastes). Author sorting no longer
  joins `users`.
- `0007_admin_mfa` adds `users.mfa_secret`, `mfa_enabled` and `mfa_enrolled_at`,
  plus the `mfa_recovery_codes` table. Set `OPNG_MFA_KEY` to a base64-encoded
  32-byte secret **before** the first administrator enrolls. The key is not in
  the database dump; keep it in your secret store.
- `0008_entry_retention` adds `entries.visibility`, `expires_at`,
  `protection_hash` and `deleted_at` with their indexes. Existing rows remain
  public and never expire.
- `0009_api_tokens` adds the `api_tokens` table for hashed personal tokens.
- All six migrations are idempotent and safe to re-run if a previous attempt was
  interrupted.

Configure the Phase 13 services before enabling them:

- Mail: `OPNG_MAIL_TRANSPORT` (`smtp`, `sendmail` or `log`) and the matching
  `OPNG_MAIL_*` values. `php tools/doctor.php --strict` must accept them before
  the login page offers account recovery.
- MFA: `OPNG_MFA_KEY` as described above. The lowest-id administrator must
  enroll on the next login; other administrators may enroll from the security
  page.
- Identifiers: set `OPNG_HASH_KEY` to 32+ raw bytes or base64 (`openssl rand
  -base64 32`). Audit targets, client IPs and rate-limit keys switch to
  HMAC-SHA-256; existing unkeyed rows keep working, and the doctor warns while
  the key is missing.
- Bootstrap: entry points now load `src/bootstrap.php` instead of repeating the
  foundation requires. The redundant `src/database.php` wrapper was removed;
  custom integrations should require `src/includes/db.php` and call
  `database_connect()`.
- Pagination: cursors produced before this release are rejected when they lack
  the current sort/date fields. Users simply restart pagination; no data
  changes.
- Trash: `OPNG_TRASH_RETENTION_DAYS` (default 7) and a cron entry for
  `php tools/trash_purge.php`.
- External backups: `OPNG_BACKUP_TARGET`, `OPNG_BACKUP_DIR` or
  `OPNG_BACKUP_S3_*`, `OPNG_BACKUP_RETENTION_DAYS` and optionally
  `OPNG_BACKUP_KEY` for local AES-256-GCM encryption.

After any manual import, bulk insert or restore that bypassed the application,
rebuild and verify the counters:

```bash
php tools/recount_storage.php
php tools/recount_storage.php --check
```

`--check` changes nothing and exits non-zero when a counter differs from the
authoritative data. Schedule `tools/metrics_refresh.php` if you want the admin
dashboard cache warmed before its five-minute TTL; the dashboard still computes
the values on demand when the cache is empty.

## 5. Run the doctor and smoke test

```bash
OPNG_PUBLIC_URL=https://paste.example.com php tools/doctor.php
```

Fix every failure. Then verify:

1. Sign in as an administrator; enroll MFA if this is the lowest-id
   administrator.
2. Load an existing paste and its **History** tab.
3. Create a paste, reply, edit and delete a reply; restore it from the trash.
4. Mark a paste unlisted/private, set an expiry and a password, and confirm the
   unlock flow.
5. Download an admin backup, run `php tools/backup_external.php`, and verify a
   backup into a disposable schema.
6. Create an API token and call `GET /api/v1/entries` with it.
7. `curl -fsS https://paste.example.com/healthz.php` returns `200` and
   `{"status":"ok"}`. The schema version is only returned when the request
   sends the optional `OPNG_HEALTHZ_DETAIL_TOKEN` as a bearer token; the
   container healthcheck intentionally does not need it.
8. Check the PHP error log, the structured application log and the admin audit
   viewer.

## 6. Rollback

If the new version is not viable:

1. Stop traffic.
2. Restore the previous application files (keep the same `src/config.php`).
3. Restore the SQL backup into the existing database or a fresh one:

   ```bash
   php tools/restore.php --file=/secure/backups/opng-before-upgrade.sql \
     --checksum=/secure/backups/opng-before-upgrade.sql.sha256
   ```

4. Run `php tools/doctor.php` and the smoke test.

Restoring replaces the tables in the dump. Test the restore in a disposable
database before relying on it. See [BACKUP-RESTORE.md](BACKUP-RESTORE.md).

## Database engine and hosting notes

- The installer normalizes legacy `Users`/`Entries`/`Rate_limits` names to
  lowercase. It aborts if both case variants exist; resolve the ambiguity
  manually instead of deleting tables.
- New installations require InnoDB and `utf8mb4_unicode_ci`; legacy MyISAM
  `entries` tables are converted. Take a backup first.
- The runtime account should not keep DDL privileges after the migration.
- If your host cannot run PHP CLI, import `database/schema.sql` through
  phpMyAdmin only for a fresh install, not for an upgrade. For upgrades, ask
  the host to run the migration command or move to a host that provides CLI.

## Apple Container

For upgrades in Apple Container see
[APPLE-CONTAINER.md](APPLE-CONTAINER.md). Keep the `opng-db-data` volume and
back up before recreating the application container.
