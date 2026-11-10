# Open Pastebin NG - Changelog
============================

## Version 1.1.0 (2026-11-10)

### Phase 7 — Code hygiene
#### 🧹 Strict types and coding standard (HYG-07)
- Enabled `declare(strict_types=1)` in every file under `src/includes/`, the gradual first step recommended by the audit. Entry points and CLI tools keep their current coercion behaviour until their call sites are reviewed.
- Added `PHP_CodeSniffer` with an adapted PSR-12 ruleset (`phpcs.xml`) for `src/includes/`. The ruleset excludes the procedural-file, namespace and closing-tag rules that do not apply to shared includes, and ignores long template lines. Fixed the existing violations in the directory.
- Added `composer phpcs` and a dedicated `style` CI job. New includes must pass the ruleset; other directories will be added as they are cleaned up.

#### 🌐 Comment language consistency (HYG-10)
- Translated the remaining Spanish code comments in `src/` and `tools/` to English, keeping user-facing strings in the six i18n catalogs and Spanish documentation unchanged.
- Removed stale `(v0.8)` and historical-fix prefixes that added no value to current readers. Seeded paste samples keep their original language because they are content, not comments.

#### 🐛 Paste view and history fixes
- The reply editor starts with the parent paste text, so replying keeps the original content instead of opening an empty textarea.
- The owner's delete button now opens a centred floating dialog with the current-password reauthentication field, the confirmation checkbox and Cancel/Delete actions. The confirmation always starts unchecked, the dialog closes on Escape or Cancel, closing clears the password and unchecks the confirmation again, and the server-side checks remain the only authority.
- History also matches the thread root by primary key, so legacy or seeded roots with `root_id = NULL` no longer disappear from their own thread. The seeder now stores `root_id = id` and `depth = 0`, and the integration suite covers a `NULL`-rooted legacy entry.

#### 🛠️ Admin panel
- Entry deletion, role changes, user deletion, sample-data instructions, backup download, emptying the database and deleting the database now use compact action buttons that open centred native `<dialog>` modals. Passwords and confirmation checkboxes start empty and unchecked, and reopening a dialog never restores a previous confirmation.
- The Manage Users form now has independent role-change and account-deletion forms, so each POST carries only its own password, confirmation and paste-handling option.
- Database actions are arranged as a responsive 2×2 card grid. The audit log is a collapsible `<details>` card, so the page no longer ends with a permanently expanded table.
- Global paste storage moved into Metrics as an accessible `<progress>` bar with used/total bytes, percentage and the near-quota warning. It sits below the language and user charts and is no longer repeated as plain text above the database actions.
- Emptying the database now requires both the current password and a server-enforced `confirm_empty` checkbox, before reauthentication and before any deletion.

#### 📚 Documentation and project website
- Updated the README, backup/restore guide and security guide to describe the admin confirmation dialogs, collapsible audit log and storage progress chart.
- Added [`docs/WEBSITE.md`](docs/WEBSITE.md) and a static GitHub Pages site on the `gh-pages` branch, including refreshed screenshots.
- Refreshed the project screenshots used by the README and website.

## Version 1.0.0 (2026)

### Phase 3 — Feature Historial (F1)
#### 🧵 Thread model and migration
- Added migration `0004_entry_threads` and the canonical `database/schema.sql` columns `entries.parent_id`, `entries.root_id` and `entries.depth`. Existing rows are backfilled as roots (`root_id = id`, `depth = 0`); legacy `RE: ` topics are intentionally not linked heuristically.
- `parent_id` references `entries.id` with `ON DELETE SET NULL`, so deleting a node detaches its replies without deleting them; `root_id` is preserved so the orphaned subtree remains reachable. Added `idx_entries_parent` and `idx_entries_root` for history queries and child checks.
- Added `src/includes/thread.php`: parent lookup with optional row lock, position calculation (maximum depth 32), one-query chronological page fetch (maximum 500 nodes), ancestor derivation and tree grouping. Corrupt cycles are guarded without extra queries.
- Added `tools/link_legacy_replies.php` for reviewed migrations of legacy `RE: ` pastes: `--dry-run` lists candidates, `--apply` requires explicit `--reply`/`--parent` IDs, validates topic/date/depth, recalculates the subtree and writes `entry.link_legacy` to `audit_log`. It does not use recursive CTEs, so MySQL 5.7 remains supported.

#### ✍️ Replies and history UI
- `view.php?tab=reply` now submits a hidden `parent_id`; `submit.php` validates the ID with the shared input helpers, locks the parent inside the quota transaction, rejects missing parents and depth 33, stores `parent_id`/`root_id`/`depth`, and redirects to the new reply. The reply textarea starts empty instead of copying the parent text.
- Added the **History** tab for entries that are replies or have replies. It shows a chronological `<ol>` with topic, `<time>`, author (`Guest` when anonymous) and *Ancestor*/*Current*/*Descendant* badges, plus a semantic nested `<ul>` branch graph with connector CSS, `aria-current="page"` and no JavaScript dependency.
- Large threads show the first 500 entries, a translated “very large thread” notice, a `<details>` branch toggle and a `date, id` “Load more” cursor. Deleted parents/roots show a preserved-replies notice; links and topics are escaped.
- Added `error_thread_too_deep`, the history UI strings and both notice/toggle strings to all six catalogs (268 keys each).

