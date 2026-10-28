<?php

require __DIR__ . '/../src/includes/paste_permissions.php';

function assert_permission(bool $expected, bool $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

assert_permission(true, paste_owner_can_manage(7, 'user', 7), 'users can manage their own pastes');
assert_permission(false, paste_owner_can_manage(7, 'user', 8), "users cannot manage another account's paste");
assert_permission(false, paste_owner_can_manage(7, 'user', null), 'users cannot manage anonymous pastes');
assert_permission(false, paste_owner_can_manage(null, 'guest', 7), 'guests cannot manage account-owned pastes');
assert_permission(true, paste_owner_can_manage(1, 'admin', null), 'admins can manage any paste');
assert_permission(false, paste_owner_can_manage(null, 'admin', null), 'admin role without an account is denied');
assert_permission(false, paste_owner_can_manage(7, 'unknown', 7), 'unknown roles are denied');
