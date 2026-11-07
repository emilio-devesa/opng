<?php
declare(strict_types=1);

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

/**
 * SQL expression for each supported global listing sort.
 *
 * topic, language and date are NOT NULL in the migrated schema, so the bare
 * columns can use their (column, id) indexes. author is derived from the
 * LEFT JOIN and keeps COALESCE for anonymous pastes.
 */
function entry_sort_expression(string $sort): ?string
{
    return match ($sort) {
        'topic' => 'entries.topic',
        'author' => "COALESCE(users.username, 'Guest')",
        'language' => 'entries.language',
        'date' => 'entries.date',
        default => null,
    };
}

/**
 * @return array{sort: string, order: string, value: string, id: string}|null
 */
function decode_listing_cursor(mixed $value): ?array
{
    if (
        !is_string($value)
        || strlen($value) > 2048
        || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1
    ) {
        return null;
    }

    $legacy_cursor = decode_entry_cursor($value);
    if ($legacy_cursor !== null) {
        return [
            'sort' => 'date',
            'order' => 'desc',
            'value' => $legacy_cursor['date'] ?? '0000-00-00 00:00:00',
            'id' => $legacy_cursor['id'],
        ];
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
        || !isset($payload['sort'], $payload['order'], $payload['value'], $payload['id'])
        || !is_string($payload['sort'])
        || entry_sort_expression($payload['sort']) === null
        || !is_string($payload['order'])
        || !in_array($payload['order'], ['asc', 'desc'], true)
        || !is_string($payload['value'])
        || strlen($payload['value']) > 1020
        || !is_string($payload['id'])
        || $payload['id'] === ''
        || strlen($payload['id']) > 1020
    ) {
        return null;
    }

    return [
        'sort' => $payload['sort'],
        'order' => $payload['order'],
        'value' => $payload['value'],
        'id' => $payload['id'],
    ];
}

/**
 * @param array<string, mixed> $row
 */
function entry_listing_sort_value(string $sort, array $row): string
{
    $value = match ($sort) {
        'topic' => $row['topic'] ?? '',
        'author' => $row['username'] ?? 'Guest',
        'language' => $row['language'] ?? '',
        'date' => $row['date'] ?? '',
        default => throw new InvalidArgumentException('Unsupported listing sort.'),
    };

    if (!is_string($value)) {
        throw new UnexpectedValueException('Listing sort value must be a string.');
    }

    return $value;
}

/**
 * @param array{sort: string, order: string, value: string, id: string} $cursor
 */
function encode_listing_cursor(array $cursor): string
{
    $json = json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/**
 * @param array{sort: string, order: string, value: string, id: string} $cursor
 * @return array{sql: string, params: list<string>}
 */
function listing_cursor_condition(array $cursor, string $direction): array
{
    $expression = entry_sort_expression($cursor['sort']);
    if ($expression === null || !in_array($direction, ['older', 'newer'], true)) {
        throw new InvalidArgumentException('Invalid listing cursor sort or direction.');
    }

    $ascending = ($direction === 'older') === ($cursor['order'] === 'asc');
    $operator = $ascending ? '>' : '<';

    return [
        'sql' => "({$expression} {$operator} ? OR ({$expression} = ? AND entries.id {$operator} ?))",
        'params' => [$cursor['value'], $cursor['value'], $cursor['id']],
    ];
}

/**
 * Build the public listing query shared by index.php and the query-plan test,
 * so the test always exercises the production WHERE/ORDER BY clauses.
 *
 * @param array{sort: string, order: string, value: string, id: string}|null $cursor
 * @return array{sql: string, params: list<string>}
 */
function build_listing_query(
    ?string $search_expression,
    ?array $cursor,
    ?string $direction,
    string $sort,
    string $sort_order
): array {
    if (!in_array($sort_order, ['asc', 'desc'], true)) {
        throw new InvalidArgumentException('Invalid listing order.');
    }
    if ($direction !== null && !in_array($direction, ['older', 'newer'], true)) {
        throw new InvalidArgumentException('Invalid listing direction.');
    }

    $conditions = [];
    $params = [];
    if ($search_expression !== null) {
        $conditions[] = 'MATCH(entries.topic) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $search_expression;
    }
    if ($cursor !== null) {
        if ($direction === null) {
            throw new InvalidArgumentException('A listing cursor requires a direction.');
        }
        $cursor_condition = listing_cursor_condition($cursor, $direction);
        $conditions[] = $cursor_condition['sql'];
        array_push($params, ...$cursor_condition['params']);
    }

    $where_sql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    $sort_expression = entry_sort_expression($sort);
    if ($sort_expression === null) {
        throw new InvalidArgumentException('Unsupported listing sort.');
    }
    $query_order = $sort_order;
    if ($direction === 'newer') {
        $query_order = $query_order === 'asc' ? 'desc' : 'asc';
    }

    $sql = "SELECT entries.id, entries.user_id, users.username, entries.topic, entries.language, entries.date
             FROM entries
             LEFT JOIN users ON entries.user_id = users.id"
             . $where_sql .
             " ORDER BY {$sort_expression} {$query_order}, entries.id {$query_order} LIMIT 21";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Keyset condition for the admin listings, which order DESC by
 * (value_column, id_column). "older" moves to smaller values and "newer" moves
 * back to larger values.
 *
 * @param array{id: string, date: string} $cursor
 * @return array{sql: string, params: list<string>}
 */
function admin_keyset_condition(string $value_column, string $id_column, array $cursor, string $direction): array
{
    if (!in_array($direction, ['older', 'newer'], true)) {
        throw new InvalidArgumentException('Invalid admin listing direction.');
    }
    if (
        !in_array($value_column, ['entries.date', 'users.created_at'], true)
        || !in_array($id_column, ['entries.id', 'users.id'], true)
    ) {
        throw new InvalidArgumentException('Invalid admin listing column.');
    }

    $operator = $direction === 'older' ? '<' : '>';

    return [
        'sql' => "({$value_column} {$operator} ? OR ({$value_column} = ? AND {$id_column} {$operator} ?))",
        'params' => [$cursor['date'], $cursor['date'], $cursor['id']],
    ];
}