#### 🧪 Tests and docs
- Added `tests/thread_test.php` (position, depth 32, grouping, cycle guard, migration/schema/UI/catalog contracts) and `tests/thread_integration_test.php` (disposable database: root → reply → nested reply, chronological history and authors, parent/root deletion, depth-32 chain, `delete_entries`/`preserve`, `DELETE FROM entries`).
- Extended `tests/database_install_test.php` (migration/columns/indexes/SET NULL FK/backfill on an upgraded legacy schema), `tests/performance_query_plan_test.php` (`idx_entries_root` plan and 500-node page) and `tests/backup_restore_test.php` (threaded rows survive a portable restore).
- Documented the feature, migration and integration tests in `README.md`; CI now runs both thread suites.

### Phase 4 — Release clásica para hosting compartido (F2)
#### 📦 Artefacto y herramientas
- Added `tools/build_release.sh`, which validates PHP syntax and builds `dist/opng-<version>-classic.zip`, `.tar.gz` and `SHA256SUMS`. The package excludes `tests/`, `.github/`, Composer/PHPStan files, screenshots, `.git/`, `src/config.php`, `tools/seed.php` and the container deployment, and always ships `src/config.example.php`.
- Added `tools/install.php` as the canonical CLI wrapper, `tools/create_admin.php` for the first administrator (`--if-empty`, `--force`, `--print-sql`, `OPNG_ADMIN_PASSWORD`) and `tools/restore.php` for checksum-verified restores. The restore tool rejects `CREATE DATABASE`/`USE` unless explicitly allowed, uses a temporary `0600` options file and supports `OPNG_MYSQL_CLIENT`.
- `database/schema.sql` now records migrations `0001_baseline` through `0004_entry_threads`, so a phpMyAdmin import produces the same recorded end state as the CLI installer. Added `tests/schema_drift_test.php`, which compares every `SHOW CREATE TABLE` from the two installation paths.
- Added `tests/release_contract_test.php`: it builds a real artifact, checks exclusions, required docs/configs and published checksums.

#### 🌐 Servidores, documentación y CI
- Added `deploy/apache/opng.conf`, `deploy/apache/htaccess` and moved the canonical Nginx example to `deploy/nginx/opng.conf`. All three deny the document root, internal PHP files and development directories, and set body-size, CSP, HSTS and cache headers.
- Added `docs/INSTALL-CLASSIC.md`, `docs/UPGRADING.md`, `docs/BACKUP-RESTORE.md` and `docs/SECURITY.md`, covering the CLI and phpMyAdmin paths, 0.9→1.0 migrations, portable backups and the production checklist.
- Added `.github/workflows/release.yml`: on `v*` tags it runs the contracts, builds the classic artifacts, installs them into MySQL 8.0, smoke-tests the health endpoint, login provisioning and backup, and publishes the zip/tarball with `SHA256SUMS`.

### Phase 5 — Apple Container (F3)
#### 🍎 Imagen y orquestación
- Added one arm64 application image (`deploy/apple-container/Containerfile`) with Nginx on port 8080, PHP-FPM on loopback, supervisord, OPcache, GD and mysqli. It keeps the document root at `/var/www/opng/src`, never bakes secrets into the image and removes `src/config.php` at build time.
- Added `entrypoint.sh` (tmpfs preparation, MariaDB wait, idempotent schema bootstrap/migrations via `OPNG_AUTO_MIGRATE`, first-admin provisioning and credential scrubbing before supervisord), `healthcheck.sh`, and host scripts `start.sh`/`stop.sh`/`backup.sh`/`restore.sh`.
- `start.sh` creates the private `opng-net` network and persistent `opng-db-data`/`opng-backups` volumes, runs MariaDB 11 without publishing 3306, starts the app read-only with tmpfs mounts, publishes only `127.0.0.1:8080` and filters `OPNG_DB_ROOT_PASSWORD` out of the application environment.
- Added `.containerignore`, `.dockerignore` and `deploy/apple-container/opng.env.example`. Real environment files and `src/config.php` are excluded from the build context.
- Added `tests/container_contract_test.php` and `.github/workflows/container.yml` (Buildx `linux/arm64`, GHCR on tags, SBOM and provenance). Documented installation, upgrades, backups, security and troubleshooting in `docs/APPLE-CONTAINER.md`.

### Phase 6 — CI y cierre de release
#### ♿ Accesibilidad, i18n y confirmaciones (UX-04…UX-09, HYG-02)
- Added `src/includes/i18n.php`: the `opng_lang` cookie (then `Accept-Language`, then English) selects the locale, sets `<html lang>` and translates the buffered layout with `DOMDocument` before the first paint. `language.js` keeps the cookie and `localStorage` in sync, prefers the server-rendered `lang`, and never overwrites elements with nested markup.
- Added a dependency-free `<time datetime>` renderer with locale-aware date formats and the raw stored value in `title`; the public listing now has an explicit empty state with a “create the first entry” link.
- Auth forms keep their inline errors, preserve safe posted values and now expose `id="form-error"`, `aria-invalid`, `aria-describedby` and `autofocus` on the first invalid field. The shared CAPTCHA accepts an error association for the same purpose.
- Paste deletion, admin entry deletion and admin user actions now require a visible, server-enforced `confirm_delete`/`confirm_action` checkbox. The JavaScript-only confirmation (`confirm.js`) was removed, so the destructive actions remain confirmed with JavaScript disabled.
- Added the `accessibility` CI job (`MySQL 8.0` + seeded data + PHP server + Playwright): `tools/a11y_check.mjs` validates rendered HTML with `html-validate` and runs axe WCAG 2.x rules on index, view, login, register and admin in English and Spanish. Serious/critical violations and HTML errors fail the job. Added `tests/i18n_test.php`, `tests/form_accessibility_test.php` and `tests/destructive_confirmation_test.php`; the classic artifact excludes the Node-based a11y tooling.
- Fixed the History tab query-string limit (`tab=history` exceeded the previous five-byte limit) and added a regression assertion.

