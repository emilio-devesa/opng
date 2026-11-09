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

## 5. Run the doctor and smoke test

```bash
OPNG_PUBLIC_URL=https://paste.example.com php tools/doctor.php
```

Fix every failure. Then verify:

1. Sign in as an administrator.
2. Load an existing paste and its **History** tab.
3. Create a paste, reply, edit and delete a reply.
4. Download an admin backup.
5. `curl -fsS https://paste.example.com/healthz.php` returns `200` and the
   expected schema version.
6. Check the PHP error log and the admin audit log.

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
