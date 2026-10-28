# Contributing to Open Pastebin NG

Thank you for helping improve Open Pastebin NG. Contributions can include bug reports, improvement ideas, documentation, translations, tests, and code.

## How to Contribute

### Report issues

Open an issue in the [GitHub issue tracker](https://github.com/emilio-devesa/opng/issues). Include:

- A clear summary and the expected and actual behavior.
- Steps to reproduce, including sample input where it is safe to share.
- The affected OPNG revision, PHP version and SAPI, database product/version, and browser when relevant.
- Relevant error messages or logs with credentials, session values, personal data, and paste contents removed.

Search existing issues first, and add information to an existing report if it describes the same problem. Do not publish credentials or sensitive security details in a public issue.

### Suggest improvements

Open an issue describing the problem or use case, the proposed behavior, and any relevant accessibility, security, or compatibility considerations. For substantial or user-visible changes, discuss the approach before investing in a large implementation.

### Submit code

1. Fork the repository and create a focused branch from the appropriate base branch. Use `main` unless the maintainers specify another target.
2. Make a small, coherent change that follows the existing patterns. Include or update tests, documentation, and all six translation catalogs where relevant.
3. Run the checks listed in [Development setup and repository structure](#development-setup-and-repository-structure).
4. Open a pull request describing the motivation, implementation, tests run, and any deployment or migration implications. Link the related issue and include screenshots for visible UI changes when useful.
5. Keep the change reviewable; avoid bundling unrelated formatting or refactoring.

Never commit `src/config.php`, credentials, production data, or other secrets. The repository intentionally ignores the local config file.

## Communication

- Use GitHub issues for reproducible bugs and improvement proposals, and pull request discussions for implementation and review.
- Be respectful, constructive, and specific. Explain disagreements with technical context and stay open to feedback.
- Do not include private user data, real paste contents, passwords, tokens, or database dumps in issues, pull requests, screenshots, or logs.
- For a suspected security vulnerability, do not publish exploit details or sensitive information in a public issue. Use GitHub's private vulnerability reporting for this repository if it is enabled; otherwise contact the maintainers privately through the repository's GitHub page.

## Development setup and repository structure

### Local setup

Requirements are PHP on a supported patch level, Composer, MySQL or MariaDB, and the `mysqli` and `gd` PHP extensions. A local Apache/Nginx setup such as MAMP is suitable. Check both the CLI PHP and the PHP runtime used by the web server.

1. Clone the repository and install development dependencies:

   ```bash
   git clone https://github.com/emilio-devesa/opng.git
   cd opng
   composer install
   ```

2. Create a local database and configure a dedicated runtime account. Copy `src/config.example.php` to `src/config.php` and enter local credentials, or set the documented `OPNG_DB_*` environment variables. Do not use production credentials or data.

3. Create the schema using the separate migration account and CLI installer, as described in the [installation guide](README.md#-installation). Point the web server document root at `src/`, not the repository root.

4. Run static checks:

   ```bash
   composer lint
   composer phpstan
   ```

5. Run local regression tests that do not modify the database:

   ```bash
   php tests/password_reset_security_test.php
   php tests/input_validation_test.php
   php tests/http_input_test.php
   php tests/search_pagination_test.php
   php tests/session_security_test.php
   php tests/permissions_test.php
   php tests/sql_backup_test.php
   php tests/frontend_contract_test.php
   ```

   The database installation and query-plan integration tests create or modify fixtures, including a 5,000-row dataset. Run them only against a disposable database and only with the explicit opt-in environment variables documented in the test files and CI workflow. Never run them against a shared or production database.

6. `tools/seed.php` is a CLI-only development seeder. It deletes its own previous fixtures (`seed%` entry IDs and accounts in `@example.test`) and creates accounts with the known development password `Password123!`. Review the target database first; never run it in production or against data you need to keep.

### Repository structure

| Path | Purpose |
| --- | --- |
| `src/` | Web document root and application entry points. |
| `src/admin/` | Administrator panel, user/entry actions, backup, and database guidance. |
| `src/auth/` | Login, registration, logout, CAPTCHA, and password-reset flows. |
| `src/includes/` | Shared database, session, CSRF, validation, permissions, pagination, and layout helpers. |
| `src/assets/` | CSS, JavaScript, six translation catalogs, and locally served vendor assets. |
| `src/install.php` | CLI-only database schema installer and upgrade routine. |
| `tests/` | Regression tests and isolated MySQL integration tests. |
| `tools/` | CLI development tools, including the sample-data seeder. |
| `deploy/` | Example deployment configuration, including Nginx. |
| `.github/workflows/` | CI lint, static analysis, regression checks, and isolated database jobs. |
| `screenshots/` | Project screenshots used by the README. |

For application requirements, production deployment, and database privileges, see the [README](README.md).
