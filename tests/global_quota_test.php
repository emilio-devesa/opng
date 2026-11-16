<?php

require __DIR__ . '/../src/includes/user_quota.php';

$root = dirname(__DIR__);

function assert_global_quota(bool $expected, bool $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

assert_global_quota(true, global_quota_allows(0, 1024, 2048), 'a paste fits under the global quota');
assert_global_quota(true, global_quota_allows(1024, 1024, 2048), 'the global quota is inclusive at its boundary');
assert_global_quota(false, global_quota_allows(2048, 1, 2048), 'a paste over the global quota is rejected');
assert_global_quota(false, global_quota_allows(-1, 1, 2048), 'negative usage is rejected');
assert_global_quota(false, global_quota_allows(0, -1, 2048), 'negative paste size is rejected');

putenv('OPNG_STORAGE_QUOTA_BYTES=12345');
assert_global_quota(true, global_storage_quota_bytes() === 12345, 'the quota can be configured with OPNG_STORAGE_QUOTA_BYTES');

putenv('OPNG_STORAGE_QUOTA_BYTES=not-a-number');
assert_global_quota(
    true,
    global_storage_quota_bytes() === OPNG_DEFAULT_GLOBAL_QUOTA_BYTES,
    'invalid quota values fall back to the 10 GiB default'
);

putenv('OPNG_STORAGE_QUOTA_BYTES');
assert_global_quota(
    true,
    global_storage_quota_bytes() === OPNG_DEFAULT_GLOBAL_QUOTA_BYTES,
    'the default global quota is 10 GiB'
);

foreach ([
    'global_quota_lock',
    'global_quota_consume',
    'global_quota_zero',
    'global_quota_recount',
    'global_entry_usage',
    'global_entry_consume',
    'user_quota_consume',
    'user_quota_zero_all',
    'user_quota_recount',
] as $function) {
    assert_global_quota(true, function_exists($function), "{$function}() is available");
}

$quota_source = file_get_contents($root . '/src/includes/user_quota.php');
$lock_function = '';
if (is_string($quota_source)) {
    $lock_start = strpos($quota_source, 'function lock_user_quota');
    $lock_end = $lock_start === false ? false : strpos($quota_source, 'function user_quota_consume', $lock_start);
    if ($lock_start !== false && $lock_end !== false) {
        $lock_function = substr($quota_source, $lock_start, $lock_end - $lock_start);
    }
}
assert_global_quota(
    true,
    $lock_function !== '' && !str_contains($lock_function, 'SUM('),
    'locking an account reads users.used_bytes instead of summing its entries (PERF-10)'
);

$submit = file_get_contents($root . '/src/submit.php');
$update = file_get_contents($root . '/src/update.php');
assert_global_quota(
    true,
    is_string($submit)
        && str_contains($submit, 'global_quota_lock')
        && str_contains($submit, 'global_quota_consume')
        && !str_contains($submit, 'global_storage_usage'),
    'paste creation uses the transactional counter instead of SUM()'
);
assert_global_quota(
    true,
    is_string($update)
        && str_contains($update, 'global_quota_lock')
        && str_contains($update, 'global_quota_consume')
        && str_contains($update, 'error_global_storage_quota'),
    'paste edits check and update the global counter'
);
foreach ([
    '/src/admin/empty.php' => 'global_quota_zero',
    '/src/includes/trash.php' => 'global_quota_consume',
    '/src/admin/user_action.php' => 'global_quota_consume',
] as $path => $marker) {
    $source = file_get_contents($root . $path);
    assert_global_quota(
        true,
        is_string($source) && str_contains($source, $marker),
        "{$path} keeps the global counter in sync"
    );
}
$install = file_get_contents($root . '/src/install.php');
assert_global_quota(
    true,
    is_string($install) && str_contains($install, 'storage_counters'),
    'the installer creates the storage counter table'
);
assert_global_quota(
    true,
    is_string($install)
        && str_contains($install, 'used_entries')
        && str_contains($install, 'author_label'),
    'the installer seeds the entry counter and author label columns'
);

foreach ([
    '/src/submit.php' => ['global_entry_consume', 'user_quota_consume'],
    '/src/update.php' => ['user_quota_consume'],
    '/src/includes/trash.php' => ['global_entry_consume', 'user_quota_consume'],
    '/src/admin/user_action.php' => ['global_entry_consume', 'used_bytes'],
    '/src/admin/empty.php' => ['user_quota_zero_all'],
] as $path => $markers) {
    $source = file_get_contents($root . $path);
    $found = is_string($source);
    foreach ($markers as $marker) {
        $found = $found && str_contains($source, $marker);
    }
    assert_global_quota(true, $found, "{$path} maintains the denormalized counters");
}

$seed = file_get_contents($root . '/tools/seed.php');
assert_global_quota(
    true,
    is_string($seed)
        && str_contains($seed, 'author_label')
        && str_contains($seed, 'global_quota_recount')
        && str_contains($seed, 'user_quota_recount'),
    'tools/seed.php writes author labels and reconciles both counters'
);
$recount = $root . '/tools/recount_storage.php';
$refresh = $root . '/tools/metrics_refresh.php';
assert_global_quota(true, is_file($recount) && is_file($refresh), 'counter and metrics maintenance tools are installed');
$recount_source = is_file($recount) ? file_get_contents($recount) : false;
assert_global_quota(
    true,
    is_string($recount_source)
        && str_contains($recount_source, '--check')
        && str_contains($recount_source, 'global_quota_lock'),
    'tools/recount_storage.php verifies drift and takes the global lock first'
);
$metrics = file_get_contents($root . '/src/includes/admin_metrics.php');
assert_global_quota(
    true,
    is_string($metrics)
        && str_contains($metrics, 'storage_counters')
        && str_contains($metrics, 'GROUP BY language'),
    'admin metrics read the counters and group by the indexed language column'
);
