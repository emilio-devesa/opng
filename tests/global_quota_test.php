<?php

require __DIR__ . '/../src/includes/user_quota.php';

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

foreach (['global_quota_lock', 'global_quota_consume', 'global_quota_zero', 'global_quota_recount'] as $function) {
    assert_global_quota(true, function_exists($function), "{$function}() is available");
}

$root = dirname(__DIR__);
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
    '/src/admin/drop_id.php' => 'global_quota_consume',
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
