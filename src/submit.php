<?php

require_once __DIR__ . '/bootstrap.php';
// Handles the create/reply POST. Refactor: centralized session/CSRF/rules.
session_start_secured();
require("includes/rules.php");
require("includes/ratelimit.php");
require("includes/paste_validation.php");
require("includes/user_quota.php");
require("includes/admin_metrics.php");
require("includes/thread.php");
require_once("includes/entry_access.php");
require_once("includes/account_role.php");
require_once("includes/captcha.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf');
}

// Preserve leading/trailing whitespace exactly as pasted (indentation and the
// final newline are meaningful in source code). Empty input is still rejected
// by paste_validation_error(), which trims only for the emptiness check.
$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1, false);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    opng_error_redirect('error_invalid_paste');
}

$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null || $language === '') {
    opng_error_redirect($validation_error ?? 'error_language_required');
}

try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    opng_error_redirect('error_language_config');
}
if (!isset($rules[$language])) {
    opng_error_redirect('error_invalid_language');
}

// Replies carry the parent entry ID in a hidden field. The relationship is
// validated inside the transaction so a deleted parent cannot be referenced.
$parent_id = http_string_parameter($_POST, 'parent_id', 255, false);
if ($parent_id !== null && !http_valid_entry_id($parent_id)) {
    opng_error_redirect('error_invalid_paste');
}

// Visibility, expiry and password protection apply to new roots. Replies
// inherit all three from their parent inside the transaction, so a reply can
// never make a private or protected thread public.
$access_input = entry_access_input($_POST);
if (is_string($access_input)) {
    opng_error_redirect($access_input);
}
$visibility = $access_input['visibility'];
$protection_password = $access_input['password'];
$expires_at = entry_expiry_from_option($access_input['expiry']);
$protection_hash = $protection_password !== ''
    ? entry_protection_hash($protection_password)
    : null;

// db_connect() may revoke a stale identity. Do not downgrade an in-flight
// authenticated POST to a guest submission or attribute it to the old user.
$had_identity = isset($_SESSION['user_id']);
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}
$user_id = http_positive_integer($_SESSION['user_id'] ?? null);
if ($had_identity && $user_id === null) {
    $db->close();
    header('Location: auth/login.php');
    exit;
}
if ($user_id === null && $visibility === 'private') {
    $db->close();
    opng_error_redirect('error_private_requires_login');
}

