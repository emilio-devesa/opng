<?php
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
 * @return array{id: string, root_id: string, depth: int}|null
 */
function thread_parent_for_reply(mysqli $db, string $parent_id, bool $lock = false): ?array
{
    $sql = 'SELECT id, COALESCE(root_id, id) AS root_id, depth FROM entries WHERE id = ?';
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
        'root_id' => is_string($row['root_id'] ?? null) && $row['root_id'] !== ''
            ? $row['root_id']
            : $row['id'],
        'depth' => max(0, (int) ($row['depth'] ?? 0)),
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
 * Fetch one chronological page of a thread.
 *
 * @param array{id: string, date: string|null}|null $cursor
 * @return array{
 *   nodes: list<array{id: string, parent_id: string|null, root_id: string|null, depth: int, topic: string, date: string, username: string|null}>,
 *   truncated: bool,
 *   next_cursor: string|null
 * }
 */
function thread_fetch_page(mysqli $db, string $root_id, ?array $cursor = null): array
{
    $sql = "SELECT entries.id, entries.parent_id, entries.root_id, entries.depth,
                   entries.topic, entries.date, u.username
            FROM entries
            LEFT JOIN users u ON u.id = entries.user_id
            WHERE entries.root_id = ?";
    $types = 's';
    $params = [$root_id];

    if ($cursor !== null) {
        $condition = entry_cursor_condition($cursor, 'newer');
        $sql .= ' AND ' . $condition['sql'];
        foreach ($condition['params'] as $parameter) {
            $types .= 's';
            $params[] = $parameter;
        }
    }

    $sql .= ' ORDER BY entries.date ASC, entries.id ASC LIMIT ' . (OPNG_THREAD_PAGE_SIZE + 1);

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
            'username' => is_string($row['username'] ?? null) ? $row['username'] : null,
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
 * @return array{by_id: array<string, array>, children: array<string, list<string>>, roots: list<string>}
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
