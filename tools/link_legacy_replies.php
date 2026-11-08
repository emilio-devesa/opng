<?php
/**
 * Link legacy "RE: " pastes to their parent as real thread replies (F1).
 *
 * Usage (from the repository root):
 *   php tools/link_legacy_replies.php --dry-run [--limit=50]
 *   php tools/link_legacy_replies.php --apply --reply=REPLY_ID --parent=PARENT_ID
 *
 * - CLI only, and uses the credentials from src/config.php.
 * - The --dry-run mode never writes: it lists candidates or validates a pair of IDs.
 * - The --apply mode requires explicit, reviewed IDs. It never guesses relations:
 *   it checks the "RE: " prefix, the topic, the time order, that the reply
 *   is still unrelated, and recomputes root_id/depth for its whole subtree.
 * - Every applied link is recorded in audit_log.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/includes/thread.php';
require __DIR__ . '/../src/includes/audit_log.php';

/**
 * @return array<string, string|bool>
 */
function link_legacy_options(array $argv): array
{
    $options = [
        'dry-run' => false,
        'apply' => false,
        'reply' => '',
        'parent' => '',
        'limit' => '50',
        'help' => false,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['dry-run'] = true;
        } elseif ($argument === '--apply') {
            $options['apply'] = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
        } elseif (str_starts_with($argument, '--reply=')) {
            $options['reply'] = substr($argument, 8);
        } elseif (str_starts_with($argument, '--parent=')) {
            $options['parent'] = substr($argument, 9);
        } elseif (str_starts_with($argument, '--limit=')) {
            $options['limit'] = substr($argument, 8);
        } else {
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
        }
    }

    return $options;
}

function link_legacy_usage(): never
{
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php tools/link_legacy_replies.php --dry-run [--limit=50]\n"
        . "  php tools/link_legacy_replies.php --apply --reply=REPLY_ID --parent=PARENT_ID\n"
    );
    exit(2);
}

/**
 * Strip the visual "RE: " prefix used by legacy replies.
 */
function link_legacy_topic_without_prefix(string $topic): ?string
{
    if (preg_match('/\ARE:\s*/i', $topic) !== 1) {
        return null;
    }

    return trim(substr($topic, 4));
}

function link_legacy_same_topic(string $left, string $right): bool
{
    return $left !== ''
        && $right !== ''
        && mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
}

/**
 * Recompute root_id/depth for a node and every descendant.
 *
 * Legacy replies were inserted as roots, so their children (if any) still
 * point at the old root. The traversal runs inside the caller's transaction.
 *
 * @return array{updated: int, root_id: string, depth: int}
 */
function link_legacy_apply_position(
    mysqli $db,
    string $reply_id,
    string $parent_id,
    string $root_id,
    int $depth
): array {
    if ($depth > OPNG_MAX_THREAD_DEPTH) {
        throw new RuntimeException('The reply and its subtree would exceed the maximum thread depth.');
    }

    $update = $db->prepare('UPDATE entries SET parent_id = ?, root_id = ?, depth = ? WHERE id = ?');
    $update->bind_param('ssis', $parent_id, $root_id, $depth, $reply_id);
    $update->execute();
    $update->close();

    $updated = 1;
    $queue = [[$reply_id, $depth]];
    $children_select = $db->prepare(
        'SELECT id FROM entries WHERE parent_id = ? ORDER BY date ASC, id ASC FOR UPDATE'
    );
    $update_child = $db->prepare('UPDATE entries SET root_id = ?, depth = ? WHERE id = ?');
    while ($queue !== []) {
        [$current_id, $current_depth] = array_shift($queue);
        $children_select->bind_param('s', $current_id);
        $children_select->execute();
        $children = $children_select->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($children as $child) {
            $child_id = (string) ($child['id'] ?? '');
            $child_depth = $current_depth + 1;
            if ($child_id === '' || $child_depth > OPNG_MAX_THREAD_DEPTH) {
                $children_select->close();
                $update_child->close();
                throw new RuntimeException('The reply subtree would exceed the maximum thread depth.');
            }
            $update_child->bind_param('sis', $root_id, $child_depth, $child_id);
            $update_child->execute();
            $updated++;
            $queue[] = [$child_id, $child_depth];
        }
    }
    $children_select->close();
    $update_child->close();

    return ['updated' => $updated, 'root_id' => $root_id, 'depth' => $depth];
}

