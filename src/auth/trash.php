<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// auth/trash.php
// Author-facing trash with restore (10.3).
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
$user_id = require_authenticated_user();
$threads = trash_list_threads($db, $user_id);
$db->close();

$retention_days = trash_retention_days();
$flash = http_string_parameter($_GET, 'msg', 32, false);
$flash_key = match ($flash) {
    'restored' => 'trash_restored',
    'restore_failed' => 'error_trash_restore',
    default => '',
};

$lang_attr = 'en';
$page_title = 'Trash';
$page_title_i18n = 'trash_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page trash-page">
        <h1 data-i18n="trash_title">Trash</h1>
        <p data-i18n="trash_intro">Deleted threads stay here for a limited time. Restoring a thread brings every reply back.</p>
        <p class="form-help" data-i18n="trash_retention_notice">Threads are permanently removed after the retention period. Storage quota stays charged while a thread is in the trash.</p>
        <?php if ($flash_key !== ''): ?>
        <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(opng_t($flash_key, $flash), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>
        <?php if ($threads === []): ?>
        <p data-i18n="trash_empty">The trash is empty.</p>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead><tr>
                    <th scope="col" data-i18n="topic">Topic</th>
                    <th scope="col" data-i18n="trash_deleted_at">Deleted</th>
                    <th scope="col" data-i18n="trash_entries">Entries</th>
                    <th scope="col"><span class="visually-hidden" data-i18n="trash_restore">Restore</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($threads as $thread): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($thread['topic'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo opng_datetime_html($thread['deleted_at']); ?></td>
                        <td><?php echo (int) $thread['entries']; ?></td>
                        <td>
                            <form method="post" action="trash_action.php" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="input_ID" value="<?php echo htmlspecialchars($thread['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" data-i18n="trash_restore">Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p><a href="security.php" data-i18n="security_title">Account security</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
