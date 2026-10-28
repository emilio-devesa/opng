<?php

function password_validation_error(string $password): ?string
{
    $length = strlen($password);
    if ($length < 12 || $length > 72) {
        return 'Choose a password between 12 and 72 bytes long.';
    }

    return null;
}
