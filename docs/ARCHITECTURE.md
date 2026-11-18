# Architecture notes

This document records the ordering rules that the write paths depend on. They
are part of the correctness contract, not implementation detail: changing one
flow without the others can reintroduce deadlocks (ROB-15) or quota drift.

## Entry points and bootstrap

Web entry points load `src/bootstrap.php` once. It registers a lightweight
autoloader for `Opng\` classes (`src/classes/`) and loads the foundation
libraries: version/requirements, session, input validation, error messages,
i18n, logging, assets, CSRF, icons, hashing and the database helper. Domain
modules (auth, MFA, captcha, pagination, backups, ...) stay in the entry point
that needs them, so the load order is explicit without repeating the same
foundation list in every file. The legacy `src/database.php` wrapper was
removed; `database_connect()` lives in `src/includes/db.php` and never runs DDL.

## Global lock order

Every web flow that writes more than one row takes InnoDB row locks in this
order:

```
storage_counters → users → entries
```

The `storage_counters` row with `counter_key = 'global'` is the first lock in
all paste-writing and destructive admin flows. It therefore doubles as the
global write mutex: a transaction that holds it cannot race a paste insertion,
an edit, an admin deletion or an account-role change.

| Flow | Locks, in order |
|---|---|
| `src/submit.php` | global counter → account (if signed in) → parent entry (if reply) → insert |
| `src/update.php` | global counter → paste owner (if any) → entry `FOR UPDATE` → update |
| `src/admin/drop_id.php` | global counter → entry owner (if any) → entry `FOR UPDATE` → soft-delete the whole thread |
| `src/admin/user_action.php` | global counter → target user → administrator rows (`ORDER BY id`) → entries |
| `src/admin/empty.php` | global counter → every `users` counter → entries (batched delete) |
| `src/includes/trash.php` purge | global counter → affected users (`ORDER BY id`) → trashed entries (batched delete) |

Rules for new code:

1. Take the global counter before any `users` row.
2. Take `users` rows before any `entries` row.
3. When several `users` rows are locked, always use `ORDER BY id`.
4. Never lock an entry and then go back to `users` or `storage_counters`.
5. Run `audit_log_record()` after `commit()`, never inside the transaction.

The non-locking `SELECT` used to discover an entry owner is not a lock. The row
is re-read with `FOR UPDATE` after the owner and counter are locked, and the
owner is checked again before the update or delete.

## Denormalized storage counters

The request path never aggregates `entries` to enforce a quota or to draw the
dashboard:

| Value | Column | Maintained by |
|---|---|---|
| Global paste bytes | `storage_counters.used_bytes` (`counter_key = 'global'`) | `global_quota_consume()` |
| Global entry count | `storage_counters.used_entries` (`counter_key = 'global'`) | `global_entry_consume()` |
| Account paste bytes | `users.used_bytes` | `user_quota_consume()` |

The byte and entry deltas are applied in the same transaction as the entry
write, after the rows from the lock order have been taken. Negative deltas are
clamped at zero so a reconciliation mistake cannot produce a negative usage.
`lock_user_quota()` reads `users.used_bytes` and the username from the locked
account row instead of running `SUM(OCTET_LENGTH(text))` per write (PERF-10).

Bulk paths that bypass the normal write flow (`tools/seed.php`, manual imports)
must rebuild the counters. `tools/recount_storage.php` recalculates every value
from the authoritative `entries` table and takes the global counter lock first;
`--check` reports drift without changing anything and exits non-zero, so it can
run from cron or monitoring.

The admin dashboard reads the same counters for the totals. It only falls back
to `COUNT(*)`/`SUM()` when the global counter row is missing, which keeps a
recovery path without paying the aggregate on every refresh (PERF-17).
`tools/metrics_refresh.php` can warm the dashboard cache from cron.

The before/after measurements for these paths (and the i18n and listing
changes below) are recorded in [`PERFORMANCE.md`](PERFORMANCE.md) and can be
reproduced with `php tools/benchmark_queries.php` against a disposable
database.

## Listing and history query shapes

- `entries.author_label` is the denormalized display author (username or
  `Guest`). The global listing orders by `entries.author_label` through
  `idx_entries_author (author_label, id)` and does not join `users` (PERF-12).
  The label is written on insert, copied on edit, backfilled by migration
  `0006_entry_author_label.sql` and reset to `Guest` when an account is deleted
  with the preserve option. v1.2 has no username-change feature, so no rename
  maintenance is required.
- `thread_page_query()` builds the production history SQL as
  `UNION ALL` of the `root_id` branch and the legacy-root branch
  (`id = ? AND (root_id IS NULL OR root_id <> ?)`). Each branch uses its own
  index instead of the previous `root_id = ? OR id = ?` index-merge plan, and
  the outer sort only sees the rows that belong to the thread (PERF-13). The
  root branch excludes the normal case where the root already belongs to the
  first branch, so it never duplicates a row.
- Admin entry search still joins `users` when filtering by author prefix; that
  query is admin-only and benefits from the unique username index.

## Transaction boundaries

- Paste writes, edits, entry deletion and account actions use one transaction
  for the entry change and the counter update.
- Audit logging is best-effort and runs after the business transaction commits
  (ROB-20). A failed audit insert never rolls back the action, and the
  opportunistic retention `DELETE` cannot extend a business lock.
- `admin/empty.php` deletes entries in batches of 1,000 (`DELETE ... ORDER BY
  id LIMIT 1000`) inside one transaction while holding the global counter. The
  transaction stays atomic, but no single statement processes the whole table.

## Audit retention

`audit_log_prune()` removes at most one bounded batch (1,000 rows by default)
and is safe for the occasional request-time call. `audit_log_prune_all()` drains
the backlog in batches and is used by `tools/audit_prune.php` from cron. Both
rely on `idx_audit_created (created_at, id)`.

## Schema migrations

- `schema_split_sql()` understands single/double-quoted strings, backtick
  identifiers, `--`/`#` line comments and block comments. A semicolon inside a
  literal or identifier does not split a statement (ROB-18).
