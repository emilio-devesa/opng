# Security guide

Open Pastebin NG is designed for small, self-hosted installations. The
application implements the controls below; the operator is responsible for
hosting, TLS, backups and updates.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use the repository's
**Security → Report a vulnerability** (GitHub private security advisory) with:

- affected version (`src/includes/version.php`),
- deployment type (classic, Apple Container),
- reproduction steps and impact,
- relevant logs with secrets removed.

You will receive an acknowledgement and a coordinated disclosure plan. There is
no bug bounty.

## Supported versions

Security fixes target the latest `1.0.x` release. Older 0.x/0.9 deployments
should upgrade using [UPGRADING.md](UPGRADING.md).

## Deployment checklist

### Files and document root

- Document root is `src/`, never the package root.
- `src/config.php` exists only on the server, mode `0600`/`0640`, owned by the
  web user. It is never committed and never packaged.
- `tools/`, `database/`, `docs/`, `tests/`, `.git/`, `composer.*` and
  `phpstan.neon` are outside the document root and additionally denied by
  `src/.htaccess`/Nginx.
- Directories are not listable (`Options -Indexes`).
- Optional hardening: serve static assets with a long immutable cache and
  compression; both are already configured in `deploy/`.

### Database accounts

- **Runtime:** `SELECT, INSERT, UPDATE, DELETE` only on the application
  database. No `CREATE`, `ALTER`, `DROP`, `GRANT` or access to `mysql`.
- **Migration:** a separate account with DDL, used only for
  `tools/install.php`/`tools/migrate.php`; revoke or drop it afterwards.
- Both passwords are unique, long, generated randomly and not reused from
  examples. Rotate immediately if a backup or configuration leaks.
- The database is not reachable from the internet. Publish only the web port
  through the TLS proxy; keep the database on a private network.

### TLS and cookies

- Serve production over HTTPS. Terminate TLS at the web server or a trusted
  proxy.
- Set `OPNG_FORCE_HTTPS=1` only when TLS terminates in front of PHP and PHP
  cannot see `HTTPS=on`; otherwise the session cookie is not sent over HTTP and
  login breaks. Set `OPNG_PUBLIC_URL` so the doctor verifies the canonical URL.
- Session cookies use `__Host-` names, `HttpOnly`, `SameSite=Lax` and `Secure`
  under HTTPS. Do not relax these values.
- HSTS is sent only on HTTPS responses. Enable it after verifying every
  subdomain is HTTPS-only.
- If a reverse proxy is used, set `OPNG_TRUSTED_PROXIES` to its exact IPs/CIDRs.
  Leaving it empty ignores `X-Forwarded-For`; a wildcard would let clients spoof
  their address and bypass IP rate limits.

### PHP

- `display_errors=Off`, `log_errors=On`, `expose_php=Off`.
- Load `deploy/php/opng.ini` (OPcache, realpath cache, `post_max_size=4M`).
- `session.use_strict_mode=1`, `session.cookie_httponly=1` in the pool.
- Restart PHP-FPM after deploying code when `opcache.validate_timestamps=0`.
- Keep PHP and extensions patched. PHP 8.2+ is required.

### Application

- Run the doctor (`php tools/doctor.php --strict`) after every deployment.
- Create the first administrator with `tools/create_admin.php`; then delete or
  protect the tool. There is no web installer.
- `OPNG_DEV_RESET` must never be set in production; it simulates password-reset
  email delivery.
- Rate limits, CAPTCHA, bcrypt hashing, session invalidation
  (`users.session_version`), CSRF rotation after privilege changes,
  reauthentication for destructive admin actions and generic authentication
  errors are built in. Do not disable them.
- The audit log records destructive admin actions, authentication events and
  backup downloads with hashed IPs and hashed email targets. Review it during
  incident response; prune it with `tools/audit_prune.php`
  (`OPNG_AUDIT_RETENTION_DAYS`, default 90).

### Backups

- Back up daily, verify checksums and test a restore regularly.
- Backups contain password hashes, reset tokens and audit data. Encrypt them at
  rest, restrict permissions, keep copies off the host and never put them in
  the document root.
- The restore tool refuses dumps with `CREATE DATABASE`/`USE` unless explicitly
  allowed, verifies checksums and requires interactive confirmation.

## Known limitations

- There is no multi-tenant isolation: all administrators are trusted.
- There is no built-in WAF, IDS, malware scanning or email delivery.
- The CAPTCHA is a local arithmetic/image/audio challenge, not a hosted
  anti-bot service. It slows automation; it is not a substitute for rate limits.
- Legacy `RE: ` topics are not linked automatically. Only reviewed pairs are
  linked with `tools/link_legacy_replies.php`.