/**
 * Validate one explicit pair and return the rows that would be linked.
 *
 * @return array{reply: array<string, mixed>, parent: array<string, mixed>, root_id: string, depth: int}
 */
function link_legacy_validate_pair(mysqli $db, string $reply_id, string $parent_id): array
{
    $select = $db->prepare(
        'SELECT id, topic, date, parent_id, root_id, depth, user_id
         FROM entries WHERE id = ? FOR UPDATE'
    );
    $rows = [];
    foreach (['reply' => $reply_id, 'parent' => $parent_id] as $label => $id) {
        $select->bind_param('s', $id);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        if (!is_array($row)) {
            $select->close();
            throw new RuntimeException("The {$label} entry does not exist.");
        }
        $rows[$label] = $row;
    }
    $select->close();

    $reply = $rows['reply'];
    $parent = $rows['parent'];
    if ($reply_id === $parent_id) {
        throw new RuntimeException('A paste cannot be linked to itself.');
    }
    if ($reply['parent_id'] !== null || (int) $reply['depth'] !== 0) {
        throw new RuntimeException('The reply is already part of a thread.');
    }

    $reply_topic = (string) ($reply['topic'] ?? '');
    $parent_topic = trim((string) ($parent['topic'] ?? ''));
    $stripped = link_legacy_topic_without_prefix($reply_topic);
    if ($stripped === null || !link_legacy_same_topic($stripped, $parent_topic)) {
        throw new RuntimeException('The reply topic does not match "RE: <parent topic>".');
    }
    if (strcmp((string) $reply['date'], (string) $parent['date']) < 0) {
        throw new RuntimeException('The reply date is earlier than the parent date.');
    }

    $parent_depth = max(0, (int) ($parent['depth'] ?? 0));
    if ($parent_depth >= OPNG_MAX_THREAD_DEPTH) {
        throw new RuntimeException('The parent is already at the maximum thread depth.');
    }

    $root_id = is_string($parent['root_id'] ?? null) && $parent['root_id'] !== ''
        ? $parent['root_id']
        : (string) $parent['id'];

    return [
        'reply' => $reply,
        'parent' => $parent,
        'root_id' => $root_id,
        'depth' => $parent_depth + 1,
    ];
}

/**
 * Count descendants and the deepest relative level below one node.
 *
 * MySQL 5.7 has no recursive CTEs, so the traversal is a bounded PHP queue.
 *
 * @return array{count: int, max_depth: int}
 */
function link_legacy_subtree_metrics(mysqli $db, string $reply_id): array
{
    $select = $db->prepare(
        'SELECT id FROM entries WHERE parent_id = ? ORDER BY date ASC, id ASC'
    );
    $queue = [[$reply_id, 0]];
    $count = 0;
    $max_depth = 0;
    while ($queue !== []) {
        [$current_id, $current_depth] = array_shift($queue);
        $select->bind_param('s', $current_id);
        $select->execute();
        $children = $select->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($children as $child) {
            $child_id = (string) ($child['id'] ?? '');
            if ($child_id === '') {
                continue;
            }
            $count++;
            $max_depth = max($max_depth, $current_depth + 1);
            $queue[] = [$child_id, $current_depth + 1];
        }
    }
    $select->close();

    return ['count' => $count, 'max_depth' => $max_depth];
}

/**
 * @return list<array<string, mixed>>
 */
