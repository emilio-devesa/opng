<?php

/**
 * Single asset version for cache busting.
 *
 * The maximum mtime of the stylesheet, JavaScript bundles and language
 * catalogs is computed once per request. Pages reuse the same value for every
 * asset URL instead of calling filemtime() repeatedly.
 */
function opng_asset_version(): int
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $files = [__DIR__ . '/../main.css'];
    foreach (['js', 'vendor', 'lang'] as $directory) {
        $matches = glob(__DIR__ . '/../assets/' . $directory . '/*');
        if ($matches === false) {
            continue;
        }
        foreach ($matches as $match) {
            if (is_file($match)) {
                $files[] = $match;
            }
        }
    }

    $version = 0;
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false) {
            $version = max($version, $mtime);
        }
    }

    return $version;
}
