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

// Image challenge: case-insensitive comparison, one use only.
captcha_issue_image('ABCDE');
expect(captcha_validate('abcde'), 'the image code is accepted case-insensitively', $failures);
expect(!isset($_SESSION['captcha']), 'a used image code is discarded', $failures);
expect(!captcha_validate('ABCDE'), 'an image code cannot be replayed', $failures);

captcha_issue_image('ABCDE');
expect(!captcha_validate('ZZZZZ'), 'a wrong image code is rejected', $failures);
expect(!isset($_SESSION['captcha']), 'a wrong image code is discarded', $failures);

captcha_issue_image('ABCDE');
$_SESSION['captcha_expires'] = time() - 1;
expect(!captcha_validate('ABCDE'), 'an expired image code is rejected', $failures);

// Text challenge: arithmetic answer, one use only.
$question = captcha_text_challenge();
$matches = [];
$valid_question = preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches) === 1;
expect($valid_question, 'the accessible challenge is a short arithmetic question', $failures);
if ($valid_question) {
    $answer = (string) ((int) $matches[1] + (int) $matches[2]);
    expect(captcha_validate($answer), 'the text answer is accepted', $failures);
    expect(!isset($_SESSION['captcha_text_hash']), 'a used text challenge is discarded', $failures);
}

$question = captcha_text_challenge();
$matches = [];
preg_match('/^(\d) \+ (\d) = \?$/', $question, $matches);
expect(
    !captcha_validate((string) ((int) ($matches[1] ?? 0) + (int) ($matches[2] ?? 0) + 1)),
    'a wrong text answer is rejected',
    $failures
);

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

// Rendering and endpoint contracts.
ob_start();
captcha_render('auth/captcha.php');
$markup = ob_get_clean();
foreach ([
    'class="captcha-field"',
    'class="captcha-image"',
    'class="refresh-captcha"',
    '<details class="captcha-text-alternative">',
    'data-captcha-question',
    'data-captcha-status',
    'role="status"',
    'aria-live="polite"',
    'class="captcha-listen"',
    'data-i18n="captcha_listen"',
] as $marker) {
    expect(
        is_string($markup) && str_contains($markup, $marker),
        "the shared CAPTCHA markup contains {$marker}",
        $failures
    );
}

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/src/auth/captcha.php');
expect(
    is_string($endpoint)
        && str_contains($endpoint, "rate_limit_hit(\$db, 'captcha'")
        && str_contains($endpoint, 'RateLimitResult::Limited')
        && str_contains($endpoint, "header('Retry-After:"),
    'the CAPTCHA endpoint is rate limited and sends Retry-After',
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

$catalogs = ['en', 'es', 'de', 'fr', 'pt', 'zh'];
foreach ($catalogs as $language) {
    $catalog = json_decode((string) file_get_contents($root . "/src/assets/lang/{$language}.json"), true);
    foreach (['captcha_alternative', 'captcha_text_help', 'captcha_listen', 'captcha_refreshed'] as $key) {
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
        && str_contains($script, 'captcha-listen'),
    'the refresh script announces changes and offers speech synthesis',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "CAPTCHA accessibility contracts passed: text/audio fallback, expiry, single use, endpoint limit, and translations.\n";
