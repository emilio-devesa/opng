<?php

require __DIR__ . '/../src/includes/audit_log.php';

$root = dirname(__DIR__);
$failures = [];

if (!function_exists('audit_log_record') || !function_exists('audit_log_ip_hash')) {
    $failures[] = 'The audit log helpers are missing';
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$first_hash = audit_log_ip_hash();
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$second_hash = audit_log_ip_hash();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
if (
    strlen($first_hash) !== 64
    || preg_match('/\A[0-9a-f]{64}\z/', $first_hash) !== 1
    || $first_hash === $second_hash
    || $first_hash !== audit_log_ip_hash()
) {
    $failures[] = 'The audit log IP hash must be a stable SHA-256 digest';
}

$install = file_get_contents($root . '/src/install.php');
if (!is_string($install) || !str_contains($install, 'CREATE TABLE IF NOT EXISTS audit_log')) {
    $failures[] = 'The installer does not create the audit_log table';
}

foreach ([
    'src/admin/drop_id.php' => 'entry.delete',
    'src/admin/empty.php' => 'database.empty',
    'src/admin/user_action.php' => 'user.role',
    'src/admin/backup.php' => 'backup.download',
] as $path => $action) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || !str_contains($source, 'audit_log_record(') || !str_contains($source, $action)) {
        $failures[] = "Missing audit logging in {$path}";
    }
}

$admin = file_get_contents($root . '/src/admin/admin.php');
if (
    !is_string($admin)
    || !str_contains($admin, 'FROM audit_log')
    || !str_contains($admin, 'data-i18n="admin_audit_log"')
    || !str_contains($admin, 'LIMIT 20')
) {
    $failures[] = 'The admin panel does not render the latest audit entries';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Audit log contracts passed: schema, IP hashing, action logging, and admin rendering.\n";
