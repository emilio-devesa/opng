# Open Pastebin NG - Changelog
============================

## Unreleased (branch v0.8-development)
### 🚨 Security — PHP engine vulnerabilities ("PHP2Shell", October 2026)
- Updated requirements: passing from "PHP 7.4+" to patched PHP releases only (**8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 or higher**), due to:
    - **CVE-2026-109618** — PHP use-after-free in OOM/`longjmp()` handling, leading to RCE (patched 2026-10-21, actively exploited).
    - **CVE-2026-109619** — PHP use-after-free via stale global request state, leading to RCE (patched 2026-10-21, actively exploited).
    - **CVE-2026-115560** — PHP RCE via `session.upload_progress` + runtime `serialize_handler` change (unpatched at the time of writing).
- Added official mitigation for CVE-2026-109619 / CVE-2026-115560 in the docs: `session.upload_progress.enabled = 0` (administratively via `php_admin_flag` when using PHP-FPM). OPNG does not use file uploads, so the feature is disposable at zero cost.
- Shipped hardened `src/.htaccess` for Apache:
    - Blocks direct HTTP access to `config.php`, `database.php` and `rules.xml`.
    - Disables `session.upload_progress` automatically under mod_php.
- Added Nginx equivalent snippet in the README, plus server-side verification commands (`php-fpm -v`, `php-fpm -i`).
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