- Migrations are applied in filename order and recorded in `schema_migrations`.
- MySQL DDL commits implicitly, so a migration cannot be rolled back as a unit.
  Each migration must therefore be idempotent: check `information_schema`
  before adding columns, indexes or constraints, as `0004_entry_threads.sql`,
  `0005_storage_counters.sql` and `0006_entry_author_label.sql` do. A migration
  interrupted halfway can then be re-run safely.
- `0005_storage_counters` backfills `users.used_bytes` and
  `storage_counters.used_entries`; `0006_entry_author_label` backfills
  `entries.author_label` from `users.username`.
- `0007_admin_mfa` adds the encrypted TOTP secret, enrollment timestamp and
  recovery-code table; `0008_entry_retention` adds visibility, expiry,
  protection and soft-delete columns plus their indexes; `0009_api_tokens` adds
  the hashed personal-token table. All three check `information_schema` first and
  are safe to replay.
- `DELIMITER` is a native-client command, not SQL. Do not put stored routines
  with embedded semicolons in a migration file; apply those with `mysql` or
  `mariadb` and document the manual step.

## Database timeouts

`db_connect()` configures `MYSQLI_OPT_CONNECT_TIMEOUT` from
`OPNG_DB_CONNECT_TIMEOUT` (default 5 seconds, range 1–60). This prevents a
non-responsive MySQL server from exhausting the PHP-FPM worker pool (ROB-19).
`OPNG_DB_READ_TIMEOUT` optionally bounds an individual query (range 1–3600);
leave it unset unless it is above the slowest expected operation, such as a
large admin backup.

## Phase 13 components

- **Mail and recovery** (`includes/mailer.php`, `mail_templates.php`,
  `password_reset.php`): a native SMTP client with STARTTLS/implicit TLS and
  AUTH, PHP `mail()` for local sendmail, and a development log transport. The
  doctor validates the selected transport before the login page advertises
  recovery. Reset tokens are hashed and short-lived; the local simulation is
  loopback-only.
- **MFA** (`includes/mfa.php`, `auth/mfa.php`, `auth/mfa_enroll.php`): RFC 6238
  TOTP, AES-256-GCM secret storage under `OPNG_MFA_KEY`, hashed one-time
  recovery codes, mandatory enrollment for the lowest-id administrator and an
  MFA challenge folded into destructive reauthentication. Enrollment, disable
  and recovery-code replacement run in transactions.
- **Trash and retention** (`includes/trash.php`, `auth/trash*.php`,
  `admin/trash*.php`, `tools/trash_purge.php`): whole-thread soft delete,
  restore, an immediate administrator purge that refuses live rows, and a
  scheduled purge that releases the global/account counters only after the rows
  are deleted. Counters stay charged while a thread is in the trash.
- **Visibility and protection** (`includes/entry_access.php`, `src/unlock.php`):
  server-side public/unlisted/private checks, absolute expiry, Argon2id password
  protection and session unlock per thread root. Replies copy the parent's
  access fields; editing a root propagates them to the thread.
- **API tokens** (`includes/api_tokens.php`, `src/api/v1/`): SHA-256 token
  storage, `read`/`write` scopes, expiry, revocation, per-token rate limits and
  a read-only v1 router that reuses the public visibility filter.
- **Observability** (`includes/logging.php`, `admin/audit.php`): single-line JSON
  logs with request IDs, an audit viewer with filters and a rate-limit/quota
  panel.
- **External backups** (`includes/external_backup.php`,
  `admin/external_backup*.php`, `tools/backup_external.php`): local or
  S3-compatible targets, SHA-256 checksums, retention, optional local
  AES-256-GCM encryption and disposable-schema restore verification.

## Error documents and UI polish

`src/404.php` and `src/500.php` render the shared layout and the localized
catalog without touching the database. Apache (`ErrorDocument`) and the
container Nginx (`error_page`/`try_files`) route unmatched URLs and server
errors to them. Header actions use decorative inline SVG (`includes/icons.php`)
instead of emoji, and the code view offers a three-step font-size control that
persists a CSS class in `localStorage`; `@media print` removes navigation and
controls so pastes print cleanly.

## Regression coverage

- `tests/lock_order_test.php`: source-level contract for the order above.
- `tests/lock_order_integration_test.php`: two connections prove the order and
  wait behaviour on a disposable schema.
- `tests/write_concurrency_integration_test.php`: behavioural coverage for a
  quota race, concurrent edits, a double delete and a role change; it fails on
  a deadlock, a lost update or counter drift.
- `tests/audit_log_test.php`: bounded retention and audit-after-commit checks.
- `tests/migration_system_test.php`: SQL splitting and idempotent migration
  markers.
- `tests/database_timeout_test.php`: timeout parsing and documentation.
- `tests/thread_integration_test.php` and the other disposable-database tests
  exercise the real schema and deletion rules.
- `tests/mfa_test.php`, `tests/entry_visibility_test.php`,
  `tests/external_backup_test.php`, `tests/api_contract_test.php` and
  `tests/frontend_phase13_test.php` cover the Phase 13 helpers and frontend
  contracts without a database.
- `tests/trash_integration_test.php` and `tests/api_token_integration_test.php`
  exercise the trash/counter and token lifecycles on disposable schemas.