#### 🧪 Matriz de bases de datos (HYG-03)
- Added a `database-mariadb` CI job alongside the existing MySQL 8.0 job. Both run the same legacy-schema migration, CLI installer, `database_install_test`, `backup_restore_test`, `thread_integration_test`, `schema_drift_test` and ambiguous-case guard, so `lower_case_table_names`, collation and FULLTEXT differences are exercised on both engines.
- `performance_query_plan_test.php` stays MySQL-only: its `EXPLAIN` assertions encode MySQL optimizer choices, while MariaDB legitimately chooses a full scan for the small single-user fixture. MariaDB still receives the same indexes and is checked by the schema drift test.
- Fixed `tests/backup_restore_test.php` to insert a unique `9999_backup_fixture` migration version instead of a duplicate recorded version, so the test works with the new `database/schema.sql` migration history on both engines.

## Version 0.9.0 (2026)

### Phase 1 — Integrity and error reporting
#### 🧱 Data integrity
- **Paste text is no longer trimmed on save.** `submit.php` and `update.php` now read `input_text` with `trim: false`, preserving leading indentation and trailing newlines (relevant for Python, YAML, Makefiles and copy/paste fidelity). Whitespace-only pastes are still rejected by `paste_validation_error()`.
- Added `tests/paste_text_preservation_test.php`, which covers the `http_string_parameter()` trimming contract and asserts that both save paths opt out of trimming.

#### 🧭 Error handling
- **Error redirects now use canonical i18n keys instead of free-text messages.** Added `src/includes/error_messages.php` with the `OPNG_ERROR_KEYS` allowlist plus `opng_error_key()`, `opng_error_status()`, `opng_error_text()` and `opng_error_redirect()` helpers; replaced ~95 text-based redirects across `src/` (auth, admin, index, view, submit, update, pastebin).
- `error.php` validates the incoming key against the allowlist, always renders a server-side English fallback from `assets/lang/en.json` (so the page still explains the problem without JavaScript) and returns the HTTP status associated with the key.
- `paste_validation_error()` now returns canonical keys (`error_empty_text`, `error_text_too_large`, `error_topic_required`, `error_topic_too_large`); the new keys and `error_invalid_sort` were added to all six catalogs.
- Added `tests/error_messages_test.php` to verify catalog coverage for every key, the server-side fallback, and that no legacy text-based redirect remains in `src/`.

#### 🧾 Forms and UX
- **Registration errors are shown inline** in `src/auth/register.php` instead of redirecting to `error.php`, and the form repopulates `username` and `email` (never the password) after a failed attempt.
- **Live remaining-length meter** for create/edit/reply textareas: `src/assets/js/length-meter.js` counts UTF-8 bytes, updates a small right-aligned progress bar and switches to the danger color past the 1 MiB limit. Translations added as `length_mb_remaining` / `length_meter_label` in all six catalogs.

#### 🌐 Languages
- Split the legacy `Java/C/C++` rule into **C**, **C++** and **Java**, and added **Ruby** to `src/rules.xml`, matching the README and the bundled Highlight.js build. `view.php` maps each rule to its `language-*` class and keeps a `java/c/c++` → `language-cpp` fallback for existing pastes; edit/reply selects preselect `C++` for legacy values.
- Updated `tools/seed.php` language distribution and snippets for C, C++, Java and Ruby.

