<?php

declare(strict_types=1);

/**
 * External backup regression test (10.7).
 *
 * Verifies configuration validation, AES-256-GCM encryption, checksum
 * rejection and retention pruning. Restore verification itself is covered by
 * the database integration suite.
 */

require __DIR__ . '/../src/includes/external_backup.php';

function backup_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function backup_pass(string $message): void
{
    echo "PASS: {$message}\n";
}

function backup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        backup_fail($message);
    }
    backup_pass($message);
}

function backup_remove_tree(string $directory): void
{
    $entries = glob(rtrim($directory, '/') . '/*');
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                backup_remove_tree($entry);
            } else {
                @unlink($entry);
            }
        }
    }
    @rmdir($directory);
}

$directory = sys_get_temp_dir() . '/opng_backup_test_' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    backup_fail('could not create the temporary backup directory');
}

$key = base64_encode(str_repeat('b', 32));
putenv('OPNG_BACKUP_TARGET=local');
putenv('OPNG_BACKUP_DIR=' . $directory);
putenv('OPNG_BACKUP_RETENTION_DAYS=1');
putenv('OPNG_BACKUP_KEY=' . $key);
putenv('OPNG_BACKUP_S3_ENDPOINT');
putenv('OPNG_BACKUP_S3_BUCKET');
putenv('OPNG_BACKUP_S3_ACCESS_KEY');
putenv('OPNG_BACKUP_S3_SECRET_KEY');

$config = external_backup_config();
backup_assert($config['target'] === 'local', 'the local target is selected');
backup_assert(external_backup_validate_config($config) === [], 'a complete local configuration validates');

putenv('OPNG_BACKUP_KEY=not-base64');
$invalid = external_backup_config();
backup_assert(
    external_backup_validate_config($invalid) !== [],
    'an invalid OPNG_BACKUP_KEY is rejected'
);
putenv('OPNG_BACKUP_KEY=' . $key);

putenv('OPNG_BACKUP_DIR=/path/that/does/not/exist');
$invalid = external_backup_config();
backup_assert(
    external_backup_validate_config($invalid) !== [],
    'a missing local directory is rejected'
);
putenv('OPNG_BACKUP_DIR=' . $directory);

putenv('OPNG_BACKUP_TARGET=s3');
putenv('OPNG_BACKUP_S3_ENDPOINT');
putenv('OPNG_BACKUP_S3_BUCKET');
putenv('OPNG_BACKUP_S3_ACCESS_KEY');
putenv('OPNG_BACKUP_S3_SECRET_KEY');
$invalid = external_backup_config();
backup_assert(
    count(external_backup_validate_config($invalid)) >= 4,
    'the s3 target requires endpoint, bucket and credentials'
);
putenv('OPNG_BACKUP_TARGET=local');

$plain = "CREATE TABLE example (id INT);\n";
$encrypted = external_backup_encrypt($plain);
backup_assert(
    str_starts_with($encrypted, OPNG_EXTERNAL_BACKUP_MAGIC),
    'encrypted backups carry a magic marker'
);
backup_assert(external_backup_decrypt($encrypted) === $plain, 'encrypted backups decrypt to the original');
backup_assert(external_backup_decrypt($plain) === $plain, 'unencrypted backups pass through');

$tampered = base64_decode(substr($encrypted, strlen(OPNG_EXTERNAL_BACKUP_MAGIC)), true);
if (!is_string($tampered)) {
    backup_fail('encrypted payload is valid base64');
}
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);
$tamper_rejected = false;
try {
    external_backup_decrypt(OPNG_EXTERNAL_BACKUP_MAGIC . base64_encode($tampered));
} catch (RuntimeException) {
    $tamper_rejected = true;
}
backup_assert($tamper_rejected, 'tampered encrypted backups fail authentication');

$old = $directory . '/opng-20260101-123456-abcdef01.sql';
$old_enc = $directory . '/opng-20260102-123456-abcdef02.enc';
$new = $directory . '/opng-20260103-123456-abcdef03.sql';
$foreign = $directory . '/opng-manual.sql';
$link = $directory . '/opng-20260104-123456-abcdef04.sql';
foreach ([$foreign, $old . '.tmp'] as $other) {
    file_put_contents($other, 'not a completed OPNG backup');
    touch($other, time() - 3 * 86400);
}
symlink($foreign, $link);
file_put_contents($old, 'old');
file_put_contents($old . '.sha256', 'old-checksum  opng-old.sql');
file_put_contents($old_enc, 'old-encrypted');
file_put_contents($old_enc . '.sha256', 'old-checksum  opng-old.enc');
file_put_contents($new, 'new');
file_put_contents($new . '.sha256', 'new-checksum  opng-new.sql');
touch($old, time() - 3 * 86400);
touch($old . '.sha256', time() - 3 * 86400);
touch($old_enc, time() - 3 * 86400);
touch($old_enc . '.sha256', time() - 3 * 86400);
touch($new, time());

$removed = external_backup_prune_local(['directory' => $directory, 'retention_days' => 1]);
backup_assert($removed === 2, 'retention removes expired encrypted and plain backups');
backup_assert(is_file($foreign) && is_link($link) && is_file($old . '.tmp'), 'foreign, linked and temporary files survive retention');
backup_assert(!is_file($old) && !is_file($old_enc), 'expired backups are deleted');
backup_assert(
    !is_file($old . '.sha256') && !is_file($old_enc . '.sha256'),
    'checksum sidecars are deleted with their backups'
);
backup_assert(is_file($new) && is_file($new . '.sha256'), 'backups inside the retention window are kept');

$bad = $directory . '/opng-bad.sql';
file_put_contents($bad, 'checksum payload');
file_put_contents($bad . '.sha256', str_repeat('0', 64) . '  opng-bad.sql');
$checksum_rejected = false;
try {
    external_backup_verify_file($bad);
} catch (RuntimeException $exception) {
    $checksum_rejected = str_contains($exception->getMessage(), 'checksum');
}
backup_assert($checksum_rejected, 'a mismatched SHA-256 sidecar is rejected before any import');

foreach ([
    ['missing.sql', $plain, false, 'checksum'],
    ['empty.sql', '', true, 'inventory'],
    ['legacy.sql', $plain, true, 'inventory'],
    ['truncated.sql', "-- OPNG-SNAPSHOT-v1 {", true, 'inventory'],
    ['fake.enc', $plain, true, 'encryption'],
] as [$filename, $contents, $withChecksum, $reason]) {
    $path = $directory . '/' . $filename;
    file_put_contents($path, $contents);
    if ($withChecksum) {
        file_put_contents($path . '.sha256', hash('sha256', $contents) . "  {$filename}\n");
    }
    try {
        external_backup_verify_file($path);
        backup_fail("verification accepted {$filename}");
    } catch (RuntimeException $exception) {
        backup_assert(str_contains($exception->getMessage(), $reason), "verification rejects {$filename} before import");
    }
}

backup_remove_tree($directory);
echo "External backup regression passed: configuration, encryption, checksums and retention.\n";
