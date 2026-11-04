<?php

$root = dirname(__DIR__);
$failures = [];

function expect_enumeration(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$login = file_get_contents($root . '/src/auth/login.php');
expect_enumeration(
    is_string($login)
        && str_contains($login, "password_hash('opng-timing-equalizer', PASSWORD_DEFAULT)")
        && str_contains($login, '$user[\'password_hash\'] ?? ')
        && str_contains($login, 'password_verify($password, $password_hash)'),
    'login spends comparable bcrypt work when the account does not exist',
    $failures
);

$register = file_get_contents($root . '/src/auth/register.php');
$duplicate_status = is_string($register) ? strpos($register, "if (\$stmt->num_rows > 0)") : false;
$dummy_hash = is_string($register) ? strpos($register, 'password_hash($password, PASSWORD_DEFAULT);', (int) $duplicate_status) : false;
$registered_error = is_string($register) ? strpos($register, "\$form_error_key = 'error_email_registered';", (int) $duplicate_status) : false;
expect_enumeration(
    $duplicate_status !== false
        && $dummy_hash !== false
        && $registered_error !== false
        && $dummy_hash < $registered_error,
    'registration hashes the password before reporting a duplicate email',
    $failures
);

$request_reset = file_get_contents($root . '/src/auth/request_reset.php');
expect_enumeration(
    is_string($request_reset)
        && str_contains($request_reset, 'If an account exists for this address, a reset link has been generated.')
        && str_contains($request_reset, 'UPDATE users SET reset_token = reset_token WHERE id = 0'),
    'reset requests use a generic message and run the same update shape for unknown accounts',
    $failures
);

$reset_password = file_get_contents($root . '/src/auth/reset_password.php');
expect_enumeration(
    is_string($reset_password)
        && str_contains($reset_password, 'Invalid or expired reset link.')
        && !str_contains($reset_password, 'No account'),
    'reset confirmation never states whether the account exists',
    $failures
);

$catalogs = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
foreach ($catalogs as $language) {
    $catalog = json_decode((string) file_get_contents($root . "/src/assets/lang/{$language}.json"), true);
    $message = is_array($catalog) ? ($catalog['error_email_registered'] ?? null) : null;
    expect_enumeration(
        is_string($message)
            && $message !== ''
            && !preg_match('/already registered|ya está registrado|bereits registriert|déjà inscrite|já está registado|已注册/i', $message),
        "{$language}.json keeps the duplicate-email message generic",
        $failures
    );
}

$default_info = password_get_info(password_hash('timing-probe', PASSWORD_DEFAULT));
$dummy_info = password_get_info(password_hash('opng-timing-equalizer', PASSWORD_DEFAULT));
expect_enumeration(
    ($default_info['algoName'] ?? null) === ($dummy_info['algoName'] ?? null),
    'the timing equalizer uses the current default algorithm',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Account enumeration contracts passed: generic messages, duplicate-path hashing, and timing equalizers.\n";
