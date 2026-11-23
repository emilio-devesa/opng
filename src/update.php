<?php

require_once __DIR__ . '/bootstrap.php';

// Real paste editing (UPDATE).
// Only the paste author or an administrator may do it.
session_start_secured();
require("includes/rules.php");
require("includes/paste_validation.php");
require("includes/paste_permissions.php");
require("includes/user_quota.php");
require("includes/admin_metrics.php");
require("includes/account_role.php");
require_once("includes/entry_access.php");

// ------------------ Validaciones de seguridad ------------------
if (
    !isset($_SESSION['user_id'])
    || http_positive_integer($_SESSION['user_id']) === null
) {
    opng_error_redirect('error_sign_in_to_edit');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    opng_error_redirect('error_csrf');
}

// The ID must be alphanumeric (same format that submit.php generates)
$id = http_string_parameter($_POST, 'input_ID', 255, false);
if ($id === null || !http_valid_entry_id($id)) {
    opng_error_redirect('error_invalid_id');
}

// Field validation
// Preserve the exact text, including leading indentation and trailing newlines.
$text = http_string_parameter($_POST, 'input_text', OPNG_MAX_PASTE_BYTES + 1, false);
$topic = http_string_parameter($_POST, 'input_topic', OPNG_MAX_TOPIC_BYTES + 1);
$language = http_string_parameter($_POST, 'input_language', 255);
if ($text === null || $topic === null || $language === null) {
    opng_error_redirect('error_invalid_paste');
}

// Values
$validation_error = paste_validation_error($text, $topic);
if ($validation_error !== null) {
    opng_error_redirect($validation_error);
}

// Access fields are only meaningful for thread roots. Replies inherit the
// parent's visibility, expiry and password protection, so their edit form does
// not post these values and the transaction leaves them untouched (10.5).
$access_input = entry_access_input($_POST, true);
if (is_string($access_input)) {
    opng_error_redirect($access_input);
}
$visibility = $access_input['visibility'];
$protection_password = $access_input['password'];
$remove_protection = $access_input['remove'];

// Language: allowlist (same as in submit.php)
try {
    $rules = load_rules();
} catch (RuntimeException $exception) {
    opng_error_redirect('error_language_config');
}
if (!isset($rules[$language])) {
    opng_error_redirect('error_invalid_language');
}

// Connect to the database
$db = db_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable');
}

