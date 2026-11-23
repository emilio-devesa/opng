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

Rows are written as bounded multi-row `INSERT` statements (`OPNG_BACKUP_BATCH_ROWS
= 200`, `OPNG_BACKUP_BATCH_BYTES = 1 MiB`). This keeps the file smaller and the
restore faster without one statement per row, and the batch is flushed when
either limit is reached. If the dump fails mid-way, the helper rolls the
read-only snapshot back before rethrowing, so an interrupted backup never keeps
a transaction open.

Password-reset tokens (`reset_token`, `reset_token_expires`) are exported as
`NULL`. A restored database therefore never keeps a pending reset link alive;
users must request a new one. Password hashes remain in the dump, so treat the
file as a secret.

### Very large databases

The PHP helper is deliberately portable and has no `mysqldump` dependency. For
databases in the hundreds of MiB, a native dump is faster and is still
compatible with `tools/restore.php` if it omits `CREATE DATABASE`/`USE`:

```bash
backup_dir=$(mktemp -d /secure/backups/opng-XXXXXXXX) || exit 1
mysqldump --single-transaction --quick --skip-add-locks \
  --no-create-db --skip-comments opng > "$backup_dir/backup.sql"
```

The application dump remains the reference for a flat shared host where the
MySQL client is unavailable. Whichever tool you use, restore it into a
disposable database first and run `tools/recount_storage.php --check`.

## Create a backup

For the interim retention and verification procedure, see
[phase-0 containment](PHASE-0-CONTAINMENT.md). Never reuse an existing
destination: the CLI opens it for writing before connecting to the database.

### CLI

```bash
cd /path/to/opng
backup_dir=$(mktemp -d /secure/backups/opng-XXXXXXXX) || exit 1
php tools/backup.php "$backup_dir/backup.sql" &&
    (cd "$backup_dir" && sha256sum backup.sql > backup.sql.sha256)
```

To stream instead of writing a file:

```bash
backup_dir=$(mktemp -d /secure/backups/opng-XXXXXXXX) || exit 1
php tools/backup.php - > "$backup_dir/backup.sql"
```

The CLI returns non-zero on any failure and removes a partial destination file.
When redirecting stdout, a partial file can remain. Do not rotate an older
copy on the strength of a new file being non-empty.

### Admin panel

Sign in as an administrator, open **Admin → Database Actions → Download
backup** and re-enter your current password in the confirmation dialog. The
download uses the same portable dump and writes an audit entry. The password
reauthentication and CSRF checks are mandatory.

### Scheduling

```cron
# Daily backup at 02:15; retention is a separate operation.
15 2 * * * cd /home/USER/opng && d=$(mktemp -d /home/USER/backups/opng-XXXXXXXX) && php tools/backup.php "$d/backup.sql" && (cd "$d" && sha256sum backup.sql > backup.sql.sha256)
```

Recommended retention for a small installation: seven daily, four weekly and
three monthly copies, with at least one copy off the host. Define and test an
RPO/RTO appropriate to your data; a backup that has never been restored is not
a backup.

### Off-host targets

`tools/backup_external.php` runs one backup to the target selected by
`OPNG_BACKUP_TARGET`:

```bash
# Mounted directory, optionally encrypted at rest.
OPNG_BACKUP_TARGET=local \
OPNG_BACKUP_DIR=/mnt/offsite/opng \
OPNG_BACKUP_KEY="$(base64 < /run/secrets/opng-backup-key)" \
OPNG_BACKUP_RETENTION_DAYS=30 \
php tools/backup_external.php

# Any S3-compatible endpoint (AWS, MinIO, Backblaze, ...).
OPNG_BACKUP_TARGET=s3 \
OPNG_BACKUP_S3_ENDPOINT=https://s3.example.com \
OPNG_BACKUP_S3_BUCKET=opng-backups \
OPNG_BACKUP_S3_REGION=us-east-1 \
OPNG_BACKUP_S3_ACCESS_KEY=... \
OPNG_BACKUP_S3_SECRET_KEY=... \
OPNG_BACKUP_S3_PREFIX=opng \
php tools/backup_external.php
```

The command writes `opng-YYYYMMDD-HHMMSS-xxxxxxxx.sql` (or `.enc` for an
encrypted local backup) plus a `.sha256` sidecar, applies the retention window
and logs a structured `external_backup_completed` event. Local retention removes
only regular files matching that exact naming format and their regular checksum
sidecars; temporary files and symbolic links are skipped. Use a directory
dedicated to OPNG backups and writable only by the backup account. S3 uploads use
native SigV4 signing and no SDK; objects are uploaded as-is, so use a private
bucket with bucket-side encryption. The admin page (`admin/external_backup.php`)
shows the configuration and local files, and can run or verify a backup after
password + MFA reauthentication. The automatic verifier requires the original
`.sha256` sidecar, authenticates encrypted files, and checks the exact table
names and per-table row counts against the inventory at the end of the new
SQL dump. The inventory comes from the exported snapshot, not today's live
database. Old dumps without this inventory remain manually restorable; their
completeness cannot be certified by this check.

Configure `OPNG_VERIFY_DB_HOST`, `OPNG_VERIFY_DB_PORT` (default `3306`),
`OPNG_VERIFY_DB_USER` and `OPNG_VERIFY_DB_PASS` on the verifier caller (CLI or
web). Use a separate, disposable MySQL/MariaDB **instance**, with no production
data, mounts or credentials. Its verification account needs to create, import
and drop `opng_verify_*` schemas. No runtime connection is used as a fallback.
Enable this only for trusted backups: counts and an adjacent checksum do not
authenticate provenance against someone who can replace both files. For
encrypted local dumps, the recorded checksum covers decrypted SQL, not the
ciphertext; use the verifier rather than `sha256sum -c` on an `.enc` file.

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

The restored schema must include `schema_migrations`, `storage_counters`
(with `used_bytes` and `used_entries`), `users.used_bytes`, the MFA columns
(`users.mfa_secret`, `mfa_enabled`, `mfa_enrolled_at`) and `mfa_recovery_codes`,
`api_tokens`, `entries.parent_id/root_id/depth/author_label`,
`entries.visibility/expires_at/protection_hash/deleted_at`,
`idx_entries_author`, `idx_entries_visibility`, `idx_entries_deleted`, the
FULLTEXT index and all foreign keys. The dump includes the denormalized
counters, so a normal restore keeps the quota and dashboard values consistent.
If you import only table data or a partial dump, rebuild them:

```bash
php tools/recount_storage.php
php tools/recount_storage.php --check
```

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
php tools/recount_storage.php --check
php tools/doctor.php
```

The dump disables foreign-key checks during import and re-enables them. Do not
interrupt an import; restore into a fresh database instead if it fails. A
successful `tools/backup.php` dump already contains matching counters; the
check will report drift if the import was partial or edited by hand.

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
./deploy/apple-container/restore.sh /backups/opng-2026-11-05.sql
```

Back up **before** replacing the application container or restoring the
database. The `opng-db-data` volume holds the live database; never delete it
without a verified backup. See [APPLE-CONTAINER.md](APPLE-CONTAINER.md).
