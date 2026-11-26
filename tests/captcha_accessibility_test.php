<?php

require __DIR__ . '/../src/includes/captcha.php';

$_SESSION = [];

$failures = [];

function expect(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

// Image challenge: case-insensitive comparison and single use.
captcha_disable_text_mode();
captcha_issue_image('ABCDE');
expect(captcha_validate('abcde'), 'the image code is accepted case-insensitively', $failures);
expect(!isset($_SESSION['captcha']), 'a used image code is discarded', $failures);
expect(!captcha_validate('ABCDE'), 'an image code cannot be replayed', $failures);

// A wrong answer keeps the challenge for the next attempt, up to the budget.
captcha_issue_image('ABCDE');
expect(!captcha_validate('ZZZZZ'), 'a wrong image code is rejected', $failures);
expect(isset($_SESSION['captcha']), 'a wrong image code keeps the challenge for a retry', $failures);
expect(($_SESSION['captcha_attempts'] ?? 0) === 1, 'a wrong image code consumes one attempt', $failures);
expect(!captcha_validate('ZZZZZ'), 'a second wrong image code is rejected', $failures);
expect(!captcha_validate('ZZZZZ'), 'a third wrong image code is rejected', $failures);
expect(!isset($_SESSION['captcha']), 'the image challenge is discarded after the attempt budget', $failures);

captcha_issue_image('ABCDE');
$_SESSION['captcha_expires'] = time() - 1;
expect(!captcha_validate('ABCDE'), 'an expired image code is rejected', $failures);

// The text challenge is not accepted unless the user opted into text mode.
captcha_disable_text_mode();
$question = captcha_text_challenge();
$matches = [];
$valid_question = preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches) === 1;
expect($valid_question, 'the accessible challenge is a short arithmetic question', $failures);
if ($valid_question) {
    $answer = (string) ((int) $matches[1] + (int) $matches[2]);
    expect(!captcha_validate($answer), 'the text answer is rejected without the explicit text mode', $failures);
}

// Explicit text mode accepts the arithmetic answer, once.
captcha_enable_text_mode();
$question = captcha_text_challenge();
$matches = [];
preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches);
$answer = (string) ((int) ($matches[1] ?? 0) + (int) ($matches[2] ?? 0));
expect(captcha_validate($answer), 'the text answer is accepted in explicit text mode', $failures);
expect(!isset($_SESSION['captcha_text_hash']), 'a used text challenge is discarded', $failures);
expect(captcha_text_mode_enabled(), 'text mode survives a successful answer', $failures);

// The text challenge also has a bounded attempt budget.
$question = captcha_text_challenge();
$matches = [];
preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches);
$wrong = (string) ((int) ($matches[1] ?? 0) + (int) ($matches[2] ?? 0) + 1);
expect(!captcha_validate($wrong), 'a wrong text answer is rejected', $failures);
expect(isset($_SESSION['captcha_text_hash']), 'a wrong text answer keeps the challenge for a retry', $failures);
expect(!captcha_validate($wrong), 'a second wrong text answer is rejected', $failures);
expect(!captcha_validate($wrong), 'a third wrong text answer is rejected', $failures);
expect(!isset($_SESSION['captcha_text_hash']), 'the text challenge is discarded after the attempt budget', $failures);
expect(captcha_text_mode_enabled(), 'text mode survives an exhausted challenge', $failures);

$question = captcha_text_challenge();
$matches = [];
preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches);
$_SESSION['captcha_text_expires'] = time() - 1;
expect(
    !captcha_validate((string) ((int) ($matches[1] ?? 0) + (int) ($matches[2] ?? 0))),
    'an expired text challenge is rejected',
    $failures
);

captcha_issue_image('ABCDE');
expect(!captcha_validate(null), 'a missing answer is rejected', $failures);
expect(!captcha_validate(''), 'an empty answer is rejected', $failures);

// Switching back to the image challenge discards the text one.
captcha_enable_text_mode();
captcha_text_challenge();
captcha_disable_text_mode();
expect(!captcha_text_mode_enabled(), 'the image challenge can be restored explicitly', $failures);
expect(!isset($_SESSION['captcha_text_hash']), 'leaving text mode discards the text challenge', $failures);

// Image-mode rendering.
captcha_disable_text_mode();
ob_start();
captcha_render('auth/captcha.php');
$image_markup = ob_get_clean();
foreach ([
    'class="captcha-field"',
    'class="captcha-image"',
    'class="refresh-captcha"',
    'class="captcha-mode-link"',
    'data-i18n="captcha_alternative"',
    'data-i18n="captcha_image_help"',
    'data-captcha-status',
    'role="status"',
    'aria-live="polite"',
] as $marker) {
    expect(
        is_string($image_markup) && str_contains($image_markup, $marker),
        "the image CAPTCHA markup contains {$marker}",
        $failures
    );
}

