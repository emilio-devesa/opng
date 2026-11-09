# Backup and restore

Open Pastebin NG backups are portable SQL dumps. They contain every base
table, including password hashes, reset tokens and the audit log. Treat them as
secrets.

## What the dump contains

- `DROP TABLE IF EXISTS` and `CREATE TABLE` for every table.
- Every row, including hashed passwords and thread relations.
- **No** `CREATE DATABASE` and **no** `USE` statement.

Because the target database is not embedded, the same dump restores into a
database with any name. The importer chooses it:

```bash
mysql opng_restore < backup.sql
```

The admin panel, `tools/backup.php` and containers all use the same portable
format. The dump is taken from a consistent read-only snapshot and honours
`OPNG_BACKUP_MAX_SECONDS` (default 300) and `OPNG_BACKUP_MAX_BYTES`
(default 512 MiB); it aborts rather than write a truncated file.

## Create a backup

### CLI

```bash
cd /path/to/opng
php tools/backup.php /secure/backups/opng-$(date +%F).sql
sha256sum /secure/backups/opng-$(date +%F).sql > /secure/backups/opng-$(date +%F).sql.sha256
```

To stream instead of writing a file:

```bash
php tools/backup.php - > /secure/backups/opng.sql
```

The CLI returns non-zero on any failure and removes a partial destination file.
Check that the result is non-empty before rotating older copies.

### Admin panel

Sign in as an administrator, open **Admin → Database Actions → Download
backup** and re-enter your current password in the confirmation dialog. The
download uses the same portable dump and writes an audit entry. The password
reauthentication and CSRF checks are mandatory.

### Scheduling

```cron
# Daily backup at 02:15, keep seven days.
15 2 * * * cd /home/USER/opng && php tools/backup.php /home/USER/backups/opng-$(date +\%F).sql && sha256sum /home/USER/backups/opng-$(date +\%F).sql > /home/USER/backups/opng-$(date +\%F).sql.sha256
```

Recommended retention for a small installation: seven daily, four weekly and
three monthly copies, with at least one copy off the host. Define and test an
RPO/RTO appropriate to your data; a backup that has never been restored is not
a backup.

## Verify a backup

```bash
sha256sum -c opng-2026-11-05.sql.sha256
```

Then restore into a disposable database, never directly into production first:

```bash
mysql -e 'CREATE DATABASE opng_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql opng_restore < opng-2026-11-05.sql
mysql opng_restore -e 'SELECT COUNT(*) FROM entries; SELECT MAX(version) FROM schema_migrations;'
```

The restored schema must include `schema_migrations`, `storage_counters`,
`entries.parent_id/root_id/depth`, the FULLTEXT index and all foreign keys.
For an automated end-to-end check, run:

```bash
OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 \
OPNG_TEST_DB_HOST=127.0.0.1 OPNG_TEST_DB_PORT=3306 \
OPNG_TEST_DB_USER=root OPNG_TEST_DB_PASS='...' \
php tests/backup_restore_test.php
```

The test creates and drops throwaway databases; never point it at production.

## Restore

### CLI

```bash
cd /path/to/opng
php tools/restore.php --file=/secure/backups/opng-2026-11-05.sql \
  --checksum=/secure/backups/opng-2026-11-05.sql.sha256
```

The tool:

1. Verifies the optional SHA-256 checksum.
2. Refuses dumps containing `CREATE DATABASE` or `USE` unless
   `--allow-database-statements` is explicitly passed.
3. Prompts you to type `RESTORE` unless `--force` is given.
4. Uses the system `mysql`/`mariadb` client with a temporary `0600` options
   file; the password is never exposed in the process list.
5. Restores into the database configured in `src/config.php`
   (`OPNG_DB_NAME`).
6. Ends by asking you to run `tools/migrate.php`, `tools/doctor.php` and a
   smoke test.

Set `OPNG_MYSQL_CLIENT=/path/to/mysql` when the client is not in `PATH`
(common on macOS with MAMP or Homebrew).

### Manual

```bash
mysql opng < /secure/backups/opng-2026-11-05.sql
php tools/doctor.php
```

The dump disables foreign-key checks during import and re-enables them. Do not
interrupt an import; restore into a fresh database instead if it fails.

## Restore into a different installation

1. Create the target database.
2. Point a copy of the application at it (`OPNG_DB_NAME`/`config.php`) with the
   correct runtime credentials.
3. Restore the dump.
4. Run `php tools/migrate.php` to apply any migration newer than the dump.
5. Run the doctor and smoke test.

Do not reuse the source installation's `src/config.php` when testing a restore
in parallel; credentials and `OPNG_PUBLIC_URL` are environment-specific.

## Containers

In Apple Container, mount the persistent `opng-backups` volume at `/backups`:

```bash
container exec opng-app php tools/backup.php /backups/opng-$(date +%F).sql
container exec opng-app php tools/restore.php --file=/backups/opng-2026-11-05.sql
```

Back up **before** replacing the application container or restoring the
database. The `opng-db-data` volume holds the live database; never delete it
without a verified backup. See [APPLE-CONTAINER.md](APPLE-CONTAINER.md).
