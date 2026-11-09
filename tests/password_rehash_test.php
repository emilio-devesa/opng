<?php

$root = dirname(__DIR__);
$failures = [];

function expect_rehash(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$password = 'correct horse battery staple';
$legacy_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
expect_rehash(
    is_string($legacy_hash) && password_verify($password, $legacy_hash),
    'the synthetic legacy hash verifies correctly',
    $failures
);
expect_rehash(
    password_needs_rehash($legacy_hash, PASSWORD_DEFAULT),
    'a low-cost bcrypt hash is flagged for rehashing',
    $failures
);

$current_hash = password_hash($password, PASSWORD_DEFAULT);
expect_rehash(
    is_string($current_hash) && !password_needs_rehash($current_hash, PASSWORD_DEFAULT),
    'a freshly generated default hash is current',
    $failures
);
expect_rehash(
    password_verify($password, $current_hash),
    'the rehashed password still verifies',
    $failures
);

$login = file_get_contents($root . '/src/auth/login.php');
expect_rehash(
    is_string($login)
        && str_contains($login, 'password_needs_rehash($user[\'password_hash\'], PASSWORD_DEFAULT)')
        && str_contains($login, 'UPDATE users SET password_hash = ? WHERE id = ?')
        && strpos($login, 'password_needs_rehash') > strpos($login, 'password_verify'),
    'login rehashes a verified legacy hash before opening the session',
    $failures
);

$register = file_get_contents($root . '/src/auth/register.php');
expect_rehash(
    is_string($register) && str_contains($register, 'password_hash($password, PASSWORD_DEFAULT)'),
    'registration uses the current default password algorithm',
    $failures
);

foreach (['change_password.php', 'reset_password.php'] as $path) {
    $source = file_get_contents($root . '/src/auth/' . $path);
    expect_rehash(
        is_string($source) && str_contains($source, 'PASSWORD_DEFAULT'),
        "{$path} uses the current default password algorithm",
        $failures
    );
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Password hashing contracts passed: legacy hashes are rehashed on login and new hashes use PASSWORD_DEFAULT.\n";