// Text-mode rendering.
captcha_enable_text_mode();
ob_start();
captcha_render('auth/captcha.php');
$text_markup = ob_get_clean();
foreach ([
    'class="captcha-text-alternative"',
    'data-captcha-question',
    'data-i18n="captcha_text_help"',
    'class="captcha-listen"',
    'data-i18n="captcha_listen"',
    'data-i18n="captcha_use_image"',
    'data-i18n="captcha_refreshed"',
] as $marker) {
    expect(
        is_string($text_markup) && str_contains($text_markup, $marker),
        "the text CAPTCHA markup contains {$marker}",
        $failures
    );
}
expect(
    is_string($text_markup) && !str_contains($text_markup, 'class="captcha-image"'),
    'text mode does not render the image challenge',
    $failures
);

$root = dirname(__DIR__);
$styles = file_get_contents($root . '/src/main.css');
expect(
    is_string($styles)
        && str_contains($styles, '.auth-form .captcha-image')
        && str_contains($styles, '.editor-form .captcha-field .captcha-image')
        && str_contains($styles, 'background: #ffffff;'),
    'the creation form frames the CAPTCHA image like the auth pages',
    $failures
);
$endpoint = file_get_contents($root . '/src/auth/captcha.php');
expect(
    is_string($endpoint)
        && str_contains($endpoint, "rate_limit_hit(\$db, 'captcha'")
        && str_contains($endpoint, "rate_limit_hit(\$db, 'captcha_text'")
        && str_contains($endpoint, 'RateLimitResult::Limited')
        && str_contains($endpoint, "header('Retry-After:")
        && str_contains($endpoint, "\$mode === 'text'")
        && str_contains($endpoint, 'captcha_redirect_target'),
    'the CAPTCHA endpoint rate limits both modes and validates the mode switch',
    $failures
);
expect(
    is_string($endpoint)
        && str_contains($endpoint, 'captcha_image_challenge_available()')
        && str_contains($endpoint, "\$refresh")
        && str_contains($endpoint, 'captcha_enable_text_mode()')
        && str_contains($endpoint, 'captcha_disable_text_mode()'),
    'the image endpoint reuses the challenge unless refreshed and supports both modes',
    $failures
);

foreach ([
    '/src/auth/register.php' => "captcha_render('captcha.php'",
    '/src/auth/request_reset.php' => "captcha_render('captcha.php'",
    '/src/auth/login.php' => "captcha_render('captcha.php'",
    '/src/pastebin.php' => "captcha_render('auth/captcha.php'",
] as $path => $renderer) {
    $source = file_get_contents($root . $path);
    expect(
        is_string($source) && str_contains($source, $renderer),
        "{$path} renders the shared accessible CAPTCHA",
        $failures
    );
}

foreach (['submit.php', 'auth/register.php', 'auth/login.php', 'auth/request_reset.php'] as $path) {
    $source = file_get_contents($root . '/src/' . $path);
    expect(
        is_string($source)
            && str_contains($source, 'captcha_validate(')
            && !str_contains($source, "\$_SESSION['captcha']"),
        "{$path} validates both challenge types through the helper",
        $failures
    );
}

// SEC-19: the rate limit is recorded before the CAPTCHA in both guest flows.
$submit = file_get_contents($root . '/src/submit.php');
expect(
    is_string($submit)
        && strpos($submit, 'rate_limit_hit($db, $rate_limit_action') < strpos($submit, 'captcha_validate('),
    'guest paste creation records the rate limit before validating the CAPTCHA',
    $failures
);
$request_reset = file_get_contents($root . '/src/auth/request_reset.php');
expect(
    is_string($request_reset)
        && strpos($request_reset, "rate_limit_hit(\$db, 'reset_ip'") < strpos($request_reset, 'captcha_validate('),
    'password-reset requests record the rate limit before validating the CAPTCHA',
    $failures
);
expect(
    is_string($request_reset)
        && strpos($request_reset, "rate_limit_hit(\$db, 'reset_account'") < strpos($request_reset, 'captcha_validate('),
    'password-reset requests record the account limit before the CAPTCHA',
    $failures
);

$catalogs = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
foreach ($catalogs as $language) {
    $catalog = json_decode((string) file_get_contents($root . "/src/assets/lang/{$language}.json"), true);
    foreach ([
        'captcha_alternative',
        'captcha_image_help',
        'captcha_use_image',
        'captcha_text_help',
        'captcha_listen',
        'captcha_refreshed',
    ] as $key) {
        expect(
            is_array($catalog) && is_string($catalog[$key] ?? null) && $catalog[$key] !== '',
            "{$language}.json defines {$key}",
            $failures
        );
    }
}

$script = file_get_contents($root . '/src/assets/js/refresh-captcha.js');
expect(
    is_string($script)
        && str_contains($script, 'data-captcha-status')
        && str_contains($script, 'SpeechSynthesisUtterance')
        && str_contains($script, 'captcha-listen')
        && str_contains($script, 'refresh=1'),
    'the refresh script requests a new code and announces changes with speech synthesis',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "CAPTCHA accessibility contracts passed: image/text modes, attempt budget, rate-limit order, endpoint limits, and translations.\n";
