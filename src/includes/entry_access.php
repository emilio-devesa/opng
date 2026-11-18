<?php

declare(strict_types=1);

// includes/entry_access.php
// Visibility, expiry and password protection for entries (10.5).
//
// Rules:
//   public    Listed and readable by anyone with the link.
//   unlisted  Readable by anyone with the link, never listed.
//   private   Readable only by the author and administrators.
//
// A protected entry additionally requires its password. Unlocking is stored in
// the session for the whole thread (root id) for a short period, so replies do
// not ask again. Replies inherit the parent's visibility, absolute expiry and
// protection hash; a reply can therefore never make a private thread public.

const OPNG_ENTRY_VISIBILITIES = ['public', 'unlisted', 'private'];
const OPNG_ENTRY_UNLOCK_TTL = 1800;

/**
 * Whether a visibility value is accepted by the schema.
 */
function entry_visibility_valid(mixed $visibility): bool
{
    return is_string($visibility) && in_array($visibility, OPNG_ENTRY_VISIBILITIES, true);
}

/**
 * Visibility choices offered by the create/edit forms.
 *
 * @return array<string, string> value => translation key
 */
function entry_visibility_options(): array
{
    return [
        'public' => 'visibility_public',
        'unlisted' => 'visibility_unlisted',
        'private' => 'visibility_private',
    ];
}

/**
 * Expiry choices offered by the create/edit forms.
 *
 * @return array<string, string> value => translation key
 */
function entry_expiry_options(): array
{
    return [
        'never' => 'expiry_never',
        '1h' => 'expiry_1h',
        '1d' => 'expiry_1d',
        '7d' => 'expiry_7d',
        '30d' => 'expiry_30d',
    ];
}

/**
 * Convert an expiry option to an absolute MySQL datetime, or null for "never".
 */
function entry_expiry_from_option(mixed $option, ?int $now = null): ?string
{
    if (!is_string($option) || !array_key_exists($option, entry_expiry_options())) {
        return null;
    }

    $seconds = match ($option) {
        '1h' => 3600,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
        default => null,
    };
    if ($seconds === null) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', ($now ?? time()) + $seconds);
}

/**
 * Normalize an expiry option from a stored absolute value. Used by the edit
 * form when the remaining lifetime does not match a preset exactly.
 */
function entry_expiry_option_for(?string $expiresAt): string
{
    if ($expiresAt === null || $expiresAt === '') {
        return 'never';
    }

    try {
        $expiry = new DateTimeImmutable($expiresAt);
    } catch (Exception) {
        return 'never';
    }
    $remaining = $expiry->getTimestamp() - time();
    foreach (['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000] as $option => $seconds) {
        if (abs($remaining - $seconds) <= 300) {
            return $option;
        }
    }

    return 'never';
}

/**
 * @param array<string, mixed> $entry
 */
function entry_is_deleted(array $entry): bool
{
    return is_string($entry['deleted_at'] ?? null) && $entry['deleted_at'] !== '';
}

/**
 * @param array<string, mixed> $entry
 */
function entry_is_expired(array $entry, ?int $now = null): bool
{
    $expiresAt = $entry['expires_at'] ?? null;
    if (!is_string($expiresAt) || $expiresAt === '') {
        return false;
    }

    try {
        return (new DateTimeImmutable($expiresAt))->getTimestamp() <= ($now ?? time());
    } catch (Exception) {
        return true;
    }
}

/**
 * SQL predicate for entries that may appear in the public listing.
 */
function entry_public_filter_sql(string $alias = 'entries'): string
{
    if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $alias) !== 1) {
        throw new InvalidArgumentException('Invalid entry table alias.');
    }

    return "{$alias}.deleted_at IS NULL"
        . " AND ({$alias}.expires_at IS NULL OR {$alias}.expires_at > NOW())"
        . " AND {$alias}.visibility = 'public'";
}

/**
 * Whether the account may see this entry at all (before the password check).
 */