function link_legacy_candidates(mysqli $db, int $limit): array
{
    $stmt = $db->prepare(
        "SELECT r.id AS reply_id, r.topic AS reply_topic, r.date AS reply_date,
                r.user_id AS reply_user_id,
                p.id AS parent_id, p.topic AS parent_topic, p.date AS parent_date,
                p.user_id AS parent_user_id
         FROM entries r
         JOIN entries p
           ON p.topic = TRIM(SUBSTRING(r.topic, 4))
          AND p.id <> r.id
          AND p.date <= r.date
         WHERE r.topic LIKE 'RE: %'
           AND r.parent_id IS NULL
           AND r.depth = 0
           AND (r.user_id IS NULL OR p.user_id IS NULL OR r.user_id <> p.user_id)
         ORDER BY r.date ASC, r.id ASC, p.date DESC, p.id ASC
         LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

try {
    $options = link_legacy_options($argv);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    link_legacy_usage();
}

if ($options['help'] === true) {
    link_legacy_usage();
}
if ($options['dry-run'] === (bool) $options['apply']) {
    fwrite(STDERR, "ERROR: choose exactly one of --dry-run or --apply.\n");
    link_legacy_usage();
}

$reply_id = trim((string) $options['reply']);
$parent_id = trim((string) $options['parent']);
$apply = (bool) $options['apply'];
if ($apply && ($reply_id === '' || $parent_id === '')) {
    fwrite(STDERR, "ERROR: --apply requires explicit --reply=ID and --parent=ID.\n");
    link_legacy_usage();
}

$limit = filter_var($options['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
if ($limit === false) {
    fwrite(STDERR, "ERROR: --limit must be an integer between 1 and 500.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($mysql_server, $mysql_username, $mysql_password, $mysql_dbname);
    $db->set_charset('utf8mb4');

    if ($reply_id !== '' || $parent_id !== '') {
        if ($reply_id === '' || $parent_id === '') {
            throw new RuntimeException('Provide both --reply=ID and --parent=ID.');
        }

        $db->begin_transaction();
        try {
            $pair = link_legacy_validate_pair($db, $reply_id, $parent_id);
            $subtree = link_legacy_subtree_metrics($db, $reply_id);
            if ($pair['depth'] + $subtree['max_depth'] > OPNG_MAX_THREAD_DEPTH) {
                throw new RuntimeException('The reply subtree would exceed the maximum thread depth.');
            }

            if ($apply) {
                $position = link_legacy_apply_position(
                    $db,
                    $reply_id,
                    $parent_id,
                    $pair['root_id'],
                    $pair['depth']
                );
                audit_log_record(
                    $db,
                    null,
                    'entry.link_legacy',
                    'reply:' . $reply_id . ' parent:' . $parent_id
                );
                if (!$db->commit()) {
                    throw new RuntimeException('The link transaction could not be committed.');
                }
                echo "LINKED: reply {$reply_id} -> parent {$parent_id} "
                    . "(root {$position['root_id']}, depth {$position['depth']}, "
                    . "{$position['updated']} row(s) updated)\n";
            } else {
                $db->rollback();
                echo "WOULD LINK: reply {$reply_id} -> parent {$parent_id} "
                    . "(root {$pair['root_id']}, depth {$pair['depth']}, "
                    . "{$subtree['count']} descendant(s))\n";
            }
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    } else {
        if ($apply) {
            throw new RuntimeException('--apply requires explicit --reply=ID and --parent=ID.');
        }
        $candidates = link_legacy_candidates($db, $limit);
        if ($candidates === []) {
            echo "No legacy reply candidates found. Use --apply with reviewed IDs if you need a manual link.\n";
        } else {
            echo "Legacy reply candidates ({$limit} maximum). Review them, then apply explicit pairs:\n";
            foreach ($candidates as $candidate) {
                $reply_user = $candidate['reply_user_id'] === null ? 'guest' : 'user:' . $candidate['reply_user_id'];
                $parent_user = $candidate['parent_user_id'] === null ? 'guest' : 'user:' . $candidate['parent_user_id'];
                echo '- reply ' . $candidate['reply_id'] . ' (' . $reply_user . ', ' . $candidate['reply_date'] . ')'
                    . ' -> parent ' . $candidate['parent_id'] . ' (' . $parent_user . ', ' . $candidate['parent_date'] . ')'
                    . ' :: ' . $candidate['reply_topic'] . "\n";
            }
            echo "Apply a reviewed pair with:\n"
                . "  php tools/link_legacy_replies.php --apply --reply=REPLY_ID --parent=PARENT_ID\n";
        }
    }

    $db->close();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    exit(1);
}
