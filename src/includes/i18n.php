<?php
// includes/i18n.php
// Server-side locale selection and translation (UX-04).
//
// The JSON catalogs in assets/lang/ are the single source of truth. The
// language selector stores its choice in the `opng_lang` cookie; PHP uses that
// cookie (then Accept-Language) to set <html lang> and to translate every
// element that already carries data-i18n before the first paint. This keeps
// the page readable and correctly labelled without JavaScript. language.js
// still switches languages instantly and keeps the cookie in sync.
//
// The translation step runs on the buffered layout output (see
// layout_header.php/layout_footer.php) and is intentionally skipped for the
// default English locale so English requests pay no DOM parsing cost.

const OPNG_DEFAULT_LOCALE = 'en';
const OPNG_SUPPORTED_LOCALES = ['en', 'es', 'de', 'fr', 'pt', 'zh'];

/**
 * Normalize a locale tag ("es", "es-ES", "es_MX") to a supported catalog.
 */
function opng_supported_locale(?string $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $value = strtolower(trim($value));
    $primary = preg_split('/[-_]/', $value)[0] ?? '';

    return in_array($primary, OPNG_SUPPORTED_LOCALES, true) ? $primary : null;
}

/**
 * Pick the best supported locale from an Accept-Language header.
 */
function opng_accept_language_locale(?string $header): ?string
{
    if (!is_string($header) || trim($header) === '') {
        return null;
    }

    $candidates = [];
    foreach (explode(',', $header) as $order => $part) {
        $pieces = explode(';', $part);
        $locale = opng_supported_locale($pieces[0]);
        if ($locale === null) {
            continue;
        }

        $quality = 1.0;
        foreach (array_slice($pieces, 1) as $parameter) {
            if (preg_match('/^\s*q=([0-9.]+)\s*$/i', $parameter, $match) === 1) {
                $quality = (float) $match[1];
            }
        }
        if ($quality > 0.0) {
            $candidates[] = ['locale' => $locale, 'quality' => $quality, 'order' => $order];
        }
    }

    usort($candidates, static function (array $left, array $right): int {
        if ($left['quality'] === $right['quality']) {
            return $left['order'] <=> $right['order'];
        }

        return $right['quality'] <=> $left['quality'];
    });

    return $candidates[0]['locale'] ?? null;
}

/**
 * Current request locale: explicit cookie, then browser preference, then English.
 */
function opng_current_locale(): string
{
    static $locale = null;
    if (is_string($locale)) {
        return $locale;
    }

    $from_cookie = isset($_COOKIE['opng_lang']) && is_string($_COOKIE['opng_lang'])
        ? opng_supported_locale($_COOKIE['opng_lang'])
        : null;
    $from_header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? opng_accept_language_locale($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        : null;

    $locale = $from_cookie ?? $from_header ?? OPNG_DEFAULT_LOCALE;

    return $locale;
}

/**
 * Load a translation catalog. Missing or malformed catalogs degrade to English.
 *
 * @return array<string, string>
 */
function opng_catalog(?string $locale = null): array
{
    static $cache = [];
    $resolved = opng_supported_locale($locale) ?? opng_current_locale();
    if (isset($cache[$resolved])) {
        return $cache[$resolved];
    }

    $path = __DIR__ . '/../assets/lang/' . $resolved . '.json';
    $json = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($json) ? json_decode($json, true) : null;
    $catalog = [];
    if (is_array($decoded)) {
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $catalog[$key] = $value;
            }
        }
    }

    return $cache[$resolved] = $catalog;
}

/**
 * Translate a key with an English fallback.
 */
function opng_t(string $key, string $fallback = ''): string
{
    $catalog = opng_catalog();
    if (isset($catalog[$key]) && $catalog[$key] !== '') {
        return $catalog[$key];
    }

    return $fallback !== '' ? $fallback : $key;
}

/**
 * Whether a node has element children that a text replacement would destroy.
 */
function opng_node_has_element_children(DOMNode $node): bool
{
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            return true;
        }
    }

    return false;
}

/**
 * Translate the data-i18n attributes in a complete HTML document.
 *
 * Elements with nested markup are left untouched so buttons that combine an
 * emoji with a visually-hidden label keep both. The JavaScript translator
 * applies the same rule for dynamic switches.
 */
function opng_i18n_apply(string $html): string
{
    $locale = opng_current_locale();
    if ($locale === OPNG_DEFAULT_LOCALE) {
        return $html;
    }

    $catalog = opng_catalog($locale);
    if ($catalog === []) {
        return $html;
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML(
        '<?xml encoding="utf-8" ?>' . $html,
        LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || !$document->documentElement instanceof DOMElement) {
        return $html;
    }

    $document->documentElement->setAttribute('lang', $locale);
    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//*[@data-i18n]') as $node) {
        if (!$node instanceof DOMElement || opng_node_has_element_children($node)) {
            continue;
        }
        $key = $node->getAttribute('data-i18n');
        if (isset($catalog[$key])) {
            $node->textContent = $catalog[$key];
        }
    }

    foreach (['aria-label', 'placeholder', 'title', 'alt'] as $attribute) {
        foreach ($xpath->query('//*[@data-i18n-' . $attribute . ']') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $key = $node->getAttribute('data-i18n-' . $attribute);
            if (isset($catalog[$key])) {
                $node->setAttribute($attribute, $catalog[$key]);
            }
        }
    }

    $output = $document->saveHTML();
    if (!is_string($output)) {
        return $html;
    }

    return preg_replace('/<\?xml[^>]*\?>\s*/', '', $output) ?? $output;
}

/**
 * Human-readable, locale-aware rendering of a stored MySQL datetime.
 *
 * The database is the source of truth for the stored wall-clock value, so the
 * helper does not shift it. It returns a <time> element with a machine-readable
 * datetime and a title that preserves the raw stored value.
 */
function opng_datetime_html(string $mysql_datetime, ?string $locale = null): string
{
    $raw = trim($mysql_datetime);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }

    try {
        $date = new DateTimeImmutable($raw);
    } catch (Exception) {
        return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }

    $locale = opng_supported_locale($locale) ?? opng_current_locale();
    $format = match ($locale) {
        'es', 'fr', 'pt' => 'd/m/Y H:i',
        'de' => 'd.m.Y H:i',
        'zh' => 'Y-m-d H:i',
        default => 'M j, Y H:i',
    };
    $display = $date->format($format);
    $machine = $date->format('Y-m-d\TH:i:s');

    return '<time datetime="' . htmlspecialchars($machine, ENT_QUOTES, 'UTF-8') . '" title="'
        . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</time>';
}
