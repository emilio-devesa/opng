<?php

require __DIR__ . '/../src/includes/reauth.php';

$root = dirname(__DIR__);
$failures = [];

function expect_reauth(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$db = mysqli_init();
expect_reauth(
    !reauthenticate_user($db, 1, null, 'test_reauth'),
    'a missing password is rejected before any database work',
    $failures
);
expect_reauth(
    !reauthenticate_user($db, 1, '', 'test_reauth'),
    'an empty password is rejected before any database work',
    $failures
);
expect_reauth(
    function_exists('reauthenticate_user')
        && str_contains((string) file_get_contents($root . '/src/includes/reauth.php'), 'OPNG_REAUTH_LIMIT')
        && str_contains((string) file_get_contents($root . '/src/includes/reauth.php'), 'password_verify($password, $hash)')
        && str_contains((string) file_get_contents($root . '/src/includes/reauth.php'), 'rate_limit_hit('),
    'confirmation verifies the current password and limits failed attempts',
    $failures
);

foreach ([
    '/src/admin/backup.php' => 'admin_reauth_backup',
    '/src/admin/empty.php' => 'admin_reauth_empty',
    '/src/admin/user_action.php' => 'admin_reauth_user',
    '/src/admin/drop_id.php' => 'entry_delete',
] as $path => $action) {
    $source = file_get_contents($root . $path);
    expect_reauth(
        is_string($source)
            && str_contains($source, "require_once('../includes/reauth.php')")
            && str_contains($source, 'reauthenticate_user($db')
            && str_contains($source, "'{$action}'")
            && str_contains($source, "opng_error_redirect('error_reauth'")
            && str_contains($source, 'csrf_valid('),
        "{$path} requires CSRF and password confirmation before acting",
        $failures
    );
}

foreach ([
    '/src/admin/admin.php' => ['drop_current_password', 'user_action_current_password', 'backup_current_password'],
    '/src/admin/empty.php' => ['empty_current_password'],
    '/src/view.php' => ['delete_current_password'],
] as $path => $ids) {
    $source = file_get_contents($root . $path);
    foreach ($ids as $id) {
        expect_reauth(
            is_string($source)
                && str_contains($source, 'name="current_password"')
                && str_contains($source, 'id="' . $id . '"')
                && str_contains($source, 'autocomplete="current-password"'),
            "{$path} renders the {$id} confirmation field",
            $failures
        );
    }
}

foreach (['en', 'es', 'de', 'fr', 'pt', 'zh'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . "/src/assets/lang/{$language}.json"), true);
    expect_reauth(
        is_array($catalog)
            && is_string($catalog['reauth_password'] ?? null)
            && is_string($catalog['error_reauth'] ?? null),
        "{$language}.json defines the confirmation label and error",
        $failures
    );
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Reauthentication contracts passed: every destructive admin action requires the current password.\n";
