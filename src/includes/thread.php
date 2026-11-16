<?php

declare(strict_types=1);

// includes/thread.php
// Related-entry threads for the Historial tab (F1).
//
// The relationship is stored explicitly: parent_id points to the direct parent,
// root_id to the thread root, and depth counts levels from the root. Replies are
// never reparented in v1.0, so cycles cannot be created through the UI; the
// helpers below still guard against corrupt data.

const OPNG_MAX_THREAD_DEPTH = 32;
const OPNG_THREAD_PAGE_SIZE = 500;

require_once __DIR__ . '/pagination_cursor.php';

class ThreadDepthException extends RuntimeException
{
}

/**
 * Load the direct parent of a reply.
 *
 * Replies inherit the parent's visibility, absolute expiry and password
 * protection (10.5), so the caller needs those fields together with the
 * position values.
 *
 * @return array{id: string, user_id: int|null, root_id: string, depth: int, visibility: string, expires_at: string|null, protection_hash: string|null}|null
 */
function thread_parent_for_reply(mysqli $db, string $parent_id, bool $lock = false): ?array
{
    $sql = 'SELECT id, user_id, COALESCE(root_id, id) AS root_id, depth, visibility, expires_at, protection_hash
            FROM entries WHERE id = ? AND deleted_at IS NULL';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $parent_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row) || !is_string($row['id'] ?? null)) {
        return null;
    }

    return [
        'id' => $row['id'],
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'root_id' => is_string($row['root_id'] ?? null) && $row['root_id'] !== ''
            ? $row['root_id']
            : $row['id'],
        'depth' => max(0, (int) ($row['depth'] ?? 0)),
        'visibility' => is_string($row['visibility'] ?? null) && $row['visibility'] !== ''
            ? $row['visibility']
            : 'public',
        'expires_at' => is_string($row['expires_at'] ?? null) && $row['expires_at'] !== ''
            ? $row['expires_at']
            : null,
        'protection_hash' => is_string($row['protection_hash'] ?? null) && $row['protection_hash'] !== ''
            ? $row['protection_hash']
            : null,
    ];
}

/**
 * Position values for a new root or reply.
 *
 * @param array{id: string, root_id: string, depth: int}|null $parent null for a root
 * @return array{parent_id: string|null, root_id: string, depth: int}
 */
function thread_position(?array $parent, string $new_id): array
{
    if ($parent === null) {
        return ['parent_id' => null, 'root_id' => $new_id, 'depth' => 0];
    }

    if ($parent['depth'] >= OPNG_MAX_THREAD_DEPTH) {
        throw new ThreadDepthException('Thread depth limit reached.');
    }

    return [
        'parent_id' => $parent['id'],
        'root_id' => $parent['root_id'],
        'depth' => $parent['depth'] + 1,
    ];
}

/**
 * Build the production history query.
 *
 * The previous `(root_id = ? OR id = ?)` form made MySQL choose index_merge
 * plus sort_union for the two access paths. Two UNION ALL branches can each
 * use their own index; the outer ORDER BY only sorts the rows that belong to
 * this thread (PERF-13). The second branch keeps legacy roots with
 * root_id = NULL, and excludes the normal case where the root already belongs
 * to the first branch, so no row is duplicated.
 *
 * @param array{id: string, date: string|null}|null $cursor
 * @return array{sql: string, types: string, params: list<string>}
 */
