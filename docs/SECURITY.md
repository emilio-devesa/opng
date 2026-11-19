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

Security fixes target the latest `1.2.x` release. Older 0.x/0.9/1.0/1.1
deployments should upgrade using [UPGRADING.md](UPGRADING.md).

## Deployment checklist

### Files and document root

- Document root is `src/`, never the package root.
- `src/config.php` exists only on the server, mode `0600`/`0640`, owned by the
  web user. It is never committed and never packaged.
- `tools/`, `database/`, `docs/`, `tests/`, `.git/`, `composer.*` and
  `phpstan.neon` are outside the document root and additionally denied by
  `src/.htaccess`/Nginx.
- Directories are not listable (`Options -Indexes`).
- Responses send a strict same-origin CSP (including `form-action 'self'`),
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy` and `X-Frame-Options: DENY` from PHP, Apache
  and the Nginx examples. Keep the three deployment copies in sync.
- Optional hardening: serve static assets with a long immutable cache and
  compression; both are already configured in `deploy/`.

### Database accounts

- **Runtime:** `SELECT, INSERT, UPDATE, DELETE` only on the application
  database. No `CREATE`, `ALTER`, `DROP`, `GRANT` or access to `mysql`.
- **Migration:** a separate account with DDL, used only for
  `tools/install.php`/`tools/migrate.php`; revoke or drop it afterwards.
- Both passwords are unique, long, generated randomly and not reused from
  examples. Rotate immediately if a backup or configuration leaks.
- The database is not reachable from the internet. In Apple Container, do not
  publish port 3306; only the private `opng-net` network reaches MariaDB.

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
  protect the tool. There is no web installer. Prefer the hidden prompt or
  `OPNG_ADMIN_PASSWORD`; `--password=` is rejected unless
  `--allow-argv-password` is explicit because argv is visible in process
  listings and shell history. `--print-sql` output contains a reusable password
  hash, so treat it as a secret.
- `OPNG_DEV_RESET` must never be set in production; it simulates password-reset
  email delivery. While it is unset, the login page hides the password-reset
  link instead of advertising a flow that cannot complete.
- Rate limits, CAPTCHA, bcrypt hashing, session invalidation
  (`users.session_version`), CSRF rotation after privilege changes,
  reauthentication plus explicit confirmation checkboxes for destructive admin
  actions, and generic authentication errors are built in. Do not disable them.
- The audit log records destructive admin actions, authentication events and
  backup downloads with hashed IPs and hashed email targets. Set
  `OPNG_HASH_KEY` (32+ raw bytes or base64) so those identifiers use
  HMAC-SHA-256 instead of an unkeyed SHA-256 that is reversible for IP addresses
  and emails; the doctor warns while the key is missing. Review the audit log
  during incident response; prune it with `tools/audit_prune.php`
  (`OPNG_AUDIT_RETENTION_DAYS`, default 90). Request-time pruning is limited to
  one 1,000-row batch and runs after the business transaction commits. The
  administrator viewer (`admin/audit.php`) filters by action, actor and date and
  shows rate-limit outcomes and quota usage. Application logs are single-line
  JSON with a request ID; the same ID is returned in `X-Request-ID`.
- Set `OPNG_MAIL_TRANSPORT` to `smtp`, `sendmail` or `log` before enabling
  account recovery. SMTP uses STARTTLS/implicit TLS and optional AUTH; verify
  certificates in production (`OPNG_MAIL_SMTP_VERIFY_PEER=1`). The `log`
  transport writes message bodies to the application log and is for development
  only. Set `OPNG_PUBLIC_URL` behind a reverse proxy so reset links are absolute.
  `tools/doctor.php --strict` rejects an invalid transport, sender or SMTP
  configuration.
- Administrator MFA uses TOTP (RFC 6238) and one-time recovery codes. The TOTP
  secret is encrypted at rest with AES-256-GCM under `OPNG_MFA_KEY` (base64,
  exactly 32 bytes); recovery codes are stored as SHA-256 hashes. Keep the key
  outside database backups and rotate it only with a planned re-enrollment. The
  lowest-id administrator must enroll on the next login; the doctor fails while
  MFA is active without a valid key. Destructive actions require the password and
  a TOTP/recovery code when MFA is enabled.
- Visibility is enforced server-side: `private` entries are readable only by the
  author and administrators, `unlisted` entries are reachable by link but never
  listed, and expired entries disappear from listings and direct views. Replies
  inherit the parent's visibility, absolute expiry and protection hash; editing a
  thread root propagates those fields to the whole thread. Protection passwords
  use Argon2id when available and are never stored in clear.
- Deleting a paste moves the whole thread to the trash. Authors see their own
  trashed threads; administrators can restore or purge any trashed thread. Purge
  refuses live rows, keeps the `storage_counters → users → entries` lock order
  and releases counters only after the rows are physically deleted. Set
  `OPNG_TRASH_RETENTION_DAYS` and schedule `tools/trash_purge.php`.
- API tokens are shown once and stored as SHA-256 hashes. The v1 API requires
  the `read` scope, returns only public live entries, applies a per-token rate
  limit and never accepts methods other than `GET`. Revocation is immediate and
  keeps the row for audit. Create, list and revoke tokens from the account
  security page; administrators can review and revoke every token.

### Backups

- Back up daily, verify checksums and test a restore regularly.
- Backups contain password hashes, encrypted MFA secrets and audit data.
  Password-reset tokens are exported as `NULL`, so pending reset links never
  survive a restore and must be reissued. Encrypt backups at rest, restrict
  permissions, keep copies off the host and never put them in the document root.
  The MFA key is not part of the database backup; losing it makes enrolled TOTP
  secrets unreadable.
- The restore tool refuses dumps with `CREATE DATABASE`/`USE` unless explicitly
  allowed, verifies checksums and requires interactive confirmation.
- `tools/backup_external.php` writes to `OPNG_BACKUP_DIR` or uploads to an
  S3-compatible endpoint with native SigV4 signing. Local files can be encrypted
  with AES-256-GCM under `OPNG_BACKUP_KEY`; S3 objects are uploaded as-is, so use
  a private bucket and bucket-side encryption. Every backup has a SHA-256
  checksum and retention is controlled by `OPNG_BACKUP_RETENTION_DAYS`. Restore
  verification creates and drops a disposable schema, so grant `CREATE`/`DROP`
  only to the account that runs verification.

## Container hardening

- Run the application image read-only (`--read-only`) with tmpfs for `/tmp`,
  `/run`, `/var/lib/nginx` and `/var/lib/php/sessions`.
- Publish only `127.0.0.1:8080` unless a TLS reverse proxy is in front.
- Pin images by digest, scan them (Trivy/Grype), keep the SBOM and rebuild
  promptly for base-image fixes.
- Use `--cap-drop ALL` and `--security-opt no-new-privileges` only after
  confirming Nginx can still bind port 8080 and drop to its worker user. The
  provided configuration starts Nginx as a non-root process on port 8080 so the
  minimal capability set is sufficient on engines that support it.
- Never bake secrets into the image. Use `--env-file` with mode `0600` or the
  host secret store.
- `OPNG_AUTO_MIGRATE=1` warns at startup when `OPNG_INSTALL_DB_USER` or
  `OPNG_INSTALL_DB_PASS` is missing. In production, provide a dedicated
  migration account so the runtime account can keep DML-only privileges; the
  warning is intentional and the container still starts for single-account
  development deployments.

## Known limitations

- There is no multi-tenant isolation: all administrators are trusted.
- There is no built-in WAF, IDS or malware scanning.
- MFA is available to administrators only. Registered users rely on password
  authentication and rate limiting; WebAuthn is not implemented in v1.2.
- The CAPTCHA is a local image challenge with an explicit, rate-limited
  arithmetic/audio alternative, not a hosted anti-bot service. The text
  alternative is inherently automatable; the controls that matter are the
  rate limit recorded before validation and the three-attempt challenge budget.
  It slows automation but is not a substitute for rate limits.
- Legacy `RE: ` topics are not linked automatically. Only reviewed pairs are
  linked with `tools/link_legacy_replies.php`.
- Apple Container networking and healthcheck orchestration are the
  operator's responsibility; the image exposes `/healthz.php` for external
  checks. The public response is `{"status":"ok"}`; the schema version is only
  returned when `OPNG_HEALTHZ_DETAIL_TOKEN` matches an `Authorization: Bearer`
  header, so monitoring does not disclose the deployed revision by default.
- Destructive administrative actions distinguish an unreachable service from a
  wrong password: an unavailable rate-limit or users table returns the generic
  `error_service_unavailable` (503) instead of `error_reauth` (403).
