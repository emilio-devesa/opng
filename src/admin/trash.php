<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/trash.php
// Administrator trash view: restore, purge one thread or purge expired (10.3).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/trash.php');
$asset_version = opng_asset_version();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$db = database_connect();
if (!$db) {
    opng_error_redirect('error_database_unavailable', '../error.php');
}
$session_user_id = require_admin($db, 'trash.php');
$threads = trash_list_threads($db);
$db->close();

$retention_days = trash_retention_days();
$flash = http_string_parameter($_GET, 'msg', 32, false);
$flash_key = match ($flash) {
    'restored' => 'trash_restored',
    'purged' => 'trash_purged',
    'purged_expired' => 'trash_purged_expired',
    'restore_failed' => 'error_trash_restore',
    default => '',
};

$lang_attr = 'en';
$page_title = 'Trash - Administration';
$page_title_i18n = 'trash_admin_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h1 data-i18n="trash_admin_title">Trash</h1>
    <p data-i18n="trash_admin_intro">Deleted threads are retained before they are permanently purged. Storage counters stay charged until the purge.</p>
    <p class="form-help" data-i18n="trash_retention_notice">Threads are permanently removed after the retention period. Storage quota stays charged while a thread is in the trash.</p>
    <?php if ($flash_key !== ''): ?>
    <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars(opng_t($flash_key, $flash), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php endif; ?>

    <form method="post" action="trash_action.php" class="admin-search-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="purge_expired">
        <button type="submit" class="danger" data-i18n="trash_purge_expired_button">Purge expired threads now</button>
        <span class="form-help" data-i18n="trash_purge_expired_help">Deletes expired threads in bounded batches. Run again or wait for the scheduled purge to continue. The current password is required.</span>
    </form>

    <?php if ($threads === []): ?>
    <p data-i18n="trash_empty">The trash is empty.</p>
    <?php else: ?>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th scope="col" data-i18n="topic">Topic</th>
                <th scope="col" data-i18n="author">Author</th>
                <th scope="col" data-i18n="trash_deleted_at">Deleted</th>
                <th scope="col" data-i18n="trash_entries">Entries</th>
                <th scope="col" data-i18n="admin_actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($threads as $thread): ?>
                <tr>
                    <td><?php echo htmlspecialchars($thread['topic'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $thread['username'] !== '' ? htmlspecialchars($thread['username'], ENT_QUOTES, 'UTF-8') : '<span data-i18n="history_guest">Guest</span>'; ?></td>
                    <td><?php echo opng_datetime_html($thread['deleted_at']); ?></td>
                    <td><?php echo (int) $thread['entries']; ?></td>
                    <td class="trash-actions">
                        <form method="post" action="trash_action.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" data-i18n="trash_restore">Restore</button>
                        </form>
                        <form method="post" action="trash_action.php" class="inline-form" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="purge">
                            <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="confirm-check">
                                <input type="checkbox" name="confirm_purge" value="yes" required>
                                <span data-i18n="trash_confirm_purge">Permanently delete this thread.</span>
                            </label>
                            <label for="purge_password_<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="reauth_password">Current password:</label>
                            <input id="purge_password_<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                            <label for="purge_mfa_<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="mfa_code_optional">Authentication code (if enabled):</label>
                            <input id="purge_mfa_<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code">
                            <button type="submit" class="danger" data-i18n="trash_purge">Purge permanently</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <p><a href="admin.php" data-i18n="cancel">Cancel</a></p>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