function thread_page_query(string $root_id, ?array $cursor = null): array
{
    $columns = 'entries.id, entries.parent_id, entries.root_id, entries.depth,
                entries.topic, entries.date, entries.author_label, entries.user_id';
    $descendants = "SELECT {$columns} FROM entries
                    WHERE entries.root_id = ?
                      AND entries.deleted_at IS NULL
                      AND (entries.expires_at IS NULL OR entries.expires_at > NOW())";
    $root = "SELECT {$columns} FROM entries
             WHERE entries.id = ?
               AND (entries.root_id IS NULL OR entries.root_id <> ?)
               AND entries.deleted_at IS NULL
               AND (entries.expires_at IS NULL OR entries.expires_at > NOW())";
    $condition_types = '';
    $condition_params = [];
    if ($cursor !== null) {
        $condition = entry_cursor_condition($cursor, 'newer');
        $descendants .= ' AND ' . $condition['sql'];
        $root .= ' AND ' . $condition['sql'];
        $condition_types = str_repeat('s', count($condition['params']));
        $condition_params = $condition['params'];
    }

    $sql = "SELECT thread_entries.id, thread_entries.parent_id, thread_entries.root_id,
                   thread_entries.depth, thread_entries.topic, thread_entries.date,
                   thread_entries.author_label AS username, thread_entries.user_id
            FROM ({$descendants} UNION ALL {$root}) AS thread_entries
            ORDER BY thread_entries.date ASC, thread_entries.id ASC
            LIMIT " . (OPNG_THREAD_PAGE_SIZE + 1);

    // Parameter order follows the SQL text: the descendants branch consumes
    // its root id and cursor values first, then the legacy-root branch consumes
    // its own id, root comparison and cursor values.
    $types = 's' . $condition_types . 'ss' . $condition_types;
    $params = array_merge(
        [$root_id],
        $condition_params,
        [$root_id, $root_id],
        $condition_params
    );

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

/**
 * Fetch one chronological page of a thread.
 *
 * @param array{id: string, date: string|null}|null $cursor
 * @return array{
 *   nodes: list<array{id: string, parent_id: string|null, root_id: string|null, depth: int, topic: string, date: string, username: string, guest: bool}>,
 *   truncated: bool,
 *   next_cursor: string|null
 * }
 */
function thread_fetch_page(mysqli $db, string $root_id, ?array $cursor = null): array
{
    $query = thread_page_query($root_id, $cursor);
    $sql = $query['sql'];
    $types = $query['types'];
    $params = $query['params'];

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Thread query could not be prepared.');
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Thread query failed.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Thread result is unavailable.');
    }

    $nodes = [];
    while ($row = $result->fetch_assoc()) {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        if ($id === '') {
            continue;
        }
        $nodes[] = [
            'id' => $id,
            'parent_id' => is_string($row['parent_id'] ?? null) ? $row['parent_id'] : null,
            'root_id' => is_string($row['root_id'] ?? null) ? $row['root_id'] : null,
            'depth' => max(0, (int) ($row['depth'] ?? 0)),
            'topic' => is_string($row['topic'] ?? null) ? $row['topic'] : '',
            'date' => is_string($row['date'] ?? null) ? $row['date'] : '',
            'username' => is_string($row['username'] ?? null) ? $row['username'] : 'Guest',
            'guest' => ($row['user_id'] ?? null) === null,
        ];
    }
    $result->free();
    $stmt->close();

    $truncated = count($nodes) > OPNG_THREAD_PAGE_SIZE;
    if ($truncated) {
        $nodes = array_slice($nodes, 0, OPNG_THREAD_PAGE_SIZE);
    }

    $next_cursor = null;
    if ($truncated) {
        $last = $nodes[count($nodes) - 1];
        $next_cursor = encode_entry_cursor(['id' => $last['id'], 'date' => $last['date']]);
    }

    return ['nodes' => $nodes, 'truncated' => $truncated, 'next_cursor' => $next_cursor];
}

/**
 * Ancestor IDs of the current node within the fetched page.
 *
 * @param list<array{id: string, parent_id: string|null}> $nodes
 * @return array<string, true>
 */
function thread_ancestor_ids(array $nodes, string $current_id): array
{
    $parents = [];
    foreach ($nodes as $node) {
        $parents[$node['id']] = $node['parent_id'];
    }

    $ancestors = [];
    $cursor = $parents[$current_id] ?? null;
    $guard = 0;
    while (
        $cursor !== null
        && array_key_exists($cursor, $parents)
        && !isset($ancestors[$cursor])
        && $guard <= OPNG_MAX_THREAD_DEPTH
    ) {
        $ancestors[$cursor] = true;
        $cursor = $parents[$cursor];
        $guard++;
    }

    return $ancestors;
}

/**
 * Group a page into a tree.
 *
 * Nodes whose parent is outside the page (or was deleted) become roots for
 * rendering, so the graph never loses a branch.
 *
 * @param list<array{id: string, parent_id: string|null}> $nodes
 * @return array{by_id: array<string, array<string, mixed>>, children: array<string, list<string>>, roots: list<string>}
 */
function thread_group(array $nodes): array
{
    $by_id = [];
    foreach ($nodes as $node) {
        $by_id[$node['id']] = $node;
    }

    $children = [];
    $roots = [];
    foreach ($nodes as $node) {
        $parent_id = $node['parent_id'];
        if ($parent_id !== null && isset($by_id[$parent_id]) && $parent_id !== $node['id']) {
            $children[$parent_id][] = $node['id'];
        } else {
            $roots[] = $node['id'];
        }
    }

    return ['by_id' => $by_id, 'children' => $children, 'roots' => $roots];
}
