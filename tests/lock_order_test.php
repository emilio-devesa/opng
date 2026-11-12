<?php

/**
 * Lock-order contracts for the write flows (ROB-15).
 *
 * The application uses one global order:
 *   storage_counters -> users -> entries
 *
 * These checks fail if a future change takes the same rows in a different
 * order, which is the precondition for a deadlock between concurrent writes.
 */

$root = dirname(__DIR__);
$failures = [];

function lock_order_source(string $path): string
{
    $source = file_get_contents($path);

    return is_string($source) ? $source : '';
}

function lock_order_expect(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
    }
}

/**
 * True only when every marker exists and appears in the given order.
 *
 * @param list<string> $needles
 */
function lock_order_in_order(string $source, array $needles): bool
{
    $previous = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle);
        if ($position === false || $position <= $previous) {
            return false;
        }
        $previous = $position;
    }

    return true;
}

$submit = lock_order_source($root . '/src/submit.php');
lock_order_expect(
    lock_order_in_order($submit, [
        'global_quota_lock($db)',
        'lock_user_quota($db',
        'thread_parent_for_reply($db',
    ]),
    'submit.php must lock storage_counters, then users, then the parent entry',
    $failures
);

$update = lock_order_source($root . '/src/update.php');
lock_order_expect(
    lock_order_in_order($update, [
        'global_quota_lock($db)',
        'lock_user_quota($db',
        'FROM entries WHERE id = ? FOR UPDATE',
    ]),
    'update.php must lock storage_counters, then users, then the entry',
    $failures
);

$drop = lock_order_source($root . '/src/admin/drop_id.php');
lock_order_expect(
    lock_order_in_order($drop, [
        'global_quota_lock($db)',
        'lock_user_quota($db',
        'FROM entries WHERE id = ? FOR UPDATE',
    ]),
    'drop_id.php must lock storage_counters, then the owner, then the entry',
    $failures
);

$user_action = lock_order_source($root . '/src/admin/user_action.php');
lock_order_expect(
    lock_order_in_order($user_action, [
        'global_quota_lock($db)',
        'SELECT role FROM users WHERE id = ? FOR UPDATE',
        'ORDER BY id ASC FOR UPDATE',
        'DELETE FROM entries',
    ]),
    'user_action.php must lock storage_counters, then users, then entries',
    $failures
);

$empty = lock_order_source($root . '/src/admin/empty.php');
lock_order_expect(
    lock_order_in_order($empty, [
        'global_quota_lock($db)',
        'DELETE FROM entries ORDER BY id LIMIT',
    ]),
    'empty.php must lock storage_counters before deleting entries',
    $failures
);

$architecture = lock_order_source($root . '/docs/ARCHITECTURE.md');
lock_order_expect(
    str_contains($architecture, 'storage_counters → users → entries')
        && str_contains($architecture, 'ROB-15'),
    'docs/ARCHITECTURE.md must document the global lock order',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Lock-order contracts passed: storage_counters -> users -> entries across every write flow.\n";