$transaction_started = false;
try {
    $db->begin_transaction();
    $transaction_started = true;

    // Global lock order (ROB-15): storage_counters -> users -> entries.
    // Taking the counter first serializes this edit with submit.php and the
    // admin flows that consume the same counter.
    //
    // Same transactional counter as submit.php: an edit can grow a paste past
    // the global cap even when the per-account quota allows it (SEC-04/ROB-03).
    $global_used = global_quota_lock($db);
    if ($global_used === null) {
        error_log('OPNG update.php: global storage counter is unavailable');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_database_operation');
    }

    // Discover the owner before locking the account. The row is re-read with
    // FOR UPDATE after the account lock, so quota values always come from the
    // locked row.
    $stmt = $db->prepare('SELECT id, user_id FROM entries WHERE id = ?');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Entry lookup result is unavailable.');
    }
    $entry = $result->fetch_assoc();
    $result->free();
    $stmt->close();
    $stmt = null;

    if (!$entry) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_entry_not_found');
    }
    $owner_id = $entry['user_id'] !== null ? (int) $entry['user_id'] : null;

    $usage = null;
    if ($owner_id !== null) {
        $usage = lock_user_quota($db, $owner_id);
        if ($usage === null) {
            error_log('OPNG update.php: paste owner was not found while checking storage quota');
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_database_operation');
        }
    }

    // The entry is the last resource in the global lock order.
    $stmt = $db->prepare(
        'SELECT user_id, parent_id, root_id, depth, visibility, expires_at, protection_hash,
                OCTET_LENGTH(text) AS text_bytes
         FROM entries WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Entry lock result is unavailable.');
    }
    $entry = $result->fetch_assoc();
    $result->free();
    $stmt->close();
    $stmt = null;

    if (!$entry) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_entry_not_found');
    }
    $locked_owner_id = $entry['user_id'] !== null ? (int) $entry['user_id'] : null;
    if ($locked_owner_id !== $owner_id) {
        throw new RuntimeException('Entry ownership changed while the update locked it.');
    }

    // Check permissions: admin or paste owner
    $user_role = refresh_account_role($db) ?? 'guest';
    $current_id = $_SESSION['user_id'];

    $current_user_id = http_positive_integer($current_id);
    if ($current_user_id === null) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_sign_in_to_edit');
    }
    if (!paste_owner_can_manage($current_user_id, (string) $user_role, $owner_id)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_permission');
    }

    $previous_bytes = (int) ($entry['text_bytes'] ?? 0);
    $replacement_bytes = strlen($text);
    $global_delta = $replacement_bytes - $previous_bytes;
    $author_label = $owner_id === null ? 'Guest' : $usage['username'];

    if ($global_delta > 0 && !global_quota_allows($global_used, $global_delta)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_global_storage_quota');
    }

    if ($owner_id !== null && !user_quota_allows($usage, $previous_bytes, $replacement_bytes)) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_storage_quota');
    }

    $stmt = $db->prepare(
        "UPDATE entries SET language = ?, text = ?, topic = ?, author_label = ? WHERE id = ?"
    );
    $stmt->bind_param("sssss", $language, $text, $topic, $author_label, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $stmt = null;
    if (!$ok) {
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }

    // Editing a thread root updates the access policy of every entry in the
    // thread so replies cannot drift away from their parent. Editing a reply
    // keeps the inherited values from the parent (10.5).
    $is_root = (int) ($entry['depth'] ?? 0) === 0;
    if ($is_root) {
        $expires_at = $access_input['expiry'] === 'keep'
            ? $entry['expires_at']
            : entry_expiry_from_option($access_input['expiry']);
        $root_id = is_string($entry['root_id'] ?? null) && $entry['root_id'] !== ''
            ? $entry['root_id']
            : $id;
        $new_protection_hash = $entry['protection_hash'] ?? null;
        if ($protection_password !== '') {
            $new_protection_hash = entry_protection_hash($protection_password);
        } elseif ($remove_protection) {
            $new_protection_hash = null;
        }
        $stmt = $db->prepare(
            'UPDATE entries
             SET visibility = ?, expires_at = ?, protection_hash = ?
             WHERE id = ? OR root_id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Thread access fields could not be prepared.');
        }
        $stmt->bind_param(
            'sssss',
            $visibility,
            $expires_at,
            $new_protection_hash,
            $id,
            $root_id
        );
        if (!$stmt->execute()) {
            error_log('OPNG update.php: ' . $stmt->error);
            $stmt->close();
            $db->rollback();
            $transaction_started = false;
            $db->close();
            opng_error_redirect('error_update_paste');
        }
        $stmt->close();
        $stmt = null;
    }

    if (
        ($global_delta !== 0 && !global_quota_consume($db, $global_delta))
        || ($owner_id !== null && $global_delta !== 0 && !user_quota_consume($db, $owner_id, $global_delta))
    ) {
        error_log('OPNG update.php: could not update the storage counters');
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }
    if (!$db->commit()) {
        error_log("OPNG update.php: failed to commit the paste transaction");
        $db->rollback();
        $transaction_started = false;
        $db->close();
        opng_error_redirect('error_update_paste');
    }
    $transaction_started = false;
    // Text bytes, language and topic may have changed.
    admin_metrics_invalidate($db);
} catch (mysqli_sql_exception | RuntimeException $exception) {
    error_log("OPNG update.php: " . $exception->getMessage());
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if ($transaction_started) {
        $db->rollback();
    }
    $db->close();
    opng_error_redirect('error_database_operation');
}
$db->close();

header("Location: view.php?id=" . urlencode($id) . "&msg=" . urlencode("Paste updated successfully"));
exit;
?>