### Phase 2 — Performance and scalability
#### ⚡ Listing and schema
- **Removed `COALESCE()` from listing sorts.** `entries.date` is now `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `entries.topic` is `VARCHAR(255) NOT NULL` and `entries.language` is `NOT NULL`; legacy `NULL` dates become `1970-01-01 00:00:00` during the idempotent installer migration. The public listing now uses bare `date`/`topic`/`language` columns so `idx_entries_cursor`, `idx_entries_topic` and `idx_entries_language` can serve `ORDER BY` without filesort. The migration account needs `UPDATE` for the one-time backfills.
- Extracted `build_listing_query()` so the production `WHERE`/`ORDER BY` and the query-plan test share the same SQL (M9). The performance test now refreshes statistics with `ANALYZE TABLE` after its synthetic 5,000-row load.

#### 🖥️ Admin panel
- **Dashboard metrics are cached in MySQL** (`admin_metrics_cache`, 5-minute TTL) and invalidated by entry deletion, database emptying and user-management actions.
- Entry and user result lists now use keyset cursors (`date,id` / `created_at,id`) instead of `OFFSET`; topic search uses `MATCH ... AGAINST`, while author/username/email keep substring `LIKE` matching.

#### 🎨 Rendering and assets
- **Line numbers are capped at 5,000**; longer pastes render the full code without a gutter and show a translated notice. Highlight.js is only loaded for non-Plaintext pastes.
- A single memoized `opng_asset_version()` now supplies the cache-busting version for CSS, JavaScript, vendor bundles and language catalogs instead of repeated `filemtime()` calls per asset.

#### 🔐 Rate limiting
- Rate-limit hits are recorded with a single `INSERT ... ON DUPLICATE KEY UPDATE` statement using `LAST_INSERT_ID(expr)`, reducing four queries to one (plus an opportunistic cleanup), with a read fallback for drivers that do not expose the expression value.

### Phase 3 — Abuse resistance and operations
#### 🔐 Authentication and abuse limits
- **Login now uses three persistent limits** in addition to the session counter: IP+account (5/10 min), IP-wide (30/10 min) and account-wide (10/10 min). After three failed attempts in the same session, an adaptive CAPTCHA is required before another password check, reusing the local GD CAPTCHA.
- **Guest paste creation requires the local CAPTCHA.** Authenticated accounts keep a 20/min budget while guests are limited to 5/min. Both remain keyed by the hashed client IP.
- Added a configurable **global paste-text quota** (default 10 GiB) via `OPNG_STORAGE_QUOTA_BYTES`; the admin panel shows current usage and warns from 90%. The existing per-account 100 MiB quota is unchanged.
- Rate-limit outcomes now distinguish **`Allowed`, `Limited` and `Unavailable`**. A database/table failure during login, registration, reset or paste creation returns HTTP 503 with the new `error_service_unavailable` key instead of claiming that the user made too many attempts.

#### 🧾 Audit and administrative operations
- Added the `audit_log` table (`actor_id`, `action`, `target`, `ip_hash`, `created_at`) and `audit_log_record()`. Entry deletion, database emptying, role changes, account deletion and backup downloads are recorded with a SHA-256 IP hash.
- The admin panel renders the **latest 20 audit entries** and falls back gracefully when the table has not been migrated yet.
- **Backup downloads now use a CSRF-protected POST** instead of GET, and the download itself is audited.
- The CLI installer creates `audit_log` alongside the existing tables; `tests/database_install_test.php` checks its columns.

#### 🛡️ Deployment hardening
- The shared layout, Apache `.htaccess` and Nginx example now send `frame-ancestors 'none'` and `X-Frame-Options: DENY`.
- Added `OPNG_FORCE_HTTPS=1`, which marks the session cookie `Secure` for TLS-terminating proxies without adding redirects.
- Documented the Nginx `rules.xml` denial, the `src/` document-root requirement, and the new environment variables.

#### 🧪 Tests
- Added `tests/rate_limit_result_test.php`, `tests/global_quota_test.php` and `tests/audit_log_test.php`; extended session, frontend-contract and database-install coverage for forced HTTPS, frame headers, backup POST/CSRF, the audit log and the new rate-limit outcomes.

### Phase 4 — Deployment robustness
#### 🧱 Installer and schema migration
- **Legacy `users` tables are migrated idempotently.** The CLI installer now adds missing `role`, `reset_token`, `reset_token_expires` and `created_at` columns, validates `password_hash`, the primary key and the unique `username`/`email` indexes, and stops with an actionable message when a core column cannot be migrated safely.
- **`entries` is validated and normalized.** The installer converts the table to InnoDB and `utf8mb4_unicode_ci` when needed, adds a missing `user_id`, checks `id`, the primary key and the required columns, and prints a migration report listing every change.
- The CI legacy fixture now omits the user role/reset columns and uses a MyISAM/latin1 `Entries` table, so the migration path is exercised end to end.

#### 🛡️ Shared guards and backup limits
- Added `src/includes/admin_guard.php` with `require_authenticated_user()` and `require_admin()`; the admin scripts now share one authorization path instead of repeating session/role checks. Owner entry deletion uses the authenticated guard.
- Admin backups are bounded by `OPNG_BACKUP_MAX_SECONDS` (default 300) and `OPNG_BACKUP_MAX_BYTES` (default 512 MiB). The new `error_backup_limit` key explains how to raise the limits.

#### 📦 Dependencies and credentials
- `composer.json` now declares `ext-dom` and `ext-ctype`, matching the runtime requirements already documented in the README.
- Documented that deployed installations must use a unique, non-empty runtime password, must not reuse the development example credentials, and should rotate any credential that was committed or shared.

#### 🧪 Tests and docs
- Extended `tests/database_install_test.php` and `tests/frontend_contract_test.php` for the user/entries migration, shared guards, backup limits and Composer extensions; added `error_backup_limit` to all six catalogs.
- Updated `README.md`, `CONTRIBUTING.md` and `src/config.example.php` with the migration behavior, extension requirements, backup limits and credential guidance.

### Phase 5 — UX and accessibility
#### 🔑 Account security
- **Added `src/auth/change_password.php`.** Signed-in accounts can rotate their password with the current password, the new password and a confirmation. The page uses CSRF validation, a persistent 5/10-minute rate limit, the shared 12–72 byte password policy, `session_regenerate_id(true)` after success and invalidates any pending reset token.
- The account navigation now links to the change-password page for both regular users and administrators.

#### ♿ Accessibility and headings
- Added a **skip-to-content link** as the first body element and exposed `#Content` as a focusable `<main>` landmark.
- The header now renders the site name (`title_index`) instead of duplicating the page title; each page has a single content `h1` (index, create, view topic, auth, error and admin pages) and section headings were demoted accordingly.
- The language selector no longer overrides its translated label with a fixed `aria-label`; each option now exposes a flag plus the native language name (for example `🇬🇧 English`) and its `lang` attribute.
- Search help is an accessible disclosure: `aria-expanded`/`aria-controls` with a click/touch toggle, outside-click dismissal and Escape support, in addition to hover/focus.

#### 🧪 Tests and docs
- Added `tests/change_password_test.php`; extended `tests/frontend_contract_test.php` (skip link, headings, language selector, search-help disclosure, change-password contracts) and `tests/input_validation_test.php` (11-byte rejection).
- Added nine keys to all six catalogs (230 keys each) and documented the new password flow and accessibility behavior in `README.md`.

