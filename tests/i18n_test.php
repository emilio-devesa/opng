<?php

require __DIR__ . '/../src/includes/i18n.php';

$root = dirname(__DIR__);
$failures = [];

function expect_i18n(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = $message;
        return;
    }

    echo "PASS: {$message}\n";
}

// Locale normalization and Accept-Language quality ordering.
expect_i18n(opng_supported_locale('es-ES') === 'es', 'regional locales map to their catalog', $failures);
expect_i18n(opng_supported_locale('pt_BR') === 'pt', 'underscore locales map to their catalog', $failures);
expect_i18n(opng_supported_locale('xx') === null, 'unsupported locales are rejected', $failures);
expect_i18n(
    opng_accept_language_locale('fr-CA,fr;q=0.9,en;q=0.8') === 'fr',
    'Accept-Language prefers the highest-quality supported locale',
    $failures
);
expect_i18n(
    opng_accept_language_locale('de;q=0.2, es;q=0.9') === 'es',
    'Accept-Language orders candidates by quality',
    $failures
);
expect_i18n(
    opng_accept_language_locale('xx, en;q=0.5') === 'en',
    'Accept-Language skips unsupported locales',
    $failures
);

// All six catalogs must stay complete and share the same keys.
$reference = null;
foreach (['en', 'es', 'de', 'fr', 'pt', 'zh'] as $language) {
    $catalog = opng_catalog($language);
    expect_i18n($catalog !== [], "{$language}.json loads as an array", $failures);
    $keys = array_keys($catalog);
    sort($keys);
    if ($reference === null) {
        $reference = $keys;
    } else {
        expect_i18n($keys === $reference, "{$language}.json has the same keys as English", $failures);
    }
    foreach (['no_entries', 'no_entries_create', 'error_confirmation_required'] as $key) {
        expect_i18n(
            isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '',
            "{$language}.json defines {$key}",
            $failures
        );
    }
}

// Server-side translation of a buffered document.
$_COOKIE['opng_lang'] = 'es';
$html = '<!DOCTYPE html><html lang="en"><head><title data-i18n="title_index">Open Pastebin NG</title></head>'
    . '<body><p data-i18n="no_entries">No entries yet.</p>'
    . '<button data-i18n-aria-label="home" aria-label="Home">Home</button>'
    . '<p data-i18n="no_results"><strong>Keep nested markup</strong></p></body></html>';
$translated = opng_i18n_apply($html);
expect_i18n(opng_current_locale() === 'es', 'the opng_lang cookie selects the request locale', $failures);
expect_i18n(str_contains($translated, '<html lang="es"'), 'the translated document declares the active locale', $failures);
expect_i18n(str_contains($translated, 'no hay entradas'), 'data-i18n text is translated before output', $failures);
expect_i18n(str_contains($translated, 'aria-label="Inicio"'), 'data-i18n attributes are translated', $failures);
expect_i18n(str_contains($translated, '<strong>Keep nested markup</strong>'), 'nested markup is never replaced', $failures);
expect_i18n(!str_contains($translated, '<?xml'), 'the temporary XML declaration is removed', $failures);
expect_i18n(opng_t('no_entries') === 'Todavía no hay entradas.', 'opng_t resolves catalog keys', $failures);

// Locale-aware <time> rendering keeps the stored value as the machine value.
$spanish_date = opng_datetime_html('2026-11-06 12:34:56', 'es');
expect_i18n(str_contains($spanish_date, 'datetime="2026-11-06T12:34:56"'), 'dates expose a machine-readable datetime', $failures);
expect_i18n(str_contains($spanish_date, 'title="2026-11-06 12:34:56"'), 'dates preserve the stored value in title', $failures);
expect_i18n(str_contains($spanish_date, '>06/11/2026 12:34</time>'), 'Spanish dates use day/month/year order', $failures);
$english_date = opng_datetime_html('2026-11-06 12:34:56', 'en');
expect_i18n(str_contains($english_date, '>Nov 6, 2026 12:34</time>'), 'English dates use a localized month name', $failures);
expect_i18n(
    opng_datetime_html('not-a-date', 'en') === 'not-a-date',
    'invalid stored dates are escaped without crashing',
    $failures
);

// The static assets must keep the cookie and the server locale in sync.
$language_js = file_get_contents($root . '/src/assets/js/language.js');
expect_i18n(
    is_string($language_js)
        && str_contains($language_js, 'opng_lang')
        && str_contains($language_js, 'document.documentElement.lang')
        && str_contains($language_js, 'el.querySelector("*")'),
    'language.js writes the cookie, prefers the server locale and preserves nested markup',
    $failures
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Server-side locale contracts passed.\n";
