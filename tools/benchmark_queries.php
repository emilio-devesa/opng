<?php

/**
 * Reproducible phase 10 benchmark (PERF-10/12/13/14/17).
 *
 * Usage (from the repository root):
 *   OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 \
 *   OPNG_TEST_DB_USER=root OPNG_TEST_DB_PASS=secret \
 *   php tools/benchmark_queries.php
 *
 * Optional:
 *   --rows=20000       synthetic entries (default 20000)
 *   --iterations=10    timed iterations per query (default 10)
 *   --help
 *
 * The script creates a disposable `opng_bench_*` database from
 * database/schema.sql, measures the legacy query/form against the current
 * production path on the same rows, prints a Markdown table and drops the
 * database. It never touches the configured application database.
 *
 * PERF-15 (browser highlighting) cannot be measured in PHP CLI; its manual
 * browser procedure is documented in docs/PERFORMANCE.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../src/includes/schema.php';
require __DIR__ . '/../src/includes/user_quota.php';
require __DIR__ . '/../src/includes/pagination_cursor.php';
require __DIR__ . '/../src/includes/thread.php';
require __DIR__ . '/../src/includes/i18n.php';

const BENCHMARK_DEFAULT_ROWS = 20000;
const BENCHMARK_DEFAULT_ITERATIONS = 10;

/**
 * @return array{rows: int, iterations: int}
 */
function benchmark_options(array $argv): array
{
    $rows = BENCHMARK_DEFAULT_ROWS;
    $iterations = BENCHMARK_DEFAULT_ITERATIONS;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            echo "Usage: php tools/benchmark_queries.php [--rows=N] [--iterations=N]\n";
            exit(0);
        }
        if (preg_match('/\A--rows=([0-9]+)\z/', $argument, $match) === 1) {
            $rows = max(100, (int) $match[1]);
            continue;
        }
        if (preg_match('/\A--iterations=([0-9]+)\z/', $argument, $match) === 1) {
            $iterations = max(1, (int) $match[1]);
            continue;
        }

        fwrite(STDERR, "Unknown option: {$argument}\n");
        fwrite(STDERR, "Usage: php tools/benchmark_queries.php [--rows=N] [--iterations=N]\n");
        exit(2);
    }

    return ['rows' => $rows, 'iterations' => $iterations];
}

/**
 * Median and mean milliseconds for a callable.
 *
 * @return array{median: float, mean: float, min: float}
 */
function benchmark_measure(callable $operation, int $iterations): array
{
    $operation();

    $samples = [];
    for ($index = 0; $index < $iterations; $index++) {
        $started = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $started) / 1e6;
    }
    sort($samples);
    $middle = intdiv(count($samples), 2);
    $median = count($samples) % 2 === 0
        ? ($samples[$middle - 1] + $samples[$middle]) / 2
        : $samples[$middle];

    return [
        'median' => $median,
        'mean' => array_sum($samples) / count($samples),
        'min' => $samples[0],
    ];
}

function benchmark_print_row(
    string $finding,
    string $legacy,
    string $current,
    array $legacy_result,
    array $current_result
): void {
    $speedup = $current_result['median'] > 0
        ? $legacy_result['median'] / $current_result['median']
        : 0.0;

    printf(
        "| %s | %s | %s | %.3f ms | %.3f ms | %.1fx |\n",
        $finding,
        $legacy,
        $current,
        $legacy_result['median'],
        $current_result['median'],
        $speedup
    );
}

/**
 * EXPLAIN a query, optionally with bound parameters.
 *
 * @return list<array<string, mixed>>
 */
function benchmark_explain(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare('EXPLAIN ' . $sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $stmt->close();

    return $rows;
}

/**
 * Compact plan description for the Markdown report.
 */
function benchmark_plan_summary(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = sprintf(
            '%s type=%s key=%s extra=%s',
            (string) ($row['table'] ?? '?'),
            (string) ($row['type'] ?? '?'),
            (string) ($row['key'] ?? 'NULL'),
            (string) ($row['Extra'] ?? '')
        );
    }

    return implode(' | ', $parts);
}

/**
 * Legacy full-document translation used by the phase 10 audit, reproduced
 * without the <pre>/<textarea> protection added by PERF-14.
 */
function benchmark_legacy_i18n_apply(string $html): string
{
    $locale = opng_current_locale();
    $catalog = opng_catalog($locale);
    if ($catalog === []) {
        return $html;
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML(
        '<?xml encoding="utf-8" ?>' . $html,
        LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$document->documentElement instanceof DOMElement) {
        return $html;
    }

    $document->documentElement->setAttribute('lang', $locale);
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//*[@data-i18n]') as $node) {
        if (!$node instanceof DOMElement || opng_node_has_element_children($node)) {
            continue;
        }
        $key = $node->getAttribute('data-i18n');
        if (isset($catalog[$key])) {
            $node->textContent = $catalog[$key];
        }
    }

    $output = $document->saveHTML();
    if (!is_string($output)) {
        return $html;
    }

    return preg_replace('/<\?xml[^>]*\?>\s*/', '', $output) ?? $output;
}

