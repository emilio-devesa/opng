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
// segundos. Si la tabla no existe o hay un error de BD, se permite la acción
// (fail-open) para no bloquear el sitio por un problema de infraestructura; el
// error queda en el log.

/**
 * IP del cliente. No se confía en X-Forwarded-For por defecto porque el
 * cliente puede falsearla; si OPNG está detrás de un proxy de confianza,
 * conviene adaptar esta función.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Registra un intento para (acción, clave) y devuelve true si sigue dentro
 * del límite.
 */
function rate_limit_hit(mysqli $db, string $action, string $key, int $limit, int $window): bool
{
    $window_start = intdiv(time(), $window) * $window;
    $key_hash = hash('sha256', $key);

    // 1. Asegurar la fila (sin modificar el contador todavía).
    $stmt = $db->prepare(
        "INSERT IGNORE INTO rate_limits (action, key_hash, window_start, hits) VALUES (?, ?, ?, 0)"
    );
    if (!$stmt) {
        error_log('OPNG ratelimit.php: ' . $db->error);
        return true; // fail-open
    }
    $stmt->bind_param('ssi', $action, $key_hash, $window_start);
    if (!$stmt->execute()) {
        error_log('OPNG ratelimit.php: ' . $stmt->error);
        $stmt->close();
        return true;
    }
    $stmt->close();

    // 2. Incrementar, reiniciando el contador si cambió la ventana.
    //    (Evitamos VALUES() en ON DUPLICATE KEY por compatibilidad MySQL/MariaDB.)
    $stmt = $db->prepare(
        "UPDATE rate_limits
            SET hits = IF(window_start = ?, hits + 1, 1),
                window_start = ?
          WHERE action = ? AND key_hash = ?"
    );
    if (!$stmt) {
        error_log('OPNG ratelimit.php: ' . $db->error);
        return true;
    }
    $stmt->bind_param('iiss', $window_start, $window_start, $action, $key_hash);
    if (!$stmt->execute()) {
        error_log('OPNG ratelimit.php: ' . $stmt->error);
        $stmt->close();
        return true;
    }
    $stmt->close();

    // 3. Leer el contador actual.
    $stmt = $db->prepare("SELECT hits FROM rate_limits WHERE action = ? AND key_hash = ?");
    if (!$stmt) {
        error_log('OPNG ratelimit.php: ' . $db->error);
        return true;
    }
    $stmt->bind_param('ss', $action, $key_hash);
    if (!$stmt->execute()) {
        error_log('OPNG ratelimit.php: ' . $stmt->error);
        $stmt->close();
        return true;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $hits = (int) ($row['hits'] ?? 0);
    $stmt->close();

    // 4. Limpieza oportunista de ventanas antiguas (1% de las llamadas).
    if (random_int(1, 100) === 1) {
        $threshold = time() - 86400;
        $db->query("DELETE FROM rate_limits WHERE window_start < $threshold");
    }

    return $hits <= $limit;
}
