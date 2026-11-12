# Architecture notes

This document records the ordering rules that the write paths depend on. They
are part of the correctness contract, not implementation detail: changing one
flow without the others can reintroduce deadlocks (ROB-15) or quota drift.

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
| `src/admin/drop_id.php` | global counter → entry owner (if any) → entry `FOR UPDATE` → delete |
| `src/admin/user_action.php` | global counter → target user → administrator rows (`ORDER BY id`) → entries |
| `src/admin/empty.php` | global counter → entries (batched delete) |

Rules for new code:

1. Take the global counter before any `users` row.
2. Take `users` rows before any `entries` row.
3. When several `users` rows are locked, always use `ORDER BY id`.
4. Never lock an entry and then go back to `users` or `storage_counters`.
5. Run `audit_log_record()` after `commit()`, never inside the transaction.

The non-locking `SELECT` used to discover an entry owner is not a lock. The row
is re-read with `FOR UPDATE` after the owner and counter are locked, and the
owner is checked again before the update or delete.

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
  before adding columns, indexes or constraints, as `0004_entry_threads.sql`
  does. A migration interrupted halfway can then be re-run safely.
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

## Regression coverage

- `tests/lock_order_test.php`: source-level contract for the order above.
- `tests/audit_log_test.php`: bounded retention and audit-after-commit checks.
- `tests/migration_system_test.php`: SQL splitting and idempotent migration
  markers.
- `tests/database_timeout_test.php`: timeout parsing and documentation.
- `tests/thread_integration_test.php` and the other disposable-database tests
  exercise the real schema and deletion rules.