$options = benchmark_options($argv);

if (getenv('OPNG_TEST_ALLOW_DATABASE_FIXTURES') !== '1') {
    echo "SKIP: set OPNG_TEST_ALLOW_DATABASE_FIXTURES=1 to run the benchmark.\n";
    exit(0);
}

$host = getenv('OPNG_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('OPNG_TEST_DB_PORT') ?: 3306);
$user = getenv('OPNG_TEST_DB_USER') ?: '';
$password = getenv('OPNG_TEST_DB_PASS') ?: '';

if ($user === '') {
    echo "SKIP: set OPNG_TEST_DB_USER (and OPNG_TEST_DB_PASS) to run the benchmark.\n";
    exit(0);
}

$name = 'opng_bench_' . strtolower(bin2hex(random_bytes(4)));
if (preg_match('/\Aopng_bench_[a-z0-9]+\z/', $name) !== 1) {
    fwrite(STDERR, "FAIL: unsafe disposable database name\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $admin = new mysqli($host, $user, $password, null, $port);
    $admin->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    echo 'SKIP: could not connect to the disposable test server: ' . $exception->getMessage() . "\n";
    exit(0);
}

$db = null;
try {
    $admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $password, $name, $port);
    $db->set_charset('utf8mb4');

    $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if (!is_string($schema_sql)) {
        throw new RuntimeException('database/schema.sql is unreadable');
    }
    foreach (schema_split_sql($schema_sql) as $statement) {
        $db->query($statement);
    }

    echo "Creating benchmark fixtures ({$options['rows']} entries)...\n";

    $db->begin_transaction();
    $password_hash = password_hash('benchmark-fixture-password', PASSWORD_DEFAULT);
    $insert_user = $db->prepare(
        "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')"
    );
    $usernames = [];
    $user_ids = [];
    for ($index = 0; $index < 200; $index++) {
        $username = sprintf('bench-author-%03d', $index);
        $email = $username . '@example.test';
        $insert_user->bind_param('sss', $username, $email, $password_hash);
        $insert_user->execute();
        $user_ids[] = (int) $insert_user->insert_id;
        $usernames[] = $username;
    }
    $insert_user->close();

    $insert_entry = $db->prepare(
        "INSERT INTO entries (id, user_id, author_label, language, text, topic, date)
         VALUES (?, ?, ?, 'text', ?, ?, ?)"
    );
    $base = 1700000000;
    $owner_id = $user_ids[0];
    $owner_username = $usernames[0];
    $inserted = 0;
    for ($index = 0; $index < $options['rows']; $index++) {
        $id = sprintf('bench-entry-%06d', $index);
        if ($index < intdiv($options['rows'], 2)) {
            $entry_user = $owner_id;
            $entry_username = $owner_username;
        } else {
            $user_index = 1 + ($index % 199);
            $entry_user = $user_ids[$user_index];
            $entry_username = $usernames[$user_index];
        }
        $text = str_repeat('Benchmark text payload. ', 8);
        $topic = 'Benchmark topic ' . ($index % 50);
        $date = gmdate('Y-m-d H:i:s', $base + $index);
        $insert_entry->bind_param('sissss', $id, $entry_user, $entry_username, $text, $topic, $date);
        $insert_entry->execute();
        $inserted++;
    }
    $insert_entry->close();

    // A guest-owned 1 MiB paste for the i18n path; it stays out of the
    // account-quota fixture so the SUM comparison remains a normal account.
    $large_payload = str_repeat('Large paste payload with accents: ñáéíóú. ', 24000);
    $large_id = 'bench-large-paste';
    $large_author = 'Guest';
    $large_topic = 'Large benchmark paste';
    $large_date = gmdate('Y-m-d H:i:s', $base + $options['rows']);
    $large_stmt = $db->prepare(
        "INSERT INTO entries (id, user_id, author_label, language, text, topic, date)
         VALUES (?, NULL, ?, 'text', ?, ?, ?)"
    );
    $large_stmt->bind_param('sssss', $large_id, $large_author, $large_payload, $large_topic, $large_date);
    $large_stmt->execute();
    $large_stmt->close();

    // A 500-node thread for PERF-13.
    $thread_root = 'bench-thread-root';
    $thread_author = $owner_username;
    $thread_date = gmdate('Y-m-d H:i:s', $base + $options['rows'] + 1);
    $thread_text = 'Thread benchmark fixture.';
    $thread_stmt = $db->prepare(
        'INSERT INTO entries (id, parent_id, root_id, depth, user_id, author_label, language, text, topic, date)
         VALUES (?, ?, ?, ?, ?, ?, \'text\', ?, ?, ?)'
    );
    $thread_parent = null;
    $thread_depth = 0;
    for ($index = 0; $index < 500; $index++) {
        $thread_id = $index === 0 ? $thread_root : sprintf('bench-thread-%03d', $index);
        $thread_topic = 'Thread benchmark ' . $index;
        $thread_stmt->bind_param(
            'sssiissss',
            $thread_id,
            $thread_parent,
            $thread_root,
            $thread_depth,
            $owner_id,
            $thread_author,
            $thread_text,
            $thread_topic,
            $thread_date
        );
        $thread_stmt->execute();
        $thread_parent = $thread_id;
        $thread_depth = 1;
    }
    $thread_stmt->close();
    $db->commit();

    // Keep the denormalized counters faithful to the fixtures so the current
    // path reads the same logical values as the aggregate it replaces.
    $db->query(
        "UPDATE users u
         LEFT JOIN (
             SELECT user_id, COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes
             FROM entries
             WHERE user_id IS NOT NULL
             GROUP BY user_id
         ) AS entry_usage ON entry_usage.user_id = u.id
         SET u.used_bytes = COALESCE(entry_usage.used_bytes, 0)"
    );
    $db->query(
        "UPDATE storage_counters
         SET used_entries = (SELECT COUNT(*) FROM entries),
             used_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries)
         WHERE counter_key = 'global'"
    );

    $db->query('ANALYZE TABLE entries, users');

    echo "Running benchmarks...\n\n";

    $legacy_quota = $db->prepare(
        'SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) AS used_bytes FROM entries WHERE user_id = ?'
    );
    $current_quota = $db->prepare('SELECT role, used_bytes, username FROM users WHERE id = ?');
    $quota_legacy = benchmark_measure(static function () use ($legacy_quota, $owner_id): void {
        $legacy_quota->bind_param('i', $owner_id);
        $legacy_quota->execute();
        $result = $legacy_quota->get_result();
        $result->fetch_assoc();
        $result->free();
    }, $options['iterations']);
    $quota_current = benchmark_measure(static function () use ($current_quota, $owner_id): void {
        $current_quota->bind_param('i', $owner_id);
        $current_quota->execute();
        $result = $current_quota->get_result();
        $result->fetch_assoc();
        $result->free();
    }, $options['iterations']);
    $legacy_quota->close();
    $current_quota->close();

    $legacy_author_sql = "SELECT entries.id, entries.user_id, users.username, entries.topic,
                                 entries.language, entries.date
                          FROM entries
                          LEFT JOIN users ON entries.user_id = users.id
                          ORDER BY COALESCE(users.username, 'Guest') ASC, entries.id ASC
                          LIMIT 21";
    $current_author = build_listing_query(null, null, null, 'author', 'asc');
    $author_legacy = benchmark_measure(static function () use ($db, $legacy_author_sql): void {
        $result = $db->query($legacy_author_sql);
        $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }, $options['iterations']);
    $author_current = benchmark_measure(static function () use ($db, $current_author): void {
        $result = $db->query($current_author['sql']);
        $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }, $options['iterations']);

    $legacy_thread_sql = "SELECT entries.id, entries.parent_id, entries.root_id, entries.depth,
                                 entries.topic, entries.date, COALESCE(users.username, 'Guest') AS username
                          FROM entries
                          LEFT JOIN users ON users.id = entries.user_id
                          WHERE entries.root_id = ? OR entries.id = ?
                          ORDER BY entries.date ASC, entries.id ASC
                          LIMIT 501";
    $thread_query = thread_page_query($thread_root);
    $legacy_thread = $db->prepare($legacy_thread_sql);
    $thread_legacy = benchmark_measure(static function () use ($legacy_thread, $thread_root): void {
        $legacy_thread->bind_param('ss', $thread_root, $thread_root);
        $legacy_thread->execute();
        $result = $legacy_thread->get_result();
        $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }, $options['iterations']);
    $thread_current = benchmark_measure(static function () use ($db, $thread_query): void {
        $stmt = $db->prepare($thread_query['sql']);
        $stmt->bind_param($thread_query['types'], ...$thread_query['params']);
        $stmt->execute();
        $result = $stmt->get_result();
        $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
    }, $options['iterations']);
    $legacy_thread->close();

    $legacy_summary_sql = 'SELECT
        (SELECT COUNT(*) FROM entries) AS total_entries,
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COALESCE(SUM(OCTET_LENGTH(text)), 0) FROM entries) AS paste_bytes';
    $current_summary_sql = "SELECT
        (SELECT used_entries FROM storage_counters WHERE counter_key = 'global') AS total_entries,
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT used_bytes FROM storage_counters WHERE counter_key = 'global') AS paste_bytes";
    $summary_legacy = benchmark_measure(static function () use ($db, $legacy_summary_sql): void {
        $result = $db->query($legacy_summary_sql);
        $result->fetch_assoc();
        $result->free();
    }, $options['iterations']);
    $summary_current = benchmark_measure(static function () use ($db, $current_summary_sql): void {
        $result = $db->query($current_summary_sql);
        $result->fetch_assoc();
        $result->free();
    }, $options['iterations']);

    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es';
    $i18n_payload = htmlspecialchars($large_payload, ENT_QUOTES, 'UTF-8');
    $i18n_html = '<html><body><p data-i18n="no_entries">No entries yet.</p>'
        . '<pre class="code-content"><code id="code-block">' . $i18n_payload . '</code></pre></body></html>';
    $i18n_legacy = benchmark_measure(static function () use ($i18n_html): void {
        benchmark_legacy_i18n_apply($i18n_html);
    }, 3);
    $i18n_current = benchmark_measure(static function () use ($i18n_html): void {
        opng_i18n_apply($i18n_html);
    }, 3);

    $i18n_legacy_output = benchmark_legacy_i18n_apply($i18n_html);
    $i18n_current_output = opng_i18n_apply($i18n_html);
    if (
        !str_contains($i18n_legacy_output, 'no hay entradas')
        || !str_contains($i18n_current_output, 'no hay entradas')
        || !str_contains($i18n_current_output, 'Large paste payload with accents')
    ) {
        throw new RuntimeException('The i18n benchmark did not translate the surrounding page or preserve the paste payload.');
    }

    $author_plan_legacy = benchmark_explain($db, $legacy_author_sql);
    $author_plan_current = benchmark_explain($db, $current_author['sql']);
    if (
        ($author_plan_current[0]['key'] ?? null) !== 'idx_entries_author'
        || str_contains(strtolower((string) ($author_plan_current[0]['Extra'] ?? '')), 'filesort')
    ) {
        throw new RuntimeException('The current author listing did not use idx_entries_author without filesort.');
    }
    $thread_plan_legacy = benchmark_explain(
        $db,
        $legacy_thread_sql,
        'ss',
        [$thread_root, $thread_root]
    );
    $thread_plan_current = benchmark_explain(
        $db,
        $thread_query['sql'],
        $thread_query['types'],
        $thread_query['params']
    );

    echo "| Hallazgo | Enfoque anterior | Enfoque actual | Antes (mediana) | Ahora (mediana) | Mejora |\n";
    echo "|---|---|---|---:|---:|---:|\n";
    benchmark_print_row('PERF-10', 'SUM por cuenta', 'contador users.used_bytes', $quota_legacy, $quota_current);
    benchmark_print_row('PERF-12', 'LEFT JOIN + COALESCE', 'entries.author_label', $author_legacy, $author_current);
    benchmark_print_row('PERF-13', 'root_id OR id', 'thread_page_query() UNION ALL', $thread_legacy, $thread_current);
    benchmark_print_row('PERF-14', 'DOM completo', 'DOM con pre/textarea protegidos', $i18n_legacy, $i18n_current);
    benchmark_print_row('PERF-17', 'COUNT/SUM del dashboard', 'contadores globales', $summary_legacy, $summary_current);

    echo "\n### Planes de consulta\n\n";
    echo '- PERF-12 anterior: `' . benchmark_plan_summary($author_plan_legacy) . "`\n";
    echo '- PERF-12 actual: `' . benchmark_plan_summary($author_plan_current) . "`\n";
    echo '- PERF-13 anterior: `' . benchmark_plan_summary($thread_plan_legacy) . "`\n";
    echo '- PERF-13 actual: `' . benchmark_plan_summary($thread_plan_current) . "`\n";

    echo "\n";
    echo "Muestra: {$options['rows']} entradas sintéticas, 500 nodos de hilo, 1 paste de ~1 MiB.\n";
    echo "Iteraciones: {$options['iterations']} por consulta (3 para i18n).\n";
    echo 'PHP: ' . PHP_VERSION . ' · MySQL: ' . $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'] . "\n";
    echo "Los tiempos son orientativos y dependen de la máquina y del estado de la caché.\n";
} catch (Throwable $exception) {
    if ($db instanceof mysqli) {
        try {
            $db->close();
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }
    try {
        $admin->query("DROP DATABASE IF EXISTS `{$name}`");
    } catch (Throwable) {
        // Best-effort cleanup; the original failure is more useful.
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}

$db->close();
$admin->query("DROP DATABASE `{$name}`");
$admin->close();