/**
 * @param array<string, mixed> $entry
 */
function entry_visibility_allows(array $entry, ?int $userId, string $role): bool
{
    if ($role === 'admin') {
        return true;
    }
    $ownerId = isset($entry['user_id'])
        ? (int) $entry['user_id']
        : null;
    if ($userId !== null && $ownerId !== null && $userId === $ownerId) {
        return true;
    }

    return ($entry['visibility'] ?? 'public') !== 'private';
}

/**
 * @param array<string, mixed> $entry
 */
function entry_requires_unlock(array $entry): bool
{
    return is_string($entry['protection_hash'] ?? null) && $entry['protection_hash'] !== '';
}

/**
 * Root id used as the unlock key for a thread.
 */
/**
 * @param array<string, mixed> $entry
 */
function entry_thread_root_id(array $entry): string
{
    $root = $entry['root_id'] ?? null;
    if (is_string($root) && $root !== '') {
        return $root;
    }

    return (string) ($entry['id'] ?? '');
}

/**
 * Whether the current session already unlocked this thread.
 */
function entry_is_unlocked(string $rootId): bool
{
    $unlocked = $_SESSION['opng_unlocked'] ?? null;
    if (!is_array($unlocked) || !isset($unlocked[$rootId]) || !is_int($unlocked[$rootId])) {
        return false;
    }

    if ($unlocked[$rootId] < time()) {
        unset($_SESSION['opng_unlocked'][$rootId]);
        return false;
    }

    return true;
}

function entry_mark_unlocked(string $rootId): void
{
    if (!isset($_SESSION['opng_unlocked']) || !is_array($_SESSION['opng_unlocked'])) {
        $_SESSION['opng_unlocked'] = [];
    }
    $_SESSION['opng_unlocked'][$rootId] = time() + OPNG_ENTRY_UNLOCK_TTL;
}

function entry_clear_unlocked(): void
{
    unset($_SESSION['opng_unlocked']);
}

/**
 * Combined access decision for a loaded entry.
 */
/**
 * @param array<string, mixed> $entry
 */
function entry_can_view(array $entry, ?int $userId, string $role): bool
{
    if (entry_is_deleted($entry) || entry_is_expired($entry)) {
        return false;
    }
    if (!entry_visibility_allows($entry, $userId, $role)) {
        return false;
    }
    if (entry_requires_unlock($entry) && !entry_is_unlocked(entry_thread_root_id($entry))) {
        return false;
    }

    return true;
}

/**
 * Values a reply must copy from its parent.
 *
 * @param array<string, mixed> $parent
 * @return array{visibility: string, expires_at: string|null, protection_hash: string|null}
 */
function entry_reply_inherited_fields(array $parent): array
{
    return [
        'visibility' => entry_visibility_valid($parent['visibility'] ?? null)
            ? (string) $parent['visibility']
            : 'public',
        'expires_at' => is_string($parent['expires_at'] ?? null) && $parent['expires_at'] !== ''
            ? $parent['expires_at']
            : null,
        'protection_hash' => is_string($parent['protection_hash'] ?? null) && $parent['protection_hash'] !== ''
            ? $parent['protection_hash']
            : null,
    ];
}

/**
 * Validate a protection password. An empty value means "no password".
 */
function entry_protection_error(?string $password): ?string
{
    if ($password === null || $password === '') {
        return null;
    }
    if (strlen($password) < 4) {
        return 'error_protection_password';
    }
    if (strlen($password) > 72) {
        return 'error_protection_password';
    }

    return null;
}

function entry_protection_hash(string $password): string
{
    // Argon2id is the preferred algorithm (10.5); PASSWORD_DEFAULT keeps older
    // PHP builds working, and password_verify() reads the stored algorithm.
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

    return password_hash($password, $algorithm);
}

function entry_protection_verify(string $password, string $hash): bool
{
    return $hash !== '' && password_verify($password, $hash);
}
