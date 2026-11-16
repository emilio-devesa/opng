<?php

declare(strict_types=1);

// auth/tokens.php
// Personal API token management (10.4).

require_once('../includes/session.php');
session_start_secured();
require_once('../includes/csrf.php');
require_once('../includes/http_input.php');
require_once('../includes/error_messages.php');
require_once('../includes/assets.php');
require_once('../includes/admin_guard.php');
require_once('../includes/api_tokens.php');
require_once('../includes/i18n.php');
require_once('../database.php');

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
$tokens = api_token_list($db, $user_id);
$db->close();

$created_token = null;
if (isset($_SESSION['api_token_created']) && is_array($_SESSION['api_token_created'])) {
    $created_token = $_SESSION['api_token_created'];
    unset($_SESSION['api_token_created']);
}
$flash = http_string_parameter($_GET, 'msg', 32, false);
$flash_key = match ($flash) {
    'created' => 'api_token_created_notice',
    'revoked' => 'api_token_revoked_notice',
    'failed' => 'api_token_operation_failed',
    default => '',
};

$lang_attr = 'en';
$page_title = 'API tokens';
$page_title_i18n = 'api_tokens_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <section class="auth-page tokens-page">
        <h1 data-i18n="api_tokens_title">API tokens</h1>
        <p data-i18n="api_tokens_intro">Tokens authenticate read-only requests to the API. The clear value is shown once; only a hash is stored.</p>

        <?php if ($created_token !== null && is_string($created_token['clear'] ?? null)): ?>
        <section class="box" aria-labelledby="created-token-title">
            <h2 id="created-token-title" data-i18n="api_token_created_title">New token</h2>
            <p role="status" data-i18n="api_token_created_notice">Copy this token now. It will not be shown again.</p>
            <p><code class="api-token-value"><?php echo htmlspecialchars((string) $created_token['clear'], ENT_QUOTES, 'UTF-8'); ?></code></p>
        </section>
        <?php endif; ?>

        <?php if ($flash_key !== ''): ?>
        <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(opng_t($flash_key, $flash), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <?php endif; ?>

        <section class="box" aria-labelledby="new-token-title">
            <h2 id="new-token-title" data-i18n="api_token_new">Create a token</h2>
            <form method="post" action="tokens_action.php" class="auth-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">
                <p>
                    <label for="token_name" data-i18n="api_token_name">Token name:</label>
                    <input id="token_name" type="text" name="name" maxlength="80" required>
                </p>
                <fieldset class="access-options">
                    <legend data-i18n="api_token_scopes">Scopes</legend>
                    <label><input type="checkbox" name="scopes[]" value="read" checked> <span data-i18n="api_scope_read">Read entries</span></label>
                    <label><input type="checkbox" name="scopes[]" value="write"> <span data-i18n="api_scope_write">Write (reserved)</span></label>
                </fieldset>
                <p>
                    <label for="token_expiry" data-i18n="api_token_expiry">Expires:</label>
                    <select id="token_expiry" name="expires_days">
                        <option value="30" data-i18n="api_token_expiry_30">30 days</option>
                        <option value="90" data-i18n="api_token_expiry_90">90 days</option>
                        <option value="365" data-i18n="api_token_expiry_365">365 days</option>
                        <option value="" data-i18n="api_token_expiry_never">Never</option>
                    </select>
                </p>
                <button type="submit" data-i18n="api_token_create">Create token</button>
            </form>
        </section>

        <section class="box" aria-labelledby="token-list-title">
            <h2 id="token-list-title" data-i18n="api_token_list">Your tokens</h2>
            <?php if ($tokens === []): ?>
            <p data-i18n="api_token_none">You have no API tokens.</p>
            <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        <th scope="col" data-i18n="api_token_name">Token name</th>
                        <th scope="col" data-i18n="api_token_scopes">Scopes</th>
                        <th scope="col" data-i18n="api_token_created">Created</th>
                        <th scope="col" data-i18n="api_token_last_used">Last used</th>
                        <th scope="col" data-i18n="api_token_expires">Expires</th>
                        <th scope="col" data-i18n="api_token_status">Status</th>
                        <th scope="col"><span class="visually-hidden" data-i18n="api_token_revoke">Revoke</span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <?php $revoked = $token['revoked_at'] !== null; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($token['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($token['scopes'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo opng_datetime_html($token['created_at']); ?></td>
                            <td><?php echo $token['last_used_at'] !== null ? opng_datetime_html($token['last_used_at']) : '<span data-i18n="api_token_never">Never</span>'; ?></td>
                            <td><?php echo $token['expires_at'] !== null ? opng_datetime_html($token['expires_at']) : '<span data-i18n="api_token_never">Never</span>'; ?></td>
                            <td data-i18n="<?php echo $revoked ? 'api_token_status_revoked' : 'api_token_status_active'; ?>"><?php echo $revoked ? 'Revoked' : 'Active'; ?></td>
                            <td>
                                <?php if (!$revoked): ?>
                                <form method="post" action="tokens_action.php" class="inline-form" autocomplete="off">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $token['id']; ?>">
                                    <label for="revoke_password_<?php echo (int) $token['id']; ?>" data-i18n="reauth_password">Current password:</label>
                                    <input id="revoke_password_<?php echo (int) $token['id']; ?>" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                                    <label for="revoke_mfa_<?php echo (int) $token['id']; ?>" data-i18n="mfa_code_optional">Authentication code (if enabled):</label>
                                    <input id="revoke_mfa_<?php echo (int) $token['id']; ?>" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code">
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
        </section>
        <p><a href="security.php" data-i18n="security_title">Account security</a></p>
    </section>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