### Phase 6 — Version close-out
#### 🏷️ Versioning
- **Centralized the application version.** `src/includes/version.php` defines `OPNG_VERSION` (currently `0.9.0`); the shared footer renders it instead of a hard-coded `v0.8`, and `README.md` points to the same constant.
- Consolidated the five audit phase entries under a single `## Version 0.9.0 (2026)` release heading.

#### 🛡️ Deployment review
- Rechecked `src/.htaccess` and `deploy/nginx.example.conf`: both keep `frame-ancestors 'none'`, `X-Frame-Options: DENY`, the `rules.xml`/`config*.php`/`database.php` denials and the `src/` document-root guidance, while the session cookie honours `OPNG_FORCE_HTTPS=1` without redirects.

#### 🎨 Theme and header controls
- Header chips (home, theme, language, account and logout) now use dedicated `--control-*` tokens: light mode gets a white surface, a stronger border and a subtle shadow instead of blending into the page, while dark mode keeps its existing surfaces.
- Replaced the native language-select arrow with a centered custom triangle and removed the browser's default select chrome, so the flag and language name stay aligned.
- Search and "create entry" actions now use `--action-*` tokens: a tonal light chip in light mode and the filled accent in dark mode, making the theme switch visibly change their appearance.

## Version 0.8 (2026)
### 🛠️ Administration and database recovery
- Reorganized the admin panel around entry management, user management and database actions; added parameterized search with ID selection, role updates, and guarded account deletion with a choice to anonymize or delete associated pastes.
- Added an administrator-only SQL backup download using a read-only consistent snapshot and safe value serialization. The UI warns that dumps contain password hashes/reset tokens and that restore replaces tables; complete database deletion remains a manual MAMP operation so the web runtime needs no `DROP` privilege.
- Added admin metrics for total pastes/users, language distribution, the five most active users plus the remainder, and the total byte size of paste text.
- Added a first database-action button that opens administrator-only CLI seeder instructions, including its fixture cleanup scope and known development password; the seeder remains inaccessible through HTTP.
- Added responsive admin controls with role radio buttons, search/action separators and reduced login autofill on the numeric account-ID field; added deletion confirmation, translations in all six locales, SQL serialization regression coverage and documented backup/restore handling.

### ⚡ Performance and scalability — Phase 3
- Replaced wildcard topic scans with a bounded, sanitized FULLTEXT query; installer now adds topic, cursor-ordering and owner indexes.
- Replaced numbered OFFSET pagination and per-request COUNT(*) with stable date/ID keyset cursors and a 51-row window.
- Added a concurrency-safe 100 MiB cumulative content quota for normal accounts; admins and guest pastes are exempt, and over-quota content is never deleted.
- Versioned and browser-cached translation catalogs; documented the search semantics, quota, and schema-upgrade procedure.
- Added regression coverage for search normalization, bidirectional cursors, quota boundaries, paste permissions, controlled database-connection failures, frontend contracts, and indexed EXPLAIN plans on a synthetic 5,000-row MySQL dataset.

### 🎨 UX, accessibility and CSP — Phase 2
- Vendored a local Highlight.js 11.12.0 bundle limited to the supported languages, with its BSD-3-Clause license. Paste code is highlighted only after HTML escaping; copy uses the Clipboard API with visible, translated success/error feedback.
- Authentication and error pages now use the shared layout and six-language catalogs. Added associated labels, accessible names, password guidance, translated server messages and correct document language updates.
- Logout now requires a CSRF-protected POST and expires the session cookie. Destructive confirmations use external event listeners; removed inline handlers/styles and `unsafe-inline` from Apache/Nginx CSPs, including static and CAPTCHA locations.
- Improved view/navigation semantics, current-page state, focus visibility, responsive table scrolling and code line-number accessibility. Standardized redirect-after-POST feedback for reset requests and successful password changes.

### 🔐 Security and robustness — Phase 1
- The schema installer is CLI-only, requires a pre-created database, and uses separate `OPNG_INSTALL_DB_*` credentials. Runtime defaults now target a dedicated DML-only account; migration rights can be revoked after installation.
- The installer creates lowercase table names, safely renames only unambiguous legacy case variants, and stops on ambiguous schemas. Existing `entries.text` is widened to `MEDIUMTEXT` without deleting rows.
- Replaced `TRUNCATE` with `DELETE` in the admin empty action so the runtime account does not need `DROP`; added Linux/MySQL CI coverage for case-sensitive migration, data preservation, input limits, and DML-only runtime privileges.
- Nginx's example redirects HTTP to TLS, blocks web execution of the installer, sets PHP's HTTPS state at TLS termination, and documents HSTS and request-size limits. Session cookies no longer trust arbitrary `X-Forwarded-Proto`.
- Added server-side limits of 1 MiB per paste and 255 bytes per topic, plus a 12–72-byte password policy for registration and reset. Critical auth and paste routes now validate scalar inputs and handle database exceptions without reporting success on failed writes.
- Large pastes use constant-memory line counting and a bounded editor height instead of allocating one array element per line.
- Composer and CI now target the documented patched PHP release branches (8.2–8.5).

