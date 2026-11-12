<?php

declare(strict_types=1);

// includes/captcha.php
// Shared CAPTCHA state and accessible alternative (SEC-11 / UX-03 / SEC-19).
//
// The image code is the default challenge. Users who cannot read the image
// opt into an explicit text mode (auth/captcha.php?mode=text), which is
// rate-limited and only accepted after that opt-in. Each challenge allows a
// small number of attempts before it is discarded, and both the challenge and
// the attempts are kept in the session. No external service is required.
//
// Challenges expire after five minutes and are compared with hash_equals().
// A successful submission clears every pending challenge.

const OPNG_CAPTCHA_TTL_SECONDS = 300;
const OPNG_CAPTCHA_MAX_ATTEMPTS = 3;

/**
 * Remove every pending challenge from the session. The text-mode preference
 * is intentionally preserved so an exhausted challenge does not force the
 * user back into image mode.
 */
function captcha_clear(): void
{
    unset(
        $_SESSION['captcha'],
        $_SESSION['captcha_expires'],
        $_SESSION['captcha_text_question'],
        $_SESSION['captcha_text_hash'],
        $_SESSION['captcha_text_expires'],
        $_SESSION['captcha_attempts']
    );
}

/**
 * Store a freshly generated image code together with its expiry and restart
 * the attempt budget.
 */
function captcha_issue_image(string $code): void
{
    unset(
        $_SESSION['captcha_text_mode'],
        $_SESSION['captcha_text_question'],
        $_SESSION['captcha_text_hash'],
        $_SESSION['captcha_text_expires']
    );
    $_SESSION['captcha'] = $code;
    $_SESSION['captcha_expires'] = time() + OPNG_CAPTCHA_TTL_SECONDS;
    $_SESSION['captcha_attempts'] = 0;
}

/**
 * Whether the session already holds an unexpired image challenge. The image
 * endpoint reuses it so the attempt budget applies to the same code across a
 * failed submission and the form reload.
 */
function captcha_image_challenge_available(): bool
{
    $code = $_SESSION['captcha'] ?? null;
    $expires = $_SESSION['captcha_expires'] ?? null;

    return is_string($code) && is_int($expires) && $expires >= time();
}

/**
 * Whether the user explicitly opted into the accessible text challenge.
 */
function captcha_text_mode_enabled(): bool
{
    return ($_SESSION['captcha_text_mode'] ?? false) === true;
}

/**
 * Enable the accessible text challenge and discard any image challenge.
 */
function captcha_enable_text_mode(): void
{
    unset($_SESSION['captcha'], $_SESSION['captcha_expires'], $_SESSION['captcha_attempts']);
    $_SESSION['captcha_text_mode'] = true;
}

/**
 * Return to the image challenge and discard the text challenge.
 */
function captcha_disable_text_mode(): void
{
    unset(
        $_SESSION['captcha_text_mode'],
        $_SESSION['captcha_text_question'],
        $_SESSION['captcha_text_hash'],
        $_SESSION['captcha_text_expires'],
        $_SESSION['captcha_attempts']
    );
}

/**
 * Return the arithmetic question for the current session, creating a new one
 * when there is none or it has expired.
 */
function captcha_text_challenge(): string
{
    $expires = $_SESSION['captcha_text_expires'] ?? null;
    $question = $_SESSION['captcha_text_question'] ?? null;
    $hash = $_SESSION['captcha_text_hash'] ?? null;

    if (
        is_string($question)
        && is_string($hash)
        && is_int($expires)
        && $expires >= time()
    ) {
        return $question;
    }

    $left = random_int(2, 9);
    $right = random_int(2, 9);
    $answer = (string) ($left + $right);

    $_SESSION['captcha_text_question'] = "{$left} + {$right} = ?";
    $_SESSION['captcha_text_hash'] = hash('sha256', $answer);
    $_SESSION['captcha_text_expires'] = time() + OPNG_CAPTCHA_TTL_SECONDS;
    $_SESSION['captcha_attempts'] = 0;

    return $_SESSION['captcha_text_question'];
}

/**
 * Count a failed attempt. The challenge is discarded after
 * OPNG_CAPTCHA_MAX_ATTEMPTS failures; the text-mode preference survives.
 */
function captcha_record_failure(): bool
{
    $attempts = $_SESSION['captcha_attempts'] ?? 0;
    if (!is_int($attempts)) {
        $attempts = 0;
    }

    $attempts++;
    $_SESSION['captcha_attempts'] = $attempts;

    if ($attempts >= OPNG_CAPTCHA_MAX_ATTEMPTS) {
        $text_mode = captcha_text_mode_enabled();
        captcha_clear();
        if ($text_mode) {
            $_SESSION['captcha_text_mode'] = true;
        }
    }

    return false;
}

