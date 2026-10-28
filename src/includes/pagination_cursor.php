<?php

/**
 * @return array{id: string, date: string|null}|null
 */
function decode_entry_cursor(mixed $value): ?array
{
    if (
        !is_string($value)
        || strlen($value) > 2048
        || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1
    ) {
        return null;
    }

    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $json = base64_decode($base64, true);
    if ($json === false) {
        return null;
    }

    try {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (
        !is_array($payload)
        || !isset($payload['id'])
        || !is_string($payload['id'])
        || $payload['id'] === ''
        || strlen($payload['id']) > 1020
        || !array_key_exists('date', $payload)
        || ($payload['date'] !== null && !is_string($payload['date']))
    ) {
        return null;
    }

    $date = $payload['date'];
    if ($date !== null && $date !== '0000-00-00 00:00:00') {
        if (strlen($date) !== 19) {
            return null;
        }

        $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        $date_errors = DateTimeImmutable::getLastErrors();
        if (
            $parsed_date === false
            || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
            || $parsed_date->format('Y-m-d H:i:s') !== $date
        ) {
            return null;
        }
    }

    return ['id' => $payload['id'], 'date' => $date];
}

/**
 * @param array{id: string, date: string|null} $cursor
 */
function encode_entry_cursor(array $cursor): string
{
    $json = json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/**
 * @param array{id: string, date: string|null} $cursor
 * @return array{sql: string, params: list<string>}
 */
function entry_cursor_condition(array $cursor, string $direction): array
{
    if ($cursor['date'] === null) {
        if ($direction === 'older') {
            return [
                'sql' => '(entries.date IS NULL AND entries.id < ?)',
                'params' => [$cursor['id']],
            ];
        }

        return [
            'sql' => '(entries.date IS NOT NULL OR (entries.date IS NULL AND entries.id > ?))',
            'params' => [$cursor['id']],
        ];
    }

    if ($direction === 'older') {
        return [
            'sql' => '(entries.date < ? OR entries.date IS NULL OR (entries.date = ? AND entries.id < ?))',
            'params' => [$cursor['date'], $cursor['date'], $cursor['id']],
        ];
    }

    return [
        'sql' => '(entries.date > ? OR (entries.date = ? AND entries.id > ?))',
        'params' => [$cursor['date'], $cursor['date'], $cursor['id']],
    ];
}
