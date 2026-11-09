# Classic installation (shared hosting)

This guide installs the `opng-<version>-classic` artifact on a shared host with
PHP and MySQL. It does not require Composer, Node, Docker, Apple Container or
root access. If your host provides SSH, use the CLI path; the phpMyAdmin path
is a documented fallback.

## Requirements

- PHP 8.2 or newer with `mysqli`, `mbstring` and `gd`.
- MySQL 5.7+ or MariaDB 10.4+.
- A web server whose document root can point at the extracted `src/`
  directory (Apache 2.4 with `mod_rewrite`/`mod_headers`, or Nginx).
- HTTPS in production. A TLS-terminating proxy is fine; set
  `OPNG_FORCE_HTTPS=1` only when PHP cannot see the original HTTPS request.

## 1. Verify and upload the artifact

Download both files and verify the checksum before uploading:

```bash
sha256sum -c SHA256SUMS          # Linux
shasum -a 256 -c SHA256SUMS      # macOS
```

Extract `opng-<version>-classic.zip` (or `.tar.gz`) outside the web root, for
example to `~/opng`. Then point the hosting document root at:

```text
~/opng/src
```

Do **not** point it at `~/opng`: `tools/`, `database/`, `docs/` and the
configuration template must not be reachable over HTTP. Keep `tools/` and
`database/` outside the document root even though `src/.htaccess` and the
Nginx example also deny them.

The artifact never contains `src/config.php`; it always contains
`src/config.example.php`.

## 2. Create the database and accounts

Create a database and two accounts. The runtime account only needs DML
privileges; the migration account is used once from the CLI and can be revoked
afterwards.

```sql
CREATE DATABASE opng CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'opng_runtime'@'localhost' IDENTIFIED BY 'replace-runtime-secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON opng.* TO 'opng_runtime'@'localhost';

CREATE USER 'opng_migrator'@'localhost' IDENTIFIED BY 'replace-migration-secret';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, DROP ON opng.* TO 'opng_migrator'@'localhost';
```

Some panels create the database and user for you and do not allow `CREATE
USER`; use the panel's equivalent and keep the two privilege sets separate.

## 3. Configure the application

```bash
cd ~/opng
cp src/config.example.php src/config.php
chmod 640 src/config.php
```

Edit `src/config.php` (or set the `OPNG_DB_*` environment variables in the
hosting panel):

```php
$mysql_server   = 'localhost';
$mysql_username = 'opng_runtime';
$mysql_password = 'replace-runtime-secret';
$mysql_dbname   = 'opng';
```

Use a non-empty password. Never reuse the development/MAMP example values.
Optional settings are documented at the top of `src/config.example.php`:
`OPNG_FORCE_HTTPS`, `OPNG_PUBLIC_URL`, `OPNG_TRUSTED_PROXIES`,
`OPNG_STORAGE_QUOTA_BYTES`, `OPNG_BACKUP_MAX_SECONDS` and
`OPNG_BACKUP_MAX_BYTES`.

## 4. Install the schema (CLI, recommended)

Run the installer with the migration account. It creates the lowercase tables,
indexes and versioned migrations, and it is safe to rerun:

```bash
cd ~/opng
OPNG_INSTALL_DB_USER=opng_migrator \
OPNG_INSTALL_DB_PASS='replace-migration-secret' \
php tools/install.php
```

The target database must already exist; the installer deliberately does not
create or drop databases. It prints the applied migration versions and ends
with `schema version 0004_entry_threads`. If the host runs MySQL 8.4 or newer
and the legacy table names differ only by case, resolve them before running
the installer; it aborts rather than deleting ambiguous data.

Then revoke or drop the migration account:

```sql
DROP USER 'opng_migrator'@'localhost';
```

## 5. Create the first administrator

```bash
php tools/create_admin.php --username=admin --email=admin@example.com
```

The password is prompted twice without echoing. You can instead set
`OPNG_ADMIN_PASSWORD` for unattended provisioning:

```bash
OPNG_ADMIN_PASSWORD='a long unique password' \
php tools/create_admin.php --username=admin --email=admin@example.com --if-empty
```

`--if-empty` exits successfully when an administrator already exists, which
makes it safe in provisioning scripts. The command refuses to create a second
administrator unless `--force` is explicit. Remove or protect
`tools/create_admin.php` after setup.

