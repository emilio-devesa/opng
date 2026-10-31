<?php
// includes/ratelimit.php
// Rate limiting persistente en MySQL (fix S5/R8).
//
// El contador de sesión de login.php se puede saltar borrando la cookie, así
// que además llevamos un contador por IP (y por IP+cuenta en el login) en la
// tabla `rate_limits`. La clave se guarda hasheada: no almacenamos IPs ni
// emails en claro.
//
// Uso:
//   if (!rate_limit_hit($db, 'login', client_ip() . '|' . $email, 5, 600)) { ... }
//
// `$limit` es el número máximo de intentos permitidos dentro de `$window`
// segundos. Si la tabla no existe o hay un error de BD, se deniega la acción
// (fail-closed) y se registra el error para no omitir silenciosamente el límite.

/**
 * IP del cliente. No se confía en X-Forwarded-For por defecto porque el
 * cliente puede falsearla; si OPNG está detrás de un proxy de confianza,
 * conviene adaptar esta función.
 */
function client_ip(): string
{
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($remote_addr) && filter_var($remote_addr, FILTER_VALIDATE_IP) !== false
        ? $remote_addr
        : '0.0.0.0';
}

/**
 * Registra un intento para (acción, clave) y devuelve true si sigue dentro
 * del límite.
 */
function rate_limit_hit(mysqli $db, string $action, string $key, int $limit, int $window): bool
{
    try {
        return record_rate_limit_hit($db, $action, $key, $limit, $window);
    } catch (mysqli_sql_exception $exception) {
        error_log('OPNG ratelimit.php: ' . $exception->getMessage());
        return false;
    }
}

function record_rate_limit_hit(mysqli $db, string $action, string $key, int $limit, int $window): bool
{
    if ($limit < 1 || $window < 1 || $action === '' || strlen($action) > 32) {
        error_log('OPNG ratelimit.php: invalid rate-limit configuration');
        return false;
    }

    $window_start = intdiv(time(), $window) * $window;
    $key_hash = hash('sha256', $key);
    $stmt = null;

    try {
        // Record the hit and return the new counter in a single statement.
        // LAST_INSERT_ID(expr) exposes the updated value through insert_id;
        // this replaces the previous INSERT IGNORE + UPDATE + SELECT sequence.
        $stmt = $db->prepare(
            "INSERT INTO rate_limits (action, key_hash, window_start, hits)
             VALUES (?, ?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE
                hits = LAST_INSERT_ID(IF(window_start = ?, hits + 1, 1)),
                window_start = ?"
        );
        if (!$stmt) {
            error_log('OPNG ratelimit.php: ' . $db->error);
            return false;
        }
        $stmt->bind_param('ssiii', $action, $key_hash, $window_start, $window_start, $window_start);
        if (!$stmt->execute()) {
            error_log('OPNG ratelimit.php: ' . $stmt->error);
            return false;
        }
        $hits = (int) $db->insert_id;
        $stmt->close();
        $stmt = null;

        // Some MySQL/MariaDB drivers do not expose LAST_INSERT_ID(expr) when
        // the table has no AUTO_INCREMENT column. Fall back to a read.
        if ($hits < 1) {
            $stmt = $db->prepare("SELECT hits FROM rate_limits WHERE action = ? AND key_hash = ?");
            if (!$stmt) {
                error_log('OPNG ratelimit.php: ' . $db->error);
                return false;
            }
            $stmt->bind_param('ss', $action, $key_hash);
            if (!$stmt->execute()) {
                error_log('OPNG ratelimit.php: ' . $stmt->error);
                return false;
            }
            $result = $stmt->get_result();
            if (!$result) {
                error_log('OPNG ratelimit.php: could not read rate-limit count');
                return false;
            }
            $row = $result->fetch_assoc();
            $result->free();
            $stmt->close();
            $stmt = null;
            if (!$row) {
                error_log('OPNG ratelimit.php: rate-limit row disappeared');
                return false;
            }
            $hits = (int) $row['hits'];
        }

        // Cleanup of expired windows (1% of the calls).
        if (random_int(1, 100) === 1) {
            $threshold = time() - 86400;
            if (!$db->query("DELETE FROM rate_limits WHERE window_start < $threshold")) {
                error_log('OPNG ratelimit.php: failed to remove expired rate-limit rows: ' . $db->error);
            }
        }

        return $hits <= $limit;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
