<?php

declare(strict_types=1);

// includes/requirements.php
// Supported PHP patch range, mirroring composer.json (HYG-12).
//
// Some patch releases are excluded because of security or runtime bugs; keep
// this list and the `require.php` constraint in composer.json in sync. The
// doctor reports an unsupported runtime instead of letting the application
// fail on a missing behaviour.

const OPNG_PHP_REQUIREMENT = '>=8.2.35 <8.3.0 || >=8.3.36 <8.4.0 || >=8.4.27 <8.5.0 || >=8.5.12 <9.0.0';

/**
 * Whether a PHP version string is inside the supported patch range.
 */
function opng_php_version_supported(string $version): bool
{
    return (version_compare($version, '8.2.35', '>=') && version_compare($version, '8.3.0', '<'))
        || (version_compare($version, '8.3.36', '>=') && version_compare($version, '8.4.0', '<'))
        || (version_compare($version, '8.4.27', '>=') && version_compare($version, '8.5.0', '<'))
        || (version_compare($version, '8.5.12', '>=') && version_compare($version, '9.0.0', '<'));
}
