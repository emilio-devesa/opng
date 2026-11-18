<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// admin/external_backup.php
// Off-host backup status and manual run (10.7).
session_start_secured();
require_once('../includes/admin_guard.php');
require_once('../includes/external_backup.php');
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
$session_user_id = require_admin($db, 'external_backup.php');
$db->close();

$config = external_backup_config();
$config_errors = external_backup_validate_config($config);
// Encryption is only applied to local targets. S3 objects are uploaded as-is.
$encryption_active = $config['target'] === 'local' && $config['key'] !== '';
$local_backups = [];
if ($config['target'] === 'local' && $config['directory'] !== '' && is_dir($config['directory'])) {
    $files = glob(rtrim($config['directory'], '/') . '/opng-*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (str_ends_with($file, '.sha256') || str_ends_with($file, '.tmp')) {
                continue;
            }
            $local_backups[] = [
                'name' => basename($file),
                'bytes' => (int) (@filesize($file) ?: 0),
                'mtime' => (int) (@filemtime($file) ?: 0),
            ];
        }
    }
    usort($local_backups, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
}

$flash = http_string_parameter($_GET, 'msg', 32, false);
$flash_key = match ($flash) {
    'created' => 'external_backup_created',
    'verified' => 'external_backup_verified',
    'failed' => 'external_backup_failed',
    'disabled' => 'external_backup_disabled',
    default => '',
};

$lang_attr = 'en';
$page_title = 'External backups - Administration';
$page_title_i18n = 'external_backup_title';
$base = '../';
require(__DIR__ . '/../includes/layout_header.php');
?>
    <h1 data-i18n="external_backup_title">External backups</h1>
    <p data-i18n="external_backup_intro">Scheduled off-host copies with a SHA-256 checksum, retention and optional encryption. Restore verification imports the dump into a disposable schema.</p>

    <?php if ($flash_key !== ''): ?>
    <p class="flash-message" role="status" data-i18n="<?php echo htmlspecialchars($flash_key, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars(opng_t($flash_key, $flash), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php endif; ?>

    <fieldset class="admin-metrics">
        <legend data-i18n="external_backup_status">Configuration</legend>
        <dl class="metrics-grid">
            <div><dt data-i18n="external_backup_target">Target</dt><dd><?php echo htmlspecialchars($config['target'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt data-i18n="external_backup_retention">Retention (days)</dt><dd><?php echo (int) $config['retention_days']; ?></dd></div>
            <div><dt data-i18n="external_backup_encryption">Encryption</dt><dd data-i18n="<?php echo $encryption_active ? 'external_backup_encryption_on' : 'external_backup_encryption_off'; ?>"><?php echo $encryption_active ? 'Enabled' : 'Disabled'; ?></dd></div>
            <?php if ($config['target'] === 'local'): ?>
            <div><dt data-i18n="external_backup_directory">Directory</dt><dd><code><?php echo htmlspecialchars($config['directory'], ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
            <?php elseif ($config['target'] === 's3'): ?>
            <div><dt data-i18n="external_backup_bucket">Bucket</dt><dd><code><?php echo htmlspecialchars($config['s3_bucket'], ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($config_errors !== []): ?>
        <ul class="form-error" role="alert">
            <?php foreach ($config_errors as $error): ?>
            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <p class="form-help" data-i18n="external_backup_cli_help">Run <code>php tools/backup_external.php</code> from cron for scheduled copies.</p>
    </fieldset>

    <form method="post" action="external_backup_action.php" class="admin-search-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="run">
        <label for="backup_run_password" data-i18n="reauth_password">Current password:</label>
        <input id="backup_run_password" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
        <label for="backup_run_mfa" data-i18n="mfa_code_optional">Authentication code (if enabled):</label>
        <input id="backup_run_mfa" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code">
        <button type="submit" data-i18n="external_backup_run"<?php echo $config['target'] === 'none' ? ' disabled' : ''; ?>>Run backup now</button>
    </form>

    <?php if ($config['target'] === 'local'): ?>
    <h2 data-i18n="external_backup_files">Local backups</h2>
    <?php if ($local_backups === []): ?>
    <p data-i18n="external_backup_none">No external backups have been written yet.</p>
    <?php else: ?>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th scope="col" data-i18n="external_backup_file">File</th>
                <th scope="col" data-i18n="external_backup_size">Size (bytes)</th>
                <th scope="col" data-i18n="date">Date</th>
                <th scope="col" data-i18n="external_backup_verify">Verify</th>
            </tr></thead>
            <tbody>
            <?php foreach ($local_backups as $backup): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo number_format($backup['bytes']); ?></td>
                    <td><?php echo $backup['mtime'] > 0 ? opng_datetime_html(gmdate('Y-m-d H:i:s', $backup['mtime'])) : ''; ?></td>
                    <td>
                        <form method="post" action="external_backup_action.php" class="inline-form" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="verify_password_<?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="reauth_password">Current password:</label>
                            <input id="verify_password_<?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?>" type="password" name="current_password" maxlength="72" autocomplete="current-password" required>
                            <label for="verify_mfa_<?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?>" data-i18n="mfa_code_optional">Authentication code (if enabled):</label>
                            <input id="verify_mfa_<?php echo htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8'); ?>" type="text" name="mfa_code" maxlength="32" inputmode="numeric" autocomplete="one-time-code">
                            <button type="submit" data-i18n="external_backup_verify">Verify</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <p><a href="admin.php" data-i18n="cancel">Cancel</a></p>
<?php require(__DIR__ . '/../includes/layout_footer.php'); ?>
