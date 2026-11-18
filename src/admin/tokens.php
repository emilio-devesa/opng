<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/tokens.php
// Administrator view of every API token (10.4).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/api_tokens.php');
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
$session_user_id = require_admin($db, 'tokens.php');
$tokens = api_token_list($db);
$db->close();

$flash = http_string_parameter($_GET, 'msg', 32, false);
$flash_key = match ($flash) {
    'revoked' => 'api_token_revoked_notice',
    'failed' => 'api_token_operation_failed',
    default => '',
};

$lang_attr = 'en';
$page_title = 'API tokens - Administration';
$page_title_i18n = 'admin_tokens_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h1 data-i18n="admin_tokens_title">API tokens</h1>
    <p data-i18n="admin_tokens_intro">Every personal token in this installation. Revoking a token takes effect immediately.</p>
    <?php if ($flash_key !== ''): ?>
    <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars(opng_t($flash_key, $flash), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php endif; ?>

    <?php if ($tokens === []): ?>
    <p data-i18n="api_token_none">No API tokens exist.</p>
    <?php else: ?>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th scope="col" data-i18n="username">Username</th>
                <th scope="col" data-i18n="api_token_name">Token name</th>
                <th scope="col" data-i18n="api_token_scopes">Scopes</th>
                <th scope="col" data-i18n="api_token_created">Created</th>
                <th scope="col" data-i18n="api_token_last_used">Last used</th>
                <th scope="col" data-i18n="api_token_status">Status</th>
                <th scope="col" data-i18n="admin_actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tokens as $token): ?>
                <?php $revoked = $token['revoked_at'] !== null; ?>
                <tr>
                    <td><?php echo htmlspecialchars($token['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($token['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($token['scopes'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo opng_datetime_html($token['created_at']); ?></td>
                    <td><?php echo $token['last_used_at'] !== null ? opng_datetime_html($token['last_used_at']) : '<span data-i18n="api_token_never">Never</span>'; ?></td>
                    <td data-i18n="<?php echo $revoked ? 'api_token_status_revoked' : 'api_token_status_active'; ?>"><?php echo $revoked ? 'Revoked' : 'Active'; ?></td>
                    <td>
                        <?php if (!$revoked): ?>
                        <form method="post" action="tokens_action.php" class="inline-form" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="token_id" value="<?php echo (int) $token['id']; ?>">
                            <label for="admin_revoke_password_<?php echo (int) $token['id']; ?>" data-i18n="reauth_password">Current password:</label>
                            <input id="admin_revoke_password_<?php echo (int) $token['id']; ?>" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                            <label for="admin_revoke_mfa_<?php echo (int) $token['id']; ?>" data-i18n="mfa_code_optional">Authentication code (if enabled):</label>
                            <input id="admin_revoke_mfa_<?php echo (int) $token['id']; ?>" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code">
                            <button type="submit" class="danger" data-i18n="api_token_revoke">Revoke</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <p><a href="admin.php" data-i18n="cancel">Cancel</a></p>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
