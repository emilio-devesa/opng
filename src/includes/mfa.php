<?php

declare(strict_types=1);

// includes/mfa.php
// Time-based one-time passwords for administrators (10.2).
//
// Secrets are encrypted at rest with AES-256-GCM. The 32-byte key comes from
// OPNG_MFA_KEY (base64), so a database dump alone cannot produce valid codes.
// Recovery codes are one-time values stored as SHA-256 hashes.
//
// The TOTP implementation follows RFC 6238 with the RFC 4226 dynamic
// truncation step; SHA-1, six digits and a 30-second period are the defaults
// accepted by common authenticator applications.

const OPNG_MFA_DIGITS = 6;
const OPNG_MFA_PERIOD = 30;
const OPNG_MFA_WINDOW = 1;
const OPNG_MFA_RECOVERY_CODES = 10;

require_once __DIR__ . '/logging.php';

/**
 * Decode the configured encryption key. Returns null when OPNG_MFA_KEY is
 * missing or not exactly 32 bytes of base64.
 */
function mfa_key(): ?string
{
    static $key = false;
    if ($key !== false) {
        return $key;
    }

    $raw = getenv('OPNG_MFA_KEY');
    if (!is_string($raw) || trim($raw) === '') {
        return $key = null;
    }

    $decoded = base64_decode(trim($raw), true);
    if (!is_string($decoded) || strlen($decoded) !== 32) {
        return $key = null;
    }

    return $key = $decoded;
}

/**
 * Encrypt a TOTP secret for storage. The output is base64(iv || tag || cipher).
 */
function mfa_encrypt_secret(string $secret): ?string
{
    $key = mfa_key();
    if ($key === null || $secret === '') {
        return null;
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        return null;
    }

    return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt a stored TOTP secret.
 */
function mfa_decrypt_secret(string $stored): ?string
{
    $key = mfa_key();
    if ($key === null || $stored === '') {
        return null;
    }

    $raw = base64_decode($stored, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        return null;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return is_string($plain) && $plain !== '' ? $plain : null;
}

/**
 * Generate a new 160-bit TOTP secret in RFC 4648 base32.
 */
function mfa_generate_secret(): string
{
    return mfa_base32_encode(random_bytes(20));
}

function mfa_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

function mfa_base32_decode(string $encoded): ?string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
    if ($encoded === '') {
        return null;
    }

    $bits = '';
    foreach (str_split($encoded) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) {
            return null;
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) < 8) {
            break;
        }
        $bytes .= chr(bindec($chunk));
    }

    return $bytes === '' ? null : $bytes;
}

/**
 * Compute the TOTP value for a secret and Unix timestamp.
 */