### 🔐 Security — password reset hardening
- **Disabled public password-reset requests by default.** Until real email delivery is configured, a simulated reset link is shown only when `OPNG_DEV_RESET=1` and both the request host and remote address are loopback/local.
- Reset tokens are now stored as SHA-256 hashes, expire after 10 minutes, and are consumed by a single conditional password update. The local simulation displays a one-time link only; it never exposes or recovers a plaintext password.
- Added independent hourly limits for reset requests by client IP and account. Rate-limit storage/query failures now deny the action and are logged instead of silently bypassing the limit.
- Added regression tests for default-off behavior, local-only request checks and token hashing, and wired them into CI.

### 🌐 Internationalization
- **Admin panel translations:** new i18n keys (`admin_title`, `admin_db_actions`, `empty_pastes`, `admin_manage_entries`, `id_to_remove`, `drop_entry`, `drop_confirm`) added to all language files (en, es, de, fr, pt, zh) and wired into the admin panel markup with `data-i18n` (panel title, database actions legend/button, manage entries legend, ID label, drop button). The drop confirmation dialog now reads the translated message instead of a hardcoded string.

### 🎨 Design refinements
- **Index:** removed the redundant "List of Entries" heading (the header now owns the page title).
- **Index:** the "Create New Entry" button is now right-aligned.
- **Pagination:** restyled as pill-shaped chips (navigation links + current page).
- **Header:** theme toggle and language selector kept on the same line; auth buttons (Login/Register/Logout) as right-aligned chips; new admin chip (when the logged-in user is admin) linking to the admin panel.
- **Index:** "Create New Entry" button now left-aligned; search form made smaller and right-aligned; theme toggle and language selector kept in line with the title, aligned right.
- **Index:** list toolbar row with "Create" (left) + pagination (center) + search form (right); added "Back to top" link.
- **View:** stacked the view/edit/reply boxes vertically (`.container` column) so the code/edit/reply text fields get full width instead of squeezed side-by-side columns.
- **View:** converted the three boxes into tabs (Ver / Editar / Responder) via `?tab=` (with `can_edit` guard for the Edit tab).

### 🔐 Runtime and deployment hardening
- Composer enforces PHP patch floors of 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12. The README links to the PHP supported-versions policy and the NVD records used to set these floors; it makes no unverified exploitation-status claims.
- OPNG does not use file uploads or session upload progress. Apache disables upload progress in the shipped `.htaccess`; the Nginx/PHP-FPM example documents the equivalent as optional hardening, without attributing it to an unverified advisory.
- Added a production deployment checklist covering the actual HTTP PHP SAPI, TLS, database privilege separation, installer access, backups, logs, request limits and smoke tests.
- Shipped hardened `src/.htaccess` for Apache:
    - Blocks direct HTTP access to `config.php`, `database.php` and `rules.xml`.
    - Disables `session.upload_progress` automatically under mod_php.
- Documented verification against the actual HTTP PHP SAPI and optional upload-progress hardening for PHP-FPM.
- Added a server-admin migration checklist covering branch v0.8 work in the security audit report (`opng_audit_0.7.md`).
### 🔒 Security — Application fixes (Phase 1 of the audit)
- **`admin/empty.php` rewritten** (critical, fixes S1/S2/R1):
    - Replaced the fragile `require_once('login.php')` guard with an explicit admin role check (`$_SESSION['role'] === 'admin'`).
    - Replaced the destructive `DROP DATABASE` with `TRUNCATE TABLE entries` — "Empty Database" now only removes pastes and **preserves user accounts**.
    - Added CSRF verification on the POST action and unified the response pattern (redirect with `?msg=`).
    - DB errors are now logged via `error_log()` and shown generically to the user (no more schema/driver leaks in this script).
