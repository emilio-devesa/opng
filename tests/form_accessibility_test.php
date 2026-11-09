<?php

$root = dirname(__DIR__);
$failures = [];

function expect_form_a11y(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

$forms = [
    '/src/auth/login.php',
    '/src/auth/register.php',
    '/src/auth/change_password.php',
    '/src/auth/reset_password.php',
    '/src/auth/request_reset.php',
];

foreach ($forms as $path) {
    $source = file_get_contents($root . $path);
    expect_form_a11y(
        is_string($source)
            && str_contains($source, 'id="form-error"')
            && str_contains($source, 'aria-invalid="true"')
            && str_contains($source, 'form-error')
            && str_contains($source, 'autofocus'),
        "{$path} associates inline errors with its fields and focuses the first invalid field",
        $failures
    );
}

$login = file_get_contents($root . '/src/auth/login.php');
expect_form_a11y(
    is_string($login)
        && str_contains($login, '$posted_email')
        && str_contains($login, 'value="<?php echo htmlspecialchars($posted_email'),
    'login preserves the submitted email after a failed sign-in',
    $failures
);

$register = file_get_contents($root . '/src/auth/register.php');
expect_form_a11y(
    is_string($register)
        && str_contains($register, '$posted_username')
        && str_contains($register, '$posted_email')
        && str_contains($register, '$form_error_field'),
    'registration tracks the invalid field and preserves safe values',
    $failures
);

$request_reset = file_get_contents($root . '/src/auth/request_reset.php');
expect_form_a11y(
    is_string($request_reset)
        && str_contains($request_reset, "'email' => \$posted_email")
        && str_contains($request_reset, '$email_invalid')
        && str_contains($request_reset, '$captcha_invalid'),
    'password-reset requests preserve the email and mark invalid fields',
    $failures
);

$captcha = file_get_contents($root . '/src/includes/captcha.php');
expect_form_a11y(
    is_string($captcha)
        && str_contains($captcha, 'function captcha_render(string $image_src, ?string $error_id = null)')
        && str_contains($captcha, 'aria-describedby="<?php echo $described_by; ?>"')
        && str_contains($captcha, 'aria-invalid="true"'),
    'the shared CAPTCHA can be associated with an inline error',
    $failures
);

$change_password = file_get_contents($root . '/src/auth/change_password.php');
foreach (['current_password', 'new_password', 'confirm_password'] as $field) {
    expect_form_a11y(
        is_string($change_password)
            && str_contains($change_password, "\$form_error_field === '{$field}'"),
        "change-password can mark the {$field} field invalid",
        $failures
    );
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Form error association contracts passed.\n";