## 6. Run the doctor

```bash
OPNG_PUBLIC_URL=https://paste.example.com php tools/doctor.php
```

It checks the configuration file, the runtime password, database connection,
schema version, `display_errors`, development reset mode, leftover migration
credentials and HTTPS. Fix every `FAIL` and review the `WARN` entries before
opening the site.

## 7. Web server and PHP

- Apache: use `deploy/apache/opng.conf` and deploy `deploy/apache/htaccess` as
  `src/.htaccess` if the panel does not preserve the packaged one.
- Nginx: use `deploy/nginx/opng.conf` and keep the document root at `src/`.
- PHP: load `deploy/php/opng.ini` (OPcache, `display_errors=Off`, logging on,
  `post_max_size=4M`). Restart PHP-FPM after changing OPcache settings.
- Keep the `tools/` and `database/` directories outside the document root.

If the host terminates TLS in a proxy, set `OPNG_FORCE_HTTPS=1` so session
cookies are marked `Secure`, and keep HSTS enabled only on HTTPS responses.

## 8. Schedule backups and pruning

```cron
# Daily portable backup, keep seven days.
15 2 * * * cd /home/USER/opng && php tools/backup.php /home/USER/backups/opng-$(date +\%F).sql && sha256sum /home/USER/backups/opng-$(date +\%F).sql > /home/USER/backups/opng-$(date +\%F).sql.sha256

# Weekly audit-log pruning (uses OPNG_AUDIT_RETENTION_DAYS, default 90).
30 3 * * 0 cd /home/USER/opng && php tools/audit_prune.php
```

Store backups outside the web root, restrict their permissions and read
[BACKUP-RESTORE.md](BACKUP-RESTORE.md).

## No-SSH fallback (phpMyAdmin)

The CLI installer is preferred because it validates every step. When the host
offers only phpMyAdmin:

1. Upload the files as above and create `src/config.php` with the runtime
   credentials.
2. Import `database/schema.sql` into the database. It creates the v1.0 end
   state and records migrations `0001`–`0004` in `schema_migrations`, so a
   later `tools/migrate.php` does not try to re-apply them.
3. In phpMyAdmin open the SQL tab and create the administrator. Generate the
   hash on a trusted local machine with PHP (never paste the clear password
   into the server):

   ```bash
   php -r 'fwrite(STDOUT, "Password: "); echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
   ```

   Then run, replacing the hash and identifiers:

   ```sql
   INSERT INTO users (username, email, password_hash, role, session_version)
   VALUES ('admin', 'admin@example.com', '$2y$...generated...', 'admin', 0);
   ```

4. Open the site and sign in. Then run the manual checklist: an HTTPS URL,
   `display_errors=Off`, `src/config.php` not reachable, `tools/` and
   `database/` not reachable, `/healthz.php` returns `{"status":"ok"}`.
5. There is intentionally no web installer and no `setup.php`. A one-shot web
   installer would be another unauthenticated write path and is not shipped.

## Smoke test

After installation, verify:

1. `/` loads and the language selector works.
2. Sign in as the administrator.
3. Create a paste, open it, reply to it and open the **History** tab.
4. Run `php tools/backup.php /tmp/smoke.sql` and confirm the file is non-empty.
5. `curl -fsS https://paste.example.com/healthz.php` returns
   `{"status":"ok","schema":"0004_entry_threads"}`.

## Troubleshooting

- `ERROR: Set OPNG_INSTALL_DB_USER...` — export the migration credentials.
- `Access denied` — check the host, port and grants; some panels use a socket
  instead of `localhost:3306`.
- `schema version none` — the database was imported incompletely or the
  installer did not finish; rerun `php tools/install.php` with the migration
  account.
- Login redirects to HTTP or loses the session — set `OPNG_FORCE_HTTPS=1` only
  when TLS terminates in front of PHP, and confirm `OPNG_PUBLIC_URL`.
- `The configured database name is invalid` — the installer only accepts
  letters, digits and underscores in `OPNG_DB_NAME`.

For upgrades read [UPGRADING.md](UPGRADING.md); for backups and restores read
[BACKUP-RESTORE.md](BACKUP-RESTORE.md); for hardening read [SECURITY.md](SECURITY.md).
