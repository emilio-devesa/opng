<?php
declare(strict_types=1);
// includes/captcha.php
// Shared CAPTCHA state and accessible alternative (SEC-11 / UX-03).
//
// Two challenges can be active in the same session:
//   - the traditional 5-character image code (issued by auth/captcha.php);
//   - a short arithmetic question, shown as plain text for users who cannot
//     read the image and spoken on request with the browser SpeechSynthesis
//     API. No external service is required.
//
// Both expire after five minutes and are compared with hash_equals(). A
// successful (or failed) submission clears both so a code is never reused.

const OPNG_CAPTCHA_TTL_SECONDS = 300;

/**
 * Remove every pending challenge from the session.
 */
function captcha_clear(): void
{
    unset(
        $_SESSION['captcha'],
        $_SESSION['captcha_expires'],
        $_SESSION['captcha_text_question'],
        $_SESSION['captcha_text_hash'],
        $_SESSION['captcha_text_expires']
    );
}

/**
 * Store a freshly generated image code together with its expiry.
 */
function captcha_issue_image(string $code): void
{
    $_SESSION['captcha'] = $code;
    $_SESSION['captcha_expires'] = time() + OPNG_CAPTCHA_TTL_SECONDS;
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

    return $_SESSION['captcha_text_question'];
}

/**
 * Validate an answer against either the image code or the text challenge.
 *
 * The comparison is constant-time and the whole challenge set is discarded
 * afterwards, whether the answer was right or wrong.
 */
function captcha_validate(?string $answer): bool
{
    if ($answer === null || $answer === '') {
        captcha_clear();
        return false;
    }

    $answer = strtoupper(trim($answer));
    $now = time();

    $image_code = $_SESSION['captcha'] ?? null;
    $image_expires = $_SESSION['captcha_expires'] ?? null;
    if (
        is_string($image_code)
        && is_int($image_expires)
        && $image_expires >= $now
        && hash_equals($image_code, $answer)
    ) {
        captcha_clear();
        return true;
    }

    $text_hash = $_SESSION['captcha_text_hash'] ?? null;
    $text_expires = $_SESSION['captcha_text_expires'] ?? null;
    if (
        is_string($text_hash)
        && is_int($text_expires)
        && $text_expires >= $now
        && ctype_digit($answer)
        && hash_equals($text_hash, hash('sha256', $answer))
    ) {
        captcha_clear();
        return true;
    }

    captcha_clear();
    return false;
}

/**
 * Render the shared CAPTCHA field: image, refresh control and the accessible
 * text/audio alternative. The caller must have an active session.
 */
function captcha_render(string $image_src, ?string $error_id = null): void
{
    $question = captcha_text_challenge();
    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $image_src = $escape($image_src);
    $question = $escape($question);
    $error_id = $error_id !== null && $error_id !== '' ? $escape($error_id) : null;
    $described_by = $error_id !== null ? 'captcha-help ' . $error_id : 'captcha-help';
    ?>
    <div class="captcha-field">
        <label for="captcha_answer" data-i18n="captcha_label">Please enter the captcha text:</label>
        <input id="captcha_answer" type="text" name="captcha_answer" maxlength="5" autocomplete="off" required
            aria-describedby="<?php echo $described_by; ?>"<?php echo $error_id !== null ? ' aria-invalid="true"' : ''; ?>>
        <img src="<?php echo $image_src; ?>" alt="CAPTCHA" class="captcha-image" data-i18n-alt="captcha_alt">
        <button type="button" class="refresh-captcha" data-i18n="refresh_captcha">🔄 Refresh</button>
        <details class="captcha-text-alternative">
            <summary data-i18n="captcha_alternative">Can't see the image? Use a text challenge</summary>
            <p class="captcha-question" data-captcha-question><?php echo $question; ?></p>
            <p class="field-help" id="captcha-help" data-i18n="captcha_text_help">Type the result of the arithmetic question.</p>
            <button type="button" class="captcha-listen" hidden data-i18n="captcha_listen">🔊 Listen to the question</button>
        </details>
        <span class="visually-hidden" role="status" aria-live="polite" data-captcha-status></span>
        <span class="visually-hidden" id="captcha-refreshed-text" data-i18n="captcha_refreshed">New CAPTCHA loaded.</span>
    </div>
    <?php
}
