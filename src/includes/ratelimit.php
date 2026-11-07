<?php

declare(strict_types=1);

// includes/ratelimit.php
// Rate limiting persistente en MySQL (fix S5/R8).
//
// El contador de sesión de login.php se puede saltar borrando la cookie, así
// que además llevamos un contador por IP (y por IP+cuenta en el login) en la
// tabla `rate_limits`. La clave se guarda hasheada: no almacenamos IPs ni
// emails en claro.
//
// Uso:
//   $result = rate_limit_hit($db, 'login', client_ip() . '|' . $email, 5, 600);
//   if ($result === RateLimitResult::Limited) { ... }      // 429
//   if ($result === RateLimitResult::Unavailable) { ... }  // 503
//
// `$limit` es el número máximo de intentos permitidos dentro de `$window`
// segundos. El resultado distingue un límite superado (`Limited`) de un fallo
// de base de datos (`Unavailable`): así una caída de MySQL no se presenta al
// usuario como "demasiados intentos" (M14). En ambos casos la acción se deniega
// (fail-closed) y el error se registra.

enum RateLimitResult
{
    case Allowed;
    case Limited;
    case Unavailable;
}

/**
 * Parse OPNG_TRUSTED_PROXIES (comma-separated IPs or CIDR ranges).
 *
 * @return list<string>
 */
function trusted_proxy_ranges(): array
{
    $raw = getenv('OPNG_TRUSTED_PROXIES');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $ranges = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        if (str_contains($entry, '/')) {
            [$subnet, $bits] = explode('/', $entry, 2);
            if (
                filter_var($subnet, FILTER_VALIDATE_IP) !== false
                && ctype_digit($bits)
                && (int) $bits <= (strlen((string) inet_pton($subnet)) * 8)
            ) {
                $ranges[] = $subnet . '/' . $bits;
            }
            continue;
        }

        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            $ranges[] = $entry;
        }
    }

    return $ranges;
}

function ip_in_ranges(string $ip, array $ranges): bool
{
    foreach ($ranges as $range) {
        if (!str_contains($range, '/')) {
            if ($ip === $range) {
                return true;
            }
            continue;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ip_binary = inet_pton($ip);
        $subnet_binary = inet_pton($subnet);
        if ($ip_binary === false || $subnet_binary === false || strlen($ip_binary) !== strlen($subnet_binary)) {
            continue;
        }

        $bits = (int) $bits;
        $whole_bytes = intdiv($bits, 8);
        $remaining_bits = $bits % 8;

        if ($whole_bytes > 0 && substr($ip_binary, 0, $whole_bytes) !== substr($subnet_binary, 0, $whole_bytes)) {
            continue;
        }
        if ($remaining_bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining_bits)) & 0xFF;
        if (
            (ord($ip_binary[$whole_bytes]) & $mask)
            === (ord($subnet_binary[$whole_bytes]) & $mask)
        ) {
            return true;
        }
    }

    return false;
}

function normalized_forwarded_ip(string $value): ?string
{
    $value = trim($value, " \t\"");
    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
        return $value;
    }

    // Bracketed IPv6 with an optional port: [2001:db8::1]:443
    if (
        preg_match('/\A\[([0-9a-fA-F:.]+)\](?::\d+)?\z/', $value, $matches) === 1
        && filter_var($matches[1], FILTER_VALIDATE_IP) !== false
    ) {
        return $matches[1];
    }

    // IPv4 with a port: 198.51.100.7:443
    if (
        preg_match('/\A(\d{1,3}(?:\.\d{1,3}){3}):\d+\z/', $value, $matches) === 1
        && filter_var($matches[1], FILTER_VALIDATE_IP) !== false
    ) {
        return $matches[1];
    }

    return null;
}

/**
 * IP del cliente.
 *
 * Without OPNG_TRUSTED_PROXIES the forwarded headers are ignored (a client can
 * forge them). When the direct peer is a configured proxy, X-Forwarded-For is
 * walked from right to left and the first untrusted address is returned, so a
 * client cannot prepend a fake address to the chain.
 */
