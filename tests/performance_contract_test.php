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

$benchmark = file_get_contents($root . '/tools/benchmark_queries.php');
assert_perf(
    is_string($benchmark)
        && str_contains($benchmark, "PHP_SAPI !== 'cli'")
        && str_contains($benchmark, 'OPNG_TEST_ALLOW_DATABASE_FIXTURES')
        && str_contains($benchmark, 'thread_page_query')
        && str_contains($benchmark, 'opng_i18n_apply'),
    'the phase 10 benchmark is CLI-only, opt-in and exercises the production helpers',
    $failures
);

$hashPosition = is_string($submit) ? strpos($submit, 'entry_protection_hash($protection_password)') : false;
$ratePosition = is_string($submit) ? strpos($submit, 'rate_limit_hit(') : false;
$submitTransaction = is_string($submit) ? strpos($submit, '$db->begin_transaction()') : false;
assert_perf(
    $hashPosition !== false
        && $ratePosition !== false
        && $submitTransaction !== false
        && $hashPosition > $ratePosition
        && $hashPosition < $submitTransaction,
    'paste creation hashes the protection password after admission and outside the transaction',
    $failures
);

$updateHash = is_string($update) ? strpos($update, 'entry_protection_hash($protection_password)') : false;
$updateTransaction = is_string($update) ? strpos($update, '$db->begin_transaction()') : false;
$updatePermission = is_string($update) ? strpos($update, 'paste_owner_can_manage(') : false;
assert_perf(
    $updateHash !== false
        && $updateTransaction !== false
        && $updatePermission !== false
        && $updatePermission < $updateHash
        && $updateHash < $updateTransaction,
    'paste edits hash only for a plausible owner/admin and before the transaction and its locks',
    $failures
);

$apiTokens = file_get_contents($root . '/src/includes/api_tokens.php');
$authenticateStart = is_string($apiTokens) ? strpos($apiTokens, 'function api_token_authenticate') : false;
$authenticateEnd = is_string($apiTokens) ? strpos($apiTokens, 'function api_token_touch') : false;
assert_perf(
    $authenticateStart !== false
        && $authenticateEnd !== false
        && !str_contains(
            substr($apiTokens, $authenticateStart, $authenticateEnd - $authenticateStart),
            'UPDATE api_tokens'
        )
        && str_contains($apiTokens, 'INTERVAL 60 SECOND'),
    'bearer authentication does not write; admitted use is recorded separately and throttled',
    $failures
);

$apiIndex = file_get_contents($root . '/src/api/v1/index.php');
$apiRatePosition = is_string($apiIndex) ? strpos($apiIndex, 'rate_limit_hit(') : false;
$apiTouchPosition = is_string($apiIndex) ? strpos($apiIndex, 'api_token_touch(') : false;
assert_perf(
    $apiRatePosition !== false
        && $apiTouchPosition !== false
        && $apiTouchPosition > $apiRatePosition,
    'the API records last use only after the rate limiter admits the request',
    $failures
);

$externalBackup = file_get_contents($root . '/src/includes/external_backup.php');
assert_perf(
    is_string($externalBackup)
        && str_contains($externalBackup, 'function external_backup_encrypt_stream')
        && str_contains($externalBackup, 'function external_backup_decrypt_stream')
        && str_contains($externalBackup, 'function external_backup_s3_put_stream')
        && str_contains($externalBackup, 'function external_backup_verify_stream'),
    'external backups encrypt, decrypt, upload and verify through streams',
    $failures
);

$trash = file_get_contents($root . '/src/includes/trash.php');
assert_perf(
    is_string($trash)
        && str_contains($trash, '$maxSeconds')
        && str_contains($trash, "'remaining' => \$remaining"),
    'the trash purge is bounded by time and reports the remaining backlog',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Performance contracts passed: metrics invalidation, session role reads, prefix admin search, users indexes, and the phase 10 benchmark.\n";
