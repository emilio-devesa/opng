<?php

/**
 * WCAG 2.x contrast regression test.
 *
 * Parses the design tokens in src/main.css so a future palette change cannot
 * silently drop below the AA thresholds: 4.5:1 for text and 3:1 for UI
 * components and focus indicators.
 */

$css_path = dirname(__DIR__) . '/src/main.css';
$css = file_get_contents($css_path);
if (!is_string($css)) {
    fwrite(STDERR, "FAIL: src/main.css could not be read\n");
    exit(1);
}

/**
 * Return the declaration body of the first rule whose selector matches.
 */
function css_rule_body(string $css, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^{}]*)\}/';
    if (preg_match($pattern, $css, $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

/**
 * @return array<string, string>
 */
function css_tokens(string $body): array
{
    $tokens = [];
    if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }
    }

    return $tokens;
}

/**
 * Resolve a token within one theme, falling back to the root palette.
 *
 * @param array<string, string> $theme
 * @param array<string, string> $root
 */
function resolve_token(string $name, array $theme, array $root, int $depth = 0): ?string
{
    if ($depth > 8) {
        return null;
    }

    $value = $theme[$name] ?? $root[$name] ?? null;
    if (!is_string($value)) {
        return null;
    }

    if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $matches) === 1) {
        return resolve_token($matches[1], $theme, $root, $depth + 1);
    }

    return $value;
}

/**
 * @param array<string, string> $theme
 * @param array<string, string> $root
 */
function token_color(string $name, array $theme, array $root): ?string
{
    $value = resolve_token($name, $theme, $root);
    if (!is_string($value) || preg_match('/^#[0-9a-f]{6}$/i', $value) !== 1) {
        return null;
    }

    return $value;
}

function relative_luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = [];
    foreach ([0, 2, 4] as $offset) {
        $channel = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrast_ratio(string $foreground, string $background): float
{
    $foreground_luminance = relative_luminance($foreground);
    $background_luminance = relative_luminance($background);
    $lighter = max($foreground_luminance, $background_luminance);
    $darker = min($foreground_luminance, $background_luminance);

    return ($lighter + 0.05) / ($darker + 0.05);
}

$root = css_tokens(css_rule_body($css, ':root'));
$dark = css_tokens(css_rule_body($css, 'body.dark-mode'));
if ($root === [] || $dark === []) {
    fwrite(STDERR, "FAIL: the light or dark token block could not be parsed\n");
    exit(1);
}

// token pairs => [foreground, background, minimum ratio]
$requirements = [
    ['--text', '--bg', 4.5],
    ['--text', '--surface', 4.5],
    ['--muted', '--bg', 4.5],
    ['--muted', '--surface', 4.5],
    ['--muted', '--surface-2', 4.5],
    ['--accent', '--surface', 4.5],
    ['--accent', '--surface-2', 4.5],
    ['--danger', '--surface', 4.5],
    ['--danger', '--surface-2', 4.5],
    ['--success', '--surface', 4.5],
    ['--success', '--surface-2', 4.5],
    ['--button-text', '--button-bg', 4.5],
    ['--button-text', '--button-bg-hover', 4.5],
    ['--button-text', '--danger-fill', 4.5],
    ['--button-text', '--danger-fill-hover', 4.5],
    ['--action-text', '--action-bg', 4.5],
    ['--action-text-hover', '--action-bg-hover', 4.5],
    ['--control-border', '--control-bg', 3.0],
    ['--control-border', '--surface', 3.0],
    ['--control-border', '--bg', 3.0],
    ['--focus-ring', '--control-bg', 3.0],
    ['--focus-ring', '--surface', 3.0],
    ['--focus-ring', '--bg', 3.0],
    ['--code-text', '--code-bg', 4.5],
    ['--code-muted', '--code-bg', 4.5],
];

$failures = [];
foreach (['light' => $root, 'dark' => $dark + $root] as $theme_name => $theme) {
    foreach ($requirements as [$foreground_name, $background_name, $minimum]) {
        $foreground = token_color($foreground_name, $theme, $root);
        $background = token_color($background_name, $theme, $root);
        if ($foreground === null || $background === null) {
            $failures[] = "{$theme_name}: {$foreground_name} or {$background_name} is not a solid hex colour";
            continue;
        }

        $ratio = contrast_ratio($foreground, $background);
        if ($ratio + 0.005 < $minimum) {
            $failures[] = sprintf(
                '%s: %s on %s is %.2f:1 (needs %.1f:1)',
                $theme_name,
                $foreground,
                $background,
                $ratio,
                $minimum
            );
            continue;
        }

        echo sprintf(
            "PASS: %s %s on %s = %.2f:1 (>= %.1f:1)\n",
            $theme_name,
            $foreground_name,
            $background_name,
            $ratio,
            $minimum
        );
    }
}

// Focus indicators must be solid, not a low-opacity accent tint.
if (!str_contains($css, 'outline: 3px solid var(--focus-ring)')) {
    $failures[] = 'Focus outlines do not use the solid --focus-ring token';
}
if (str_contains($css, 'outline: 3px solid var(--accent-ring)')) {
    $failures[] = 'A focus outline still uses the low-contrast --accent-ring tint';
}
if (substr_count($css, 'box-shadow: 0 0 0 3px var(--focus-ring)') < 2) {
    $failures[] = 'Text inputs do not render the solid focus ring';
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    exit(1);
}

echo "Contrast contracts passed: text >= 4.5:1 and UI/focus >= 3:1 in both themes.\n";