function client_ip(): string
{
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    $remote = is_string($remote_addr) && filter_var($remote_addr, FILTER_VALIDATE_IP) !== false
        ? $remote_addr
        : '0.0.0.0';

    $trusted_proxies = trusted_proxy_ranges();
    if ($trusted_proxies === [] || !ip_in_ranges($remote, $trusted_proxies)) {
        return $remote;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if (!is_string($forwarded) || trim($forwarded) === '') {
        return $remote;
    }

    $candidates = array_reverse(explode(',', $forwarded));
    foreach ($candidates as $candidate) {
        $candidate_ip = normalized_forwarded_ip($candidate);
        if ($candidate_ip === null) {
            continue;
        }
        if (!ip_in_ranges($candidate_ip, $trusted_proxies)) {
            return $candidate_ip;
        }
    }

    return $remote;
}

/**
 * Read the current counter without recording a new attempt. Used to reject a
 * request before paying for bcrypt when the bucket is already exhausted.
 */
/**
 * Hash a rate-limit key for one specific window.
 *
 * Including the window in the hash lets the table keep the current and the
 * previous bucket as separate rows without changing the primary key. The
 * sliding-window estimate then weighs both buckets (ROB-09).
 */
function rate_limit_window_hash(string $key, int $window_start): string
{
    return hash('sha256', $key . '|' . $window_start);
}

/**
 * Weighted estimate for the sliding window.
 *
 * The previous bucket keeps the fraction of its window that still overlaps
 * the current one. At the start of a new window its weight is 1, so a burst
 * cannot double the allowed rate at the boundary; it decays linearly to 0.
 */
function rate_limit_estimate(int $current_hits, int $previous_hits, int $window_start, int $window, int $now): float
{
    if ($window < 1) {
        return 0.0;
    }

    $elapsed = max(0, min($window, $now - $window_start));
    $previous_weight = ($window - $elapsed) / $window;

    return $current_hits + ($previous_hits * $previous_weight);
}

function rate_limit_status(mysqli $db, string $action, string $key, int $limit, int $window): RateLimitResult
{
    if ($limit < 1 || $window < 1 || $action === '' || strlen($action) > 32) {
        error_log('OPNG ratelimit.php: invalid rate-limit configuration');
        return RateLimitResult::Unavailable;
    }

    $now = time();
    $window_start = intdiv($now, $window) * $window;
    $previous_start = $window_start - $window;
    $current_hash = rate_limit_window_hash($key, $window_start);
    $previous_hash = rate_limit_window_hash($key, $previous_start);
    $stmt = null;

    try {
        $stmt = $db->prepare(
            'SELECT window_start, hits FROM rate_limits
             WHERE action = ? AND key_hash IN (?, ?)'
        );
        if (!$stmt) {
            error_log('OPNG ratelimit.php: ' . $db->error);
            return RateLimitResult::Unavailable;
        }
        $stmt->bind_param('sss', $action, $current_hash, $previous_hash);
        if (!$stmt->execute()) {
            error_log('OPNG ratelimit.php: ' . $stmt->error);
            return RateLimitResult::Unavailable;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('OPNG ratelimit.php: could not read the rate-limit counter');
            return RateLimitResult::Unavailable;
        }
        $current_hits = 0;
        $previous_hits = 0;
        while ($row = $result->fetch_assoc()) {
            if ((int) $row['window_start'] === $window_start) {
                $current_hits = (int) $row['hits'];
            } elseif ((int) $row['window_start'] === $previous_start) {
                $previous_hits = (int) $row['hits'];
            }
        }
        $result->free();

        $estimate = rate_limit_estimate($current_hits, $previous_hits, $window_start, $window, $now);

        return $estimate >= $limit ? RateLimitResult::Limited : RateLimitResult::Allowed;
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG ratelimit.php: ' . $exception->getMessage());
        return RateLimitResult::Unavailable;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Remove the current and previous counters for (action, key). Called after a
 * successful login so a legitimate user never accumulates failed-attempt
 * budget.
 */
function rate_limit_clear(mysqli $db, string $action, string $key, int $window = 600): bool
{
    if ($action === '' || strlen($action) > 32 || $window < 1) {
        return false;
    }

    try {
        $window_start = intdiv(time(), $window) * $window;
        $hashes = [
            rate_limit_window_hash($key, $window_start),
            rate_limit_window_hash($key, $window_start - $window),
        ];
        $stmt = $db->prepare(
            'DELETE FROM rate_limits WHERE action = ? AND key_hash IN (?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sss', $action, $hashes[0], $hashes[1]);
        $deleted = $stmt->execute();
        $stmt->close();

        return $deleted;
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG ratelimit.php: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Registra un intento para (acción, clave) y devuelve si sigue dentro del
 * límite o si la comprobación no se pudo completar.
 */
function rate_limit_hit(mysqli $db, string $action, string $key, int $limit, int $window): RateLimitResult
{
    try {
        return record_rate_limit_hit($db, $action, $key, $limit, $window);
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG ratelimit.php: ' . $exception->getMessage());
        return RateLimitResult::Unavailable;
    }
}

function record_rate_limit_hit(mysqli $db, string $action, string $key, int $limit, int $window): RateLimitResult
{
    if ($limit < 1 || $window < 1 || $action === '' || strlen($action) > 32) {
        error_log('OPNG ratelimit.php: invalid rate-limit configuration');
        return RateLimitResult::Unavailable;
    }

    $now = time();
    $window_start = intdiv($now, $window) * $window;
    $previous_start = $window_start - $window;
    $key_hash = rate_limit_window_hash($key, $window_start);
    $previous_hash = rate_limit_window_hash($key, $previous_start);
    $stmt = null;

    try {
        // Record the hit and return the new counter in a single statement.
        // LAST_INSERT_ID(expr) exposes the updated value through insert_id;
        // this replaces the previous INSERT IGNORE + UPDATE + SELECT sequence.
        $stmt = $db->prepare(
            "INSERT INTO rate_limits (action, key_hash, window_start, hits)
             VALUES (?, ?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE
                hits = LAST_INSERT_ID(hits + 1)"
        );
        if (!$stmt) {
            error_log('OPNG ratelimit.php: ' . $db->error);
            return RateLimitResult::Unavailable;
        }
        $stmt->bind_param('ssi', $action, $key_hash, $window_start);
        if (!$stmt->execute()) {
            error_log('OPNG ratelimit.php: ' . $stmt->error);
            return RateLimitResult::Unavailable;
        }
        $hits = (int) $db->insert_id;
        $stmt->close();
        $stmt = null;

        // Some MySQL/MariaDB drivers do not expose LAST_INSERT_ID(expr) when
        // the table has no AUTO_INCREMENT column. Fall back to a read.
        if ($hits < 1) {
            $stmt = $db->prepare(
                'SELECT hits FROM rate_limits WHERE action = ? AND key_hash = ? AND window_start = ?'
            );
            if (!$stmt) {
                error_log('OPNG ratelimit.php: ' . $db->error);
                return RateLimitResult::Unavailable;
            }
            $stmt->bind_param('ssi', $action, $key_hash, $window_start);
            if (!$stmt->execute()) {
                error_log('OPNG ratelimit.php: ' . $stmt->error);
                return RateLimitResult::Unavailable;
            }
            $result = $stmt->get_result();
            if (!$result) {
                error_log('OPNG ratelimit.php: could not read rate-limit count');
                return RateLimitResult::Unavailable;
            }
            $row = $result->fetch_assoc();
            $result->free();
            $stmt->close();
            $stmt = null;
            if (!$row) {
                error_log('OPNG ratelimit.php: rate-limit row disappeared');
                return RateLimitResult::Unavailable;
            }
            $hits = (int) $row['hits'];
        }

        // Include the weighted previous bucket so a burst at the window edge
        // cannot double the effective rate.
        $previous_hits = 0;
        $stmt = $db->prepare(
            'SELECT hits FROM rate_limits WHERE action = ? AND key_hash = ? AND window_start = ?'
        );
        if (!$stmt) {
            error_log('OPNG ratelimit.php: ' . $db->error);
            return RateLimitResult::Unavailable;
        }
        $stmt->bind_param('ssi', $action, $previous_hash, $previous_start);
        if (!$stmt->execute()) {
            error_log('OPNG ratelimit.php: ' . $stmt->error);
            return RateLimitResult::Unavailable;
        }
        $previous_result = $stmt->get_result();
        if (!$previous_result) {
            error_log('OPNG ratelimit.php: could not read the previous rate-limit bucket');
            return RateLimitResult::Unavailable;
        }
        $previous_row = $previous_result->fetch_assoc();
        $previous_result->free();
        $stmt->close();
        $stmt = null;
        if (is_array($previous_row)) {
            $previous_hits = (int) $previous_row['hits'];
        }

        // Cleanup of expired windows (1% of the calls).
        if (random_int(1, 100) === 1) {
            $threshold = time() - 86400;
            if (!$db->query("DELETE FROM rate_limits WHERE window_start < $threshold")) {
                error_log('OPNG ratelimit.php: failed to remove expired rate-limit rows: ' . $db->error);
            }
        }

        $estimate = rate_limit_estimate($hits, $previous_hits, $window_start, $window, $now);

        return $estimate > $limit ? RateLimitResult::Limited : RateLimitResult::Allowed;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
