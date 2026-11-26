# Performance benchmark (v1.2.0)

This document records the before/after evidence for the phase 10 performance
findings. It is not a capacity plan: the numbers are medians from a synthetic
dataset on one machine, and they exist to show that the hot paths no longer
grow with the account, the table or the paste payload in the way they did
before.

The benchmark is reproducible and only runs against a disposable database.

## How to run it

From the repository root:

```bash
OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 \
OPNG_TEST_DB_HOST=127.0.0.1 \
OPNG_TEST_DB_PORT=8889 \
OPNG_TEST_DB_USER=root \
OPNG_TEST_DB_PASS='your-disposable-password' \
php tools/benchmark_queries.php --rows=20000 --iterations=15
```

The script:

1. creates an `opng_bench_*` database from `database/schema.sql`;
2. inserts the requested number of synthetic entries, a 500-node thread and a
   guest-owned paste of roughly 1 MiB;
3. runs the legacy form and the current production path on the same rows;
4. prints a Markdown table and the relevant `EXPLAIN` plans;
5. drops the database, including on failure.

It refuses to run unless `OPNG_TEST_ALLOW_DATABASE_FIXTURES=1` is set and
explicit disposable credentials are provided. It never reads
`src/config.php`.

## Reference measurement

Environment:

- macOS 27.2.1, Apple M5
- PHP 8.4.17 (MAMP)
- MySQL 8.0.44
- 20,000 synthetic entries, 500 thread nodes, one ~1 MiB paste
- 15 iterations per query (3 for the i18n pass), median reported

| Finding | Previous approach | Current approach | Before | After | Improvement |
|---|---|---|---:|---:|---:|
| PERF-10 | `SUM(OCTET_LENGTH(text))` for the account | `users.used_bytes` counter | 3.917 ms | 0.028 ms | 139.1x |
| PERF-12 | `LEFT JOIN users` + `COALESCE` sort | `entries.author_label` index | 15.788 ms | 0.069 ms | 228.4x |
| PERF-13 | `root_id = ? OR id = ?` | `thread_page_query()` `UNION ALL` | 2.083 ms | 1.370 ms | 1.5x |
| PERF-14 | full-document `DOMDocument` pass | protected `<pre>`/`<textarea>` pass | 19.412 ms | 0.184 ms | 105.5x |
| PERF-17 | dashboard `COUNT(*)`/`SUM()` | global storage counters | 3.611 ms | 0.083 ms | 43.7x |

The benchmark also asserts that the current author listing uses
`idx_entries_author` without a filesort. The viewer-aware listing is covered
too: on the same synthetic dataset, a signed-in author's listing (public entries
plus their own private entries) keeps the date index and does not fall back to a
filesort. The raw plans from that run were:

```text
PERF-12 previous: entries type=ALL key=NULL
                  extra=Using temporary; Using filesort
                  users type=eq_ref key=PRIMARY
PERF-12 current:  entries type=index key=idx_entries_author
                  extra=(none)
PERF-13 previous: entries type=index_merge key=idx_entries_root,PRIMARY
                  extra=Using sort_union(idx_entries_root,PRIMARY);
                        Using where; Using filesort
PERF-13 current:  <derived2> type=ALL key=NULL extra=Using filesort
                  entries type=ref key=idx_entries_root
                  legacy root branch: Impossible WHERE noticed after
                  reading const tables
```

### Interpreting PERF-13

The union does not remove the outer sort: MySQL still filesorts the derived
result. What changed is the amount of work feeding that sort. The previous
`root_id = ? OR id = ?` shape forced an `index_merge`/`sort_union` over
`idx_entries_root` and `PRIMARY`; the current shape uses a plain
`idx_entries_root` range for the descendants and resolves the legacy-root
branch as a const/impossible lookup for a normal self-rooted thread. The sort
is therefore bounded to the rows of the thread page (at most
`OPNG_THREAD_PAGE_SIZE + 1`), not to the table. On the synthetic 500-node
thread the gain is modest (1.5x); on a table with many unrelated roots the
difference is avoiding an index-merge plan that has to combine two access
paths before sorting.

## Frontend checks (PERF-15 / UX-13)

`highlight.js` and the byte meter run in the browser, so they are not part of
the PHP benchmark. The contract test verifies the 200 KiB threshold, the manual
**Highlight** control, the progress/error states and the 120 ms meter debounce.
To check the real rendering cost manually:

1. Create a paste above 200 KiB with a language that has a highlighter (for
   example PHP).
2. Open it with DevTools → Performance and record the initial load.
3. Confirm that the page paints without a long highlight task and that
   **Highlight** is visible.
4. Start another recording and press **Highlight**. The long task should run
   only after the click, and the status should announce `Highlighting…`.
5. Create a paste just below 200 KiB and confirm it still highlights
   automatically after the initial frame.

This is a qualitative check of the intended UX, not a portable timing number.

## Phase 4 changes (v1.3.0)

These findings are structural and covered by contract or integration tests;
they are not query-plan benchmarks.

- **P01 — external backup streaming.** The previous encrypted format built the
  whole ciphertext and its base64 payload in memory. The `OPNGBK2` format
  encrypts and decrypts the dump in 1 MiB frames, and uploads and verification
  run through streams. On a
  32 MiB synthetic dump, measured with `memory_get_peak_usage(true)` under PHP
  8.4.17: a reproduction of the previous one-piece encryption peaked at
  **151.4 MiB**; the new streaming encryption and decryption peaked at
  **10.0 MiB**. Streaming the
  same 32 MiB SQL dump through `schema_split_sql_stream()` peaked at
  **2.0 MiB**. `OPNGBK1` files remain readable.
- **P02 — protection hashing outside locks.** `submit.php` hashes the
  protection password only after rate limiting, CAPTCHA and the private-entry
  check, and `update.php` hashes it before opening the transaction only when the
  session already matches the owner or an administrator. The hash is no longer
  computed while holding `storage_counters`, account or entry locks, and an
  unauthorized request no longer triggers it.
- **P03 — bounded trash purge.** Each thread is deleted in its own transaction.
  A run stops after `OPNG_TRASH_PURGE_BATCH` threads or a time budget and
  reports `remaining`; the next scheduled run resumes the backlog.
- **P04 — admitted API use.** `api_token_authenticate()` no longer writes.
  `last_used_at` is updated only after scope and rate-limit admission and at
  most once per minute per token, so read traffic and rejected requests do not
  turn every call into a row write.

## Non-benchmark findings

- **PERF-11** is a correctness fix (cache invalidation on registration), not a
  query-cost change; it is covered by the contract tests.
- **PERF-16** removes a duplicate language fetch; its evidence is structural
  (`dark-mode.js` has no `fetch()`) and is checked by
  `tests/frontend_contract_test.php`.

## Caveats

- Synthetic rows are shorter and more uniform than real pastes. Compare ratios,
  not absolute milliseconds, across machines.
- Repeated runs on the same machine vary (the sub-millisecond reads moved by
  roughly 20–40% between runs); the ratios are the stable signal.
- The benchmark does not test concurrency. The lock-order tests cover the
  deadlock contract separately.
- MySQL may choose a different plan at a different data size; the benchmark
  asserts the plan for the current author listing and documents the thread
  plan instead of assuming it.
