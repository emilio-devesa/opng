<?php

declare(strict_types=1);

// includes/mail_templates.php
// Localized transactional mail templates (10.1).
//
// The body lives in the regular language catalogs so translators maintain one
// source of truth. Placeholders are replaced after the catalog lookup; a
// missing translation falls back to English.

require_once __DIR__ . '/i18n.php';

const OPNG_RESET_LINK_MINUTES = 10;

/**
 * Read a catalog string for a specific locale, with an English fallback.
 */
function opng_mail_template_text(string $locale, string $key, string $fallback): string
{
    $catalog = opng_catalog($locale);
    $value = $catalog[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $fallback;
}

/**
 * Password-reset message for one recipient.
 *
 * @return array{subject: string, text: string, html: string}
 */
function opng_mail_password_reset_message(string $link, string $locale = 'en'): array
{
    $locale = opng_supported_locale($locale) ?? OPNG_DEFAULT_LOCALE;
    $subject = opng_mail_template_text(
        $locale,
        'email_reset_subject',
        'Reset your Open Pastebin NG password'
    );
    $body = opng_mail_template_text(
        $locale,
        'email_reset_body',
        "A password reset was requested for your account.\n\n"
        . "Open this link to choose a new password (valid for {minutes} minutes and usable once):\n"
        . "{link}\n\n"
        . "If you did not request this, you can ignore this message."
    );

    $replacements = [
        '{link}' => $link,
        '{minutes}' => (string) OPNG_RESET_LINK_MINUTES,
    ];
    $subject = strtr($subject, $replacements);
    $text = strtr($body, $replacements);

    $safe_text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $safe_link = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html lang="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . '">'
        . '<body><p>' . nl2br($safe_text, false) . '</p>'
        . '<p><a href="' . $safe_link . '">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '</body></html>';

    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}