try {
    // Authenticated accounts get a larger budget than anonymous guests.
    $rate_limit_action = $user_id === null ? 'submit_guest' : 'submit_user';
    $rate_limit_max = $user_id === null ? 5 : 20;
    $limit_result = rate_limit_hit($db, $rate_limit_action, client_ip(), $rate_limit_max, 60);
    if ($limit_result === RateLimitResult::Unavailable) {
        $db->close();
        opng_error_redirect('error_service_unavailable');
    }
    if ($limit_result === RateLimitResult::Limited) {
        $db->close();
        opng_error_redirect('error_paste_rate_limit');
    }

    // Guests must solve the local CAPTCHA. Validate it after the rate limit so
    // a wrong answer still consumes an attempt instead of allowing unlimited
    // challenge rotation (SEC-19).
    if ($user_id === null) {
        $captcha_answer = http_string_parameter($_POST, 'captcha_answer', 5);
        if (!captcha_validate($captcha_answer)) {
            $db->close();
            opng_error_redirect('captcha_incorrect');
        }
    }

    // Global soft cap for all paste text, configurable with
    // OPNG_STORAGE_QUOTA_BYTES (default 10 GiB). The counter row is locked
    // inside the same transaction as the insert, so concurrent writes cannot
    // overshoot the quota (SEC-04/ROB-03) and no full-table SUM() runs per
    // request (PERF-01).
    $id = bin2hex(random_bytes(8));
    $text_bytes = strlen($text);
    $transaction_started = false;
    $db->begin_transaction();
    $transaction_started = true;

    // Global lock order (ROB-15): storage_counters -> users -> entries. The
    // counter row is taken first, then the account (if any), and the parent
    // entry (if this is a reply) last.
    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        error_log('OPNG submit.php: global storage counter is unavailable');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_database_operation');
    }
    if (!global_quota_allows($global_used, $text_bytes)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_global_storage_quota');
    }

    $author_label = 'Guest';
    if ($user_id !== null) {
        $usage = lock_user_quota($db, (int) $user_id);
        if ($usage === null) {
            error_log('OPNG submit.php: authenticated account was not found while checking storage quota');
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_database_operation');
        }
        if ($usage['session_version'] !== (int) $_SESSION['session_version']) {
            $db->rollback();
            $db->close();
            session_forget_account();
            header('Location: auth/login.php');
            exit;
        }
        if (!user_quota_allows($usage, 0, $text_bytes)) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_storage_quota');
        }
        $author_label = $usage['username'];
    }

    $parent = null;
    if ($parent_id !== null) {
        // Lock the parent row until the insert commits: a concurrent delete
        // either waits or makes this reply fail cleanly instead of leaving a
        // dangling parent_id.
        $parent = thread_parent_for_reply($db, $parent_id, true);
        if ($parent === null) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_entry_not_found');
        }
        // A reply inherits the parent's access policy, so only an account that
        // can already view the thread may add to it. Otherwise a private or
        // protected thread could be polluted by an unrelated visitor.
        $reply_role = refresh_account_role($db) ?? 'guest';
        if (!entry_can_view($parent, $user_id, $reply_role)) {
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_entry_not_found');
        }
    }

    try {
        $position = thread_position($parent, $id);
    } catch (ThreadDepthException $exception) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_thread_too_deep');
    }

    $position_parent_id = $position['parent_id'];
    $position_root_id = $position['root_id'];
    $position_depth = $position['depth'];

    if ($user_id === null) {
        $stmt = $db->prepare(
            "INSERT INTO entries (id, parent_id, root_id, depth, user_id, author_label, language, text, topic)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssissss",
            $id,
            $position_parent_id,
            $position_root_id,
            $position_depth,
            $author_label,
            $language,
            $text,
            $topic
        );
    } else {
        $stmt = $db->prepare(
            "INSERT INTO entries (id, parent_id, root_id, depth, user_id, author_label, language, text, topic)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssiissss",
            $id,
            $position_parent_id,
            $position_root_id,
            $position_depth,
            $user_id,
            $author_label,
            $language,
            $text,
            $topic
        );
    }

    if (!$stmt->execute()) {
        error_log("OPNG submit.php: " . $stmt->error);
        $stmt->close();
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $stmt->close();
    $stmt = null;

    // The INSERT above keeps the legacy column list stable; visibility,
    // expiry and protection are written separately so replies can copy them
    // from the locked parent.
    $access = $parent !== null
        ? entry_reply_inherited_fields($parent)
        : [
            'visibility' => $visibility,
            'expires_at' => $expires_at,
            'protection_hash' => $protection_hash,
        ];
    $stmt = $db->prepare(
        'UPDATE entries SET visibility = ?, expires_at = ?, protection_hash = ? WHERE id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Entry access fields could not be prepared.');
    }
    $stmt->bind_param(
        'ssss',
        $access['visibility'],
        $access['expires_at'],
        $access['protection_hash'],
        $id
    );
    if (!$stmt->execute()) {
        error_log('OPNG submit.php: ' . $stmt->error);
        $stmt->close();
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $stmt->close();
    $stmt = null;

    if (
        !global_quota_consume($db, $text_bytes)
        || !global_entry_consume($db, 1)
        || ($user_id !== null && !user_quota_consume($db, (int) $user_id, $text_bytes))
    ) {
        error_log('OPNG submit.php: could not update the storage counters');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }

    if (!$db->commit()) {
        error_log("OPNG submit.php: failed to commit the paste transaction");
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_save_paste');
    }
    $transaction_started = false;
    // The dashboard aggregates changed; do not wait for the five-minute TTL.
    admin_metrics_invalidate($db);
    $db->close();
} catch (mysqli_sql_exception $exception) {
    error_log("OPNG submit.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($transaction_started) && $transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_save_paste');
}

if ($parent_id !== null) {
    header("Location: view.php?id=" . urlencode($id));
    exit;
}

header("Location: index.php?msg=" . urlencode("Paste created successfully"));
exit;
?>