- **XSS hardening in `view.php`** (fixes S3 / double-escaping):
    - `submit.php` now stores the paste text and topic **raw**; `view.php` applies `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on output (code block and edit textarea).
    - This is the correct escape-at-output strategy: highlight.js keeps working (it reads the decoded `textContent`) and the content is no longer re-escaped on every "Reply".
- **`submit.php` — guaranteed `NULL` for guest pastes** (fix R3): the INSERT now binds `user_id` explicitly (`VALUES (?, NULL, …)` for guests) instead of relying on driver behavior for `null` bound as an integer.
- **`exit` added after every redirect** (fix R5): `admin/drop_id.php` returned HTML after `header("Location: …")`.
- **`auth/login.php` reordered** (fix H3/U5): authentication failures now redirect *before* any HTML output (previously the `header()` was called inside the `<body>`, triggering "headers already sent" and hiding the error). Also removed the double encoding (`urlencode(htmlspecialchars(…))` → `urlencode(…)`).
### 🔧 Infrastructure & UX refactor (Phase 2 of the audit)
- **New `src/includes/` layer — shared logic now lives in one place:**
    - `db.php` — `db_connect()` opens the MySQL connection **without running any DDL** (previous behavior migrated the schema on every request; fixes P1/P3).
    - `csrf.php` — centralized CSRF token generation (`csrf_token()`, `csrf_input()`, `csrf_valid()`).
    - `rules.php` — single implementation of the `rules.xml` allowlist loader (`load_rules()`), previously duplicated in 3 files.
    - `session.php` — `session_start_secured()` sets hardened cookie params (`httponly`, `samesite=Lax`, `secure` when HTTPS) before starting the session. Applied across all pages.
    - `layout_header.php` / `layout_footer.php` — shared HTML layout with the theme/language controls and the session navigation (Login/Register/Logout), replacing the blocks previously copy-pasted in each page.
- **Key behavior note (`samesite`)**: `Lax` chosen over `Strict` on purpose — `Strict` drops the session cookie when following an external link, which hurts navigation on a public pastebin.
- **New `src/install.php`** — idempotent installer that creates the database, `Users`/`Entries` tables and the new `idx_entries_date` index. **Deployment change: run it once and delete it** — the app no longer auto-migrates on every visit.
- **`index.php`: pagination + search** (fixes P2/U4):
    - Listing query (`ORDER BY entries.date DESC`) now backed by the new index (no filesort).
    - **50 entries per page**, handled via `?p=`, with Prev/Next navigation keeping the search filter (out-of-range pages are clamped).
    - Search box filtering by topic (`?q=...` LIKE search).
    - Flash message display for success messages reaching `index.php?msg=` (e.g. after emptying pastes).
- **Real paste editing (UPDATE)** (fixes U1):
    - New `src/update.php`: validates CSRF, requires a session and enforces ownership (author of the paste or admin) before running the UPDATE.
    - `view.php` now shows an **Edit** box (pre-filled, bound to current language) only to users who can edit, and the previous "RE:" form is renamed **Reply (new paste)**.
    - `view.php` also displays the `?msg=` flash after a successful edit.
- **i18n completed** (fixes U2/U7):
    - All previously hard-coded Spanish/English texts now default to English and are tagged with `data-i18n` keys: submit.php, update.php, view.php, login.php, register.php, request_reset.php, reset_password.php, error.php and the admin empty-database page.
    - 49 keys per language file (en, es, de, fr, pt, zh); a consistency check confirms every `data-i18n` key used in the code exists in all six files.
    - Backend flash/error messages were also normalized to English (they were mixed Spanish/English).
- **Runtime verification (PHP 8.3.30 + MySQL, local smoke test)** — bugs found and fixed:
    - `index.php` passed the `ENTRIES_PER_PAGE` constant to `bind_param()`, which requires variables by reference → fatal error (`Argument #2 could not be passed by reference`). Fixed by binding a local `$limit` variable.
    - Broken relative redirects: `admin/admin.php` and `auth/login.php` pointed to `error.php` instead of `../error.php` (404 instead of the error page).
    - Login now detects an existing session via `$_SESSION['user_id']` (the previous check used `$_SESSION['logged_in']`, which is never set), and `redirect_after_login` is validated to prevent open redirects.
    - `admin/drop_id.php` no longer lets a registered user delete **anonymous** pastes through a crafted POST; only the owner or an admin can delete (matches the documented permissions).
    - `submit.php` no longer prints raw `$db->error` to the user (S6): the detail goes to `error_log()` and the user gets a generic message.
    - CSRF protection extended to **`register.php`, `request_reset.php` and `reset_password.php`** (previously only login and destructive actions verified the token).
    - Auth pages: fixed the reset link (was an absolute `/auth/...` path that broke subdirectory installs), the `main.css` path and the remaining Spanish strings in `request_reset.php`.
    - **Language selector fixed**: `dark-mode.js` and `language.js` both declared `const basePath` in the global scope. Classic scripts share the same scope, so the second file failed with `SyntaxError: Identifier 'basePath' has already been declared` and never ran (the selector did nothing). Both scripts are now wrapped in IIFEs and translations are applied with `textContent`.
    - Added **`tools/seed.php`** (CLI-only, re-runnable): seeds 10 users (8 normal + 2 admins), 60 pastes and 12 replies (72 entries) to exercise pagination, search and role permissions.
    - Every PHP file passes `php -l` (PHP 8.3.30) and the main flows (create, view, edit, reply, delete, pagination, search, login/logout, register, password reset pages, admin panel, empty DB) were exercised end-to-end against a local MySQL instance.
### 🛡️ Hardening & maturity (Phase 3 of the audit)
- **Configuration no longer versioned (fix S4):**
    - `src/config.php` was removed from git (`git rm --cached`; the local file is kept) and added to `.gitignore`.
    - New `src/config.example.php` to copy (`cp src/config.example.php src/config.php`), with optional `OPNG_DB_HOST` / `OPNG_DB_USER` / `OPNG_DB_PASS` / `OPNG_DB_NAME` environment-variable overrides for Docker/CI.
    - `config.php` and `config.example.php` are blocked by `src/.htaccess`; the README now explains the copy step.
- **Persistent rate limiting (fixes S5/R8):**
    - New `rate_limits` table created by `install.php`; keys are stored as SHA-256 hashes (no IPs or emails in clear).
    - New `src/includes/ratelimit.php` (`client_ip()`, `rate_limit_hit()`) with opportunistic cleanup of old windows and fail-open behavior on DB errors (logged).
    - Applied limits: login **5 attempts / 10 min per IP+account** (the old session counter could be bypassed by deleting the cookie), paste creation **5/min per IP**, registration and password-reset requests **5/hour per IP**.
- **Stronger local CAPTCHA (fix S7):** `auth/captcha.php` rewritten — 160×60 truecolor image, background/foreground noise, random colour/size/offset/rotation per character, soft row distortion, `Cache-Control: no-store`, and a GD bitmap fallback when no TTF font is available. Still self-hosted, no API keys.
- **Web-server hardening (fix R7):**
    - `src/.htaccess`: `Options -Indexes`, security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP) and denial of `config.example.php`.
    - New `src/includes/.htaccess` denying HTTP access to the internal layer.
    - New `deploy/nginx.example.conf` with the equivalent rules (internal files, `includes/`, hidden files, CAPTCHA no-cache, security headers).
