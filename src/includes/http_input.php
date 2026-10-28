<?php

/**
 * Read a bounded string parameter without coercing arrays or other types.
 */
function http_string_parameter(array $source, string $key, int $maxBytes, bool $trim = true): ?string
{
    if ($maxBytes < 0 || !isset($source[$key]) || !is_string($source[$key])) {
        return null;
    }

    $value = $trim ? trim($source[$key]) : $source[$key];
    if (strlen($value) > $maxBytes) {
        return null;
    }

    return $value;
}

function http_positive_integer(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || $value === '' || strlen($value) > 10 || !ctype_digit($value)) {
        return null;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($integer) ? $integer : null;
}

function http_valid_entry_id(string $id): bool
{
    return $id !== '' && strlen($id) <= 255 && ctype_alnum($id);
}
