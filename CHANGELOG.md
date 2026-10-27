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