- **CI and static analysis (fix H7):**
    - `composer.json` + `composer.lock` (requires PHP ≥ 8.2, `ext-gd`, `ext-mysqli`; dev dependency `phpstan/phpstan`).
    - `phpstan.neon` at **level 5** over `src/` and `tools/`; no baseline needed.
    - `.github/workflows/ci.yml`: matrix **PHP 8.3/8.4** running `php -l` on every file and PHPStan.
    - Fixed the real issues PHPStan surfaced: `bind_result()` variables that might not be defined (replaced with `get_result()` + `fetch_assoc()` in login/reset flows) and a redundant always-true comparison in `login.php`.
- **New visual style:** complete rewrite of `src/main.css` (design tokens in `:root` / `body.dark-mode`, modern typography, cards for auth forms, modern tables, dark code blocks, responsive layout and `prefers-reduced-motion`). Light and dark modes share the token mechanism; no external fonts or frameworks.
- **Design refinements:** new `#SiteHeader` (title, theme toggle, language selector, auth buttons, search form), "Create New Entry" link above the list, pagination above and below the paste list, copy button no longer floats over the code, code block constrained with `max-width: 100%`, new footer (`#Content` closing + `<footer>` with copyright: "© year author → GitHub, app name + version → repo"). Fixed the missing `dark-mode.js` / `language.js` includes that broke the theme toggle and language selector after moving the controls to the new header.
- **Verification:** rate limits exercised over HTTP (6th attempt blocked for login, paste creation, registration and reset), full register → captcha → reset → login flow, and CAPTCHA headers/image checked (160×60 PNG, `no-store`). `php -l` and PHPStan level 5 pass.

---

## Version 0.7 (2025)
### 🔒 Security & Access Control
- Implemented user roles:
    - Admin: Full access to the admin panel and can manage all pastes.
    - Registered User: Can create, view and delete their own pastes.
    - Guest: Can only create and view pastes.
- Paste deletion is now restricted:
    - Users can only delete their own pastes.
    - Administrators can delete any paste.

- CSRF protection added to paste deletion (drop_id.php).

### 👤 User Authentication & Management
- Added user_id field to the entries table to track paste ownership.
- Login system enhancements:
    - Role is now stored in the session for permission checks.
    - Users without admin privileges can not access admin.php.
- Fixed session-related errors in view.php and drop_id.php.

### 🛠️ Bug Fixes & Improvements
- 🐛 Fix: "user_id cannot be null" error in submit.php
- 🐛 Fix: CSRF validation errors in drop_id.php
- 🐛 Fix: "Invalid ID" error when creating a paste
- 🐛 Fix: Database connection errors in drop_id.php
- 🐛 Fix: Prevented session warnings in view.php

✨ New Features & UI Improvements
- Pastes now display the author and programming language in the entry list.
- Improved entry listing in index.php:
    - The Author and Language columns were added.
    - Displays "Guest" when the paste was created by an anonymous user.

---

## Version 0.6 (2025)
### New Features:
- **Dark Mode** with persistent theme selection using `localStorage`
- **Multi-language Support** with dynamic JSON-based translations (English, Spanish, German, French, Portuguese, and Mandarin)
- **Copy Code Button** in `view.php` for easy clipboard access

### Improvements:
- **Refactored JavaScript**: New modular structure for dark mode, language switching, and copy functionality
- **Enhanced UI**: Better CSS styles for consistency across themes
- **Localization Fixes**: Improved handling of language persistence across pages

---

## Version 0.5 (2025)
### Major Improvements:
- **Full Migration to MySQLi**: Replaced deprecated MySQL functions for modern MySQLi support
- **Security Enhancements**:
  - CSRF protection added for all forms
  - Password hashing using `password_hash()`
  - Session-based authentication for admin pages
  - Improved Input Sanitization to prevent XSS or SQLi attacks
- **Highlight.js Integration**: Replaced old XML-based syntax highlighting with client-side JavaScript
- **UI and UX Enhancements**:
  - Modernized the interface with improved styles
  - Admin panel restructured for better security and usability
- **Code Cleanup**: Removed obsolete files (`highlight.php`, `rule.php`, `sanitize.php`, `xmlparser.php`)

---

## Version 0.4 (2016)
### New Features (suggested by Mr. Jarret Stevens):
- **Short URL Feature Toggle**: Option to enable/disable via `config.php`
- **MD5 Hash-based URLs**: Avoids problematic characters in generated links

### Security Fixes:
- **MySQL Injection Prevention**: Improved validation in `submit.php`

---

## Version 0.3.1 (2014)
### Contributions from Josh (a.k.a. bigbrother) (GitHub):
- **New CSS Layout** for improved styling
- **Revamped Index Page** with better structure
- **New Features**:
  - Reply to code functionality
  - Topic database field added
  - Ability to delete entries by ID
  - Multi-language syntax highlighting (Bash, PHP, Ruby, Python)

---

## Version 0.3 (2010)
### Major Changes:
- **Pascal Syntax Highlighting** added
- **New URL Generation System**: Uses text hashing instead of sequential numbers
- **Short URL Support** via is.gd API (PHP function by David Walsh, a.k.a. darkwing)
- **Bug Fixes**: Minor improvements to the original source code
- **Updated README and Screenshots**

---

## Version 0.2 (2004)
### Improvements by Ville Särkkälä:
- **Code Cleanup**: Improved and cleaned all parts of the code
- **Customizable Syntax Highlighting** via XML configuration
- **Modify and Resubmit Entries** feature added
- **New Highlighting Options**: "None" (no syntax highlighting) and support for C/C++

---

## Version 0.1 (2004)
- **Initial Release by Ville Särkkälä** on SourceForge: Basic framework up and running
