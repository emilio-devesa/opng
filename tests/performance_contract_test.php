<?php

$root = dirname(__DIR__);
$failures = [];

function assert_perf(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$submit = file_get_contents($root . '/src/submit.php');
assert_perf(
    is_string($submit)
        && str_contains($submit, "require(\"includes/admin_metrics.php\")")
        && str_contains($submit, 'admin_metrics_invalidate($db)')
        && strpos($submit, 'admin_metrics_invalidate($db)') > strpos($submit, '$db->commit()'),
    'paste creation invalidates the admin dashboard after commit',
    $failures
);

$update = file_get_contents($root . '/src/update.php');
assert_perf(
    is_string($update)
        && str_contains($update, "require(\"includes/admin_metrics.php\")")
        && str_contains($update, 'admin_metrics_invalidate($db)')
        && strpos($update, 'admin_metrics_invalidate($db)') > strpos($update, '$db->commit()'),
    'paste edits invalidate the admin dashboard after commit',
    $failures
);

$role = file_get_contents($root . '/src/includes/account_role.php');
assert_perf(
    is_string($role)
        && !str_contains($role, 'SELECT role')
        && !str_contains($role, 'session_validate_account($db)')
        && str_contains($role, "\$_SESSION['role']"),
    'role reads use the validated session instead of a second users query',
    $failures
);

$admin = file_get_contents($root . '/src/admin/admin.php');
assert_perf(
    is_string($admin)
        && str_contains($admin, '$params[] = $entry_author . \'%\'')
        && str_contains($admin, '$search_pattern = $user_query . \'%\'')
        && !str_contains($admin, '$params[] = \'%\' . $entry_author . \'%\'')
        && !str_contains($admin, '$search_pattern = \'%\' . $user_query . \'%\''),
    'admin searches use prefix patterns instead of leading wildcards',
    $failures
);

$install = file_get_contents($root . '/src/install.php');
assert_perf(
    is_string($install)
        && str_contains($install, 'idx_users_created')
        && str_contains($install, 'idx_users_role')
        && str_contains($install, 'install_index_exists($conn, \'users\''),
    'the installer creates the users date and role indexes idempotently',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Performance contracts passed: metrics invalidation, session role reads, prefix admin search, and users indexes.\n";
