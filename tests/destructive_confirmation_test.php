<?php

$root = dirname(__DIR__);
$failures = [];

function expect_destructive(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$view = file_get_contents($root . '/src/view.php');
expect_destructive(
    is_string($view)
        && str_contains($view, 'name="confirm_delete"')
        && str_contains($view, 'value="yes"')
        && str_contains($view, 'required'),
    'the paste delete form requires a visible confirmation checkbox',
    $failures
);

$admin = file_get_contents($root . '/src/admin/admin.php');
expect_destructive(
    is_string($admin)
        && str_contains($admin, 'name="confirm_delete"')
        && str_contains($admin, 'name="confirm_action"'),
    'admin entry deletion and user actions require confirmation checkboxes',
    $failures
);

$drop = file_get_contents($root . '/src/admin/drop_id.php');
$confirmation_position = is_string($drop) ? strpos($drop, "\$_POST['confirm_delete']") : false;
$reauth_position = is_string($drop) ? strpos($drop, 'reauthenticate_user(') : false;
expect_destructive(
    $confirmation_position !== false
        && $reauth_position !== false
        && $confirmation_position < $reauth_position
        && str_contains($drop, "!== 'yes'")
        && str_contains($drop, "'error_confirmation_required'"),
    'entry deletion rejects a missing confirmation before any password or deletion work',
    $failures
);

$user_action = file_get_contents($root . '/src/admin/user_action.php');
$confirmation_position = is_string($user_action) ? strpos($user_action, "\$_POST['confirm_action']") : false;
$reauth_position = is_string($user_action) ? strpos($user_action, 'reauthenticate_user(') : false;
expect_destructive(
    $confirmation_position !== false
        && $reauth_position !== false
        && $confirmation_position < $reauth_position
        && str_contains($user_action, "!== 'yes'")
        && str_contains($user_action, "'error_confirmation_required'"),
    'user actions reject a missing confirmation before any password or database work',
    $failures
);

$error_messages = file_get_contents($root . '/src/includes/error_messages.php');
expect_destructive(
    is_string($error_messages) && str_contains($error_messages, 'error_confirmation_required'),
    'the missing-confirmation error is part of the canonical key list',
    $failures
);

foreach (['en', 'es', 'de', 'fr', 'pt', 'zh'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . "/src/assets/lang/{$language}.json"), true);
    expect_destructive(
        is_array($catalog) && is_string($catalog['error_confirmation_required'] ?? null),
        "{$language}.json translates the missing-confirmation error",
        $failures
    );
}

expect_destructive(
    !is_file($root . '/src/assets/js/confirm.js')
        && !str_contains((string) file_get_contents($root . '/src/includes/layout_header.php'), 'confirm.js'),
    'destructive actions no longer depend on a JavaScript-only confirmation',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Destructive confirmation contracts passed.\n";