/**
 * Validate an answer against the active challenge.
 *
 * The comparison is constant-time. Only the image challenge is accepted by
 * default; the text challenge requires an explicit opt-in. A correct answer
 * clears the challenge, while wrong answers consume the attempt budget.
 */
function captcha_validate(?string $answer): bool
{
    if ($answer === null || trim($answer) === '') {
        return captcha_record_failure();
    }

    $answer = strtoupper(trim($answer));
    $now = time();
    $valid = false;

    if (captcha_text_mode_enabled()) {
        $text_hash = $_SESSION['captcha_text_hash'] ?? null;
        $text_expires = $_SESSION['captcha_text_expires'] ?? null;
        $valid = is_string($text_hash)
            && is_int($text_expires)
            && $text_expires >= $now
            && ctype_digit($answer)
            && hash_equals($text_hash, hash('sha256', $answer));
    } else {
        $image_code = $_SESSION['captcha'] ?? null;
        $image_expires = $_SESSION['captcha_expires'] ?? null;
        $valid = is_string($image_code)
            && is_int($image_expires)
            && $image_expires >= $now
            && hash_equals($image_code, $answer);
    }

    if ($valid) {
        captcha_clear();
        return true;
    }

    return captcha_record_failure();
}

/**
 * Build a URL that switches the CAPTCHA mode on the current page.
 *
 * The image endpoint lives in auth/, so a root-relative return path is used.
 * Only local paths are propagated to avoid turning the endpoint into an open
 * redirect.
 */
function captcha_mode_url(string $image_src, string $mode): string
{
    $base = strtok($image_src, '?');
    if (!is_string($base)) {
        $base = 'auth/captcha.php';
    }

    $parameters = ['mode' => $mode];
    $return = $_SERVER['REQUEST_URI'] ?? null;
    if (
        is_string($return)
        && $return !== ''
        && $return[0] === '/'
        && !str_starts_with($return, '//')
        && preg_match('/[\x00-\x1F\x7F\\\\]/', $return) !== 1
    ) {
        $parameters['return'] = $return;
    }

    return $base . '?' . http_build_query($parameters);
}

/**
 * Render the shared CAPTCHA field: image, refresh control and the accessible
 * text alternative. The caller must have an active session.
 */
function captcha_render(string $image_src, ?string $error_id = null): void
{
    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $text_mode = captcha_text_mode_enabled();
    $image_src_attribute = $escape($image_src);
    $text_mode_url = $escape(captcha_mode_url($image_src, 'text'));
    $image_mode_url = $escape(captcha_mode_url($image_src, 'image'));
    $error_id = $error_id !== null && $error_id !== '' ? $escape($error_id) : null;
    $described_by = $error_id !== null ? 'captcha-help ' . $error_id : 'captcha-help';
    ?>
    <div class="captcha-field">
        <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
        <input id="captcha_answer" type="text" name="captcha_answer" maxlength="5" autocomplete="off" required
            aria-describedby="<?php echo $described_by; ?>"<?php echo $error_id !== null ? ' aria-invalid="true"' : ''; ?>>
        <?php if ($text_mode) : ?>
            <div class="captcha-text-alternative">
                <p class="captcha-question" data-captcha-question><?php echo $escape(captcha_text_challenge()); ?></p>
                <p class="field-help" id="captcha-help" data-i18n="captcha_text_help">Type the result of the arithmetic question.</p>
                <button type="button" class="captcha-listen" hidden data-i18n="captcha_listen">🔊 Listen to the question</button>
                <a class="refresh-captcha" href="<?php echo $escape(captcha_mode_url($image_src, 'text') . '&refresh=1'); ?>" data-i18n="refresh_captcha">🔄 Refresh</a>
            </div>
            <a class="captcha-mode-link" href="<?php echo $image_mode_url; ?>" data-i18n="captcha_use_image">Use the image challenge instead</a>
        <?php else : ?>
            <img src="<?php echo $image_src_attribute; ?>" alt="CAPTCHA" class="captcha-image" data-i18n-alt="captcha_alt">
            <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
            <p class="field-help" id="captcha-help" data-i18n="captcha_image_help">Type the characters shown in the image.</p>
            <a class="captcha-mode-link" href="<?php echo $text_mode_url; ?>" data-i18n="captcha_alternative">Can't see the image? Use a text challenge</a>
        <?php endif; ?>
        <span class="visually-hidden" role="status" aria-live="polite" data-captcha-status></span>
        <span class="visually-hidden" id="captcha-refreshed-text" data-i18n="captcha_refreshed">New CAPTCHA loaded.</span>
    </div>
    <?php
}