function mfa_totp(string $secret, ?int $timestamp = null, int $digits = OPNG_MFA_DIGITS): ?string
{
    $key = mfa_base32_decode($secret);
    if ($key === null) {
        return null;
    }

    $counter = intdiv($timestamp ?? time(), OPNG_MFA_PERIOD);
    $binary = pack('N2', intdiv($counter, 0x100000000), $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $binary, $key, true);
    if (strlen($hash) < 20) {
        return null;
    }

    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $modulo = 10 ** $digits;

    return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code, allowing one period of clock drift in either direction.
 */
function mfa_verify_totp(string $secret, string $code, ?int $timestamp = null): bool
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (preg_match('/\A\d{' . OPNG_MFA_DIGITS . '}\z/', $code) !== 1) {
        return false;
    }

    $now = $timestamp ?? time();
    for ($offset = -OPNG_MFA_WINDOW; $offset <= OPNG_MFA_WINDOW; $offset++) {
        $candidate = mfa_totp($secret, $now + ($offset * OPNG_MFA_PERIOD));
        if (is_string($candidate) && hash_equals($candidate, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * otpauth:// URI for the enrollment screen. The issuer and account name are
 * URL-encoded; the secret is base32.
 */
function mfa_otpauth_uri(string $secret, string $account, string $issuer = 'Open Pastebin NG'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);

    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . OPNG_MFA_DIGITS
        . '&period=' . OPNG_MFA_PERIOD;
}

/**
 * Normalize a recovery code before hashing: uppercase, remove separators.
 */
function mfa_recovery_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function mfa_recovery_hash(string $code): string
{
    return hash('sha256', mfa_recovery_normalize($code));
}

/**
 * Generate a fresh set of recovery codes. The clear values are returned once.
 *
 * @return list<string>
 */
function mfa_generate_recovery_codes(): array
{
    $codes = [];
    for ($index = 0; $index < OPNG_MFA_RECOVERY_CODES; $index++) {
        // 80 bits of entropy, grouped for legibility.
        $raw = strtoupper(bin2hex(random_bytes(10)));
        $codes[] = implode('-', str_split($raw, 5));
    }

    return $codes;
}

/**
 * Replace every recovery code for an account.
 *
 * @param list<string> $codes clear codes
 */
function mfa_store_recovery_codes(mysqli $db, int $userId, array $codes): bool
{
    $delete = $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?');
    if (!$delete) {
        return false;
    }
    $delete->bind_param('i', $userId);
    $delete->execute();
    $delete->close();

    $insert = $db->prepare(
        'INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)'
    );
    if (!$insert) {
        return false;
    }
    foreach ($codes as $code) {
        $hash = mfa_recovery_hash($code);
        $insert->bind_param('is', $userId, $hash);
        if (!$insert->execute()) {
            $insert->close();
            return false;
        }
    }
    $insert->close();

    return true;
}

/**
 * Replace every recovery code for an account atomically.
 *
 * @param list<string> $codes clear codes
 */
function mfa_replace_recovery_codes(mysqli $db, int $userId, array $codes): bool
{
    try {
        $db->begin_transaction();
        if (!mfa_store_recovery_codes($db, $userId, $codes)) {
            $db->rollback();
            return false;
        }
        $db->commit();
        return true;
    } catch (Throwable $exception) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackException) {
            opng_log_exception('mfa_recovery_rollback_failed', $rollbackException);
        }
        opng_log_exception('mfa_recovery_replace_failed', $exception);
        return false;
    }
}

/**
 * Consume one unused recovery code.
 */
function mfa_consume_recovery_code(mysqli $db, int $userId, string $code): bool
{
    $normalized = mfa_recovery_normalize($code);
    if ($normalized === '') {
        return false;
    }

    $hash = mfa_recovery_hash($code);
    $stmt = $db->prepare(
        'SELECT id FROM mfa_recovery_codes
         WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $userId, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    if (!is_array($row)) {
        return false;
    }

    $id = (int) $row['id'];
    $update = $db->prepare(
        'UPDATE mfa_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
    );
    if (!$update) {
        return false;
    }
    $update->bind_param('i', $id);
    $update->execute();
    $consumed = $update->affected_rows === 1;
    $update->close();

    return $consumed;
}

/**
 * @return array{id: int, username: string, role: string, mfa_secret: string|null, mfa_enabled: bool}|null
 */
function mfa_user_record(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT id, username, role, mfa_secret, mfa_enabled FROM users WHERE id = ?'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
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

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'username' => (string) ($row['username'] ?? ''),
        'role' => (string) ($row['role'] ?? 'user'),
        'mfa_secret' => is_string($row['mfa_secret'] ?? null) ? $row['mfa_secret'] : null,
        'mfa_enabled' => (int) ($row['mfa_enabled'] ?? 0) === 1,
    ];
}

/**
 * The lowest-id administrator must enroll before using the account. Other
 * administrators may enable MFA voluntarily from the security page.
 */
function mfa_is_first_admin(mysqli $db, int $userId): bool
{
    $stmt = $db->prepare(
        "SELECT MIN(id) AS first_admin FROM users WHERE role = 'admin'"
    );
    if (!$stmt) {
        return false;
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) && (int) ($row['first_admin'] ?? 0) === $userId;
}

/**
 * Whether this account must present a second factor at login.
 */
/**
 * @param array<string, mixed> $record
 */
function mfa_requires_challenge(array $record): bool
{
    return $record['role'] === 'admin' && $record['mfa_enabled'];
}

/**
 * Whether this account must enroll before the session can be completed.
 */
/**
 * @param array<string, mixed> $record
 */
function mfa_requires_enrollment(mysqli $db, array $record): bool
{
    return $record['role'] === 'admin'
        && !$record['mfa_enabled']
        && mfa_is_first_admin($db, $record['id']);
}

/**
 * Verify a TOTP code or consume a recovery code for an account.
 */
function mfa_verify_user_code(mysqli $db, int $userId, string $code): bool
{
    $record = mfa_user_record($db, $userId);
    if ($record === null || !$record['mfa_enabled'] || $record['mfa_secret'] === null) {
        return false;
    }

    $secret = mfa_decrypt_secret($record['mfa_secret']);
    if ($secret === null) {
        return false;
    }

    if (mfa_verify_totp($secret, $code)) {
        return true;
    }

    return mfa_consume_recovery_code($db, $userId, $code);
}

/**
 * Persist a confirmed enrollment.
 *
 * @param list<string> $recoveryCodes
 */
function mfa_enable_for_user(mysqli $db, int $userId, string $secret, array $recoveryCodes): bool
{
    $encrypted = mfa_encrypt_secret($secret);
    if ($encrypted === null) {
        return false;
    }

    try {
        $db->begin_transaction();
        $stmt = $db->prepare(
            'UPDATE users
             SET mfa_secret = ?, mfa_enabled = 1, mfa_enrolled_at = NOW(),
                 session_version = session_version + 1
             WHERE id = ?'
        );
        if (!$stmt) {
            $db->rollback();
            return false;
        }
        $stmt->bind_param('si', $encrypted, $userId);
        $stmt->execute();
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$updated || !mfa_store_recovery_codes($db, $userId, $recoveryCodes)) {
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    } catch (Throwable $exception) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackException) {
            opng_log_exception('mfa_enable_rollback_failed', $rollbackException);
        }
        opng_log_exception('mfa_enable_failed', $exception);
        return false;
    }
}

/**
 * Disable MFA and discard the recovery codes.
 */
function mfa_disable_for_user(mysqli $db, int $userId): bool
{
    try {
        $db->begin_transaction();
        $stmt = $db->prepare(
            'UPDATE users
             SET mfa_secret = NULL, mfa_enabled = 0, mfa_enrolled_at = NULL,
                 session_version = session_version + 1
             WHERE id = ?'
        );
        if (!$stmt) {
            $db->rollback();
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$updated) {
            $db->rollback();
            return false;
        }

        $delete = $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?');
        if (!$delete) {
            $db->rollback();
            return false;
        }
        $delete->bind_param('i', $userId);
        $delete->execute();
        $delete->close();

        $db->commit();
        return true;
    } catch (Throwable $exception) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackException) {
            opng_log_exception('mfa_disable_rollback_failed', $rollbackException);
        }
        opng_log_exception('mfa_disable_failed', $exception);
        return false;
    }
}
