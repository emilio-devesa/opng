<?php

declare(strict_types=1);

// includes/mailer.php
// Pluggable mail delivery for account recovery (10.1).
//
// Supported transports:
//   smtp      Native SMTP client with optional STARTTLS/implicit TLS and AUTH.
//   sendmail  PHP mail(), which hands the message to the local MTA.
//   log       Development transport: writes the message to the application log
//             (or OPNG_MAIL_LOG) and never contacts a network service.
//
// The implementation deliberately has no Composer dependency. Configuration is
// validated by tools/doctor.php before traffic is accepted.

require_once __DIR__ . '/logging.php';

const OPNG_MAIL_TRANSPORTS = ['smtp', 'sendmail', 'log'];

/**
 * @return array{
 *   transport: string,
 *   from: string,
 *   from_name: string,
 *   smtp_host: string,
 *   smtp_port: int,
 *   smtp_encryption: string,
 *   smtp_user: string,
 *   smtp_pass: string,
 *   smtp_timeout: int,
 *   smtp_verify_peer: bool,
 *   log_path: string
 * }
 */
function opng_mail_config(): array
{
    $transport = strtolower(trim((string) (getenv('OPNG_MAIL_TRANSPORT') ?: 'log')));
    if (!in_array($transport, OPNG_MAIL_TRANSPORTS, true)) {
        $transport = 'log';
    }

    $port = filter_var(getenv('OPNG_MAIL_SMTP_PORT'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $timeout = filter_var(getenv('OPNG_MAIL_SMTP_TIMEOUT'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 60],
    ]);
    $encryption = strtolower(trim((string) (getenv('OPNG_MAIL_SMTP_ENCRYPTION') ?: 'tls')));
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }

    return [
        'transport' => $transport,
        'from' => trim((string) (getenv('OPNG_MAIL_FROM') ?: 'no-reply@example.com')),
        'from_name' => trim((string) (getenv('OPNG_MAIL_FROM_NAME') ?: 'Open Pastebin NG')),
        'smtp_host' => trim((string) (getenv('OPNG_MAIL_SMTP_HOST') ?: '')),
        'smtp_port' => $port === false ? 587 : (int) $port,
        'smtp_encryption' => $encryption,
        'smtp_user' => (string) (getenv('OPNG_MAIL_SMTP_USER') ?: ''),
        'smtp_pass' => (string) (getenv('OPNG_MAIL_SMTP_PASS') ?: ''),
        'smtp_timeout' => $timeout === false ? 10 : (int) $timeout,
        'smtp_verify_peer' => getenv('OPNG_MAIL_SMTP_VERIFY_PEER') !== '0',
        'log_path' => trim((string) (getenv('OPNG_MAIL_LOG') ?: '')),
    ];
}

/**
 * Whether a configured transport can reach a real mailbox.
 */
function opng_mail_is_deliverable(): bool
{
    $config = opng_mail_config();

    return in_array($config['transport'], ['smtp', 'sendmail'], true);
}

/**
 * Configuration errors. An empty list means the selected transport is usable.
 *
 * @return list<string>
 */
/**
 * @param array<string, mixed>|null $config
 * @return list<string>
 */
function opng_mail_validate_config(?array $config = null): array
{
    $config = $config ?? opng_mail_config();
    $errors = [];

    if (!in_array($config['transport'], OPNG_MAIL_TRANSPORTS, true)) {
        $errors[] = 'OPNG_MAIL_TRANSPORT must be smtp, sendmail or log.';
    }
    if (filter_var($config['from'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'OPNG_MAIL_FROM must be a valid email address.';
    }
    if (preg_match('/[\r\n]/', $config['from_name']) === 1) {
        $errors[] = 'OPNG_MAIL_FROM_NAME must not contain line breaks.';
    }

    if ($config['transport'] === 'smtp') {
        if ($config['smtp_host'] === '' || preg_match('/[\r\n\s]/', $config['smtp_host']) === 1) {
            $errors[] = 'OPNG_MAIL_SMTP_HOST is required for the smtp transport.';
        }
        if ($config['smtp_port'] < 1 || $config['smtp_port'] > 65535) {
            $errors[] = 'OPNG_MAIL_SMTP_PORT must be between 1 and 65535.';
        }
        if (!in_array($config['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors[] = 'OPNG_MAIL_SMTP_ENCRYPTION must be tls, ssl or none.';
        }
        if (($config['smtp_user'] === '') !== ($config['smtp_pass'] === '')) {
            $errors[] = 'OPNG_MAIL_SMTP_USER and OPNG_MAIL_SMTP_PASS must be set together.';
        }
        if (preg_match('/[\r\n]/', $config['smtp_user']) === 1) {
            $errors[] = 'OPNG_MAIL_SMTP_USER must not contain line breaks.';
        }
        if ($config['smtp_encryption'] === 'none' && !$config['smtp_verify_peer']) {
            $errors[] = 'TLS is disabled and certificate verification is off; enable tls or ssl.';
        }
    }

    if ($config['transport'] === 'sendmail' && !function_exists('mail')) {
        $errors[] = 'The sendmail transport requires PHP mail().';
    }

    if ($config['transport'] === 'log' && $config['log_path'] !== '') {
        $directory = dirname($config['log_path']);
        if (!is_dir($directory) || !is_writable($directory)) {
            $errors[] = 'OPNG_MAIL_LOG must point to a writable directory.';
        }
    }

    return $errors;
}

/**
 * Encode a header value as a UTF-8 MIME word. Line breaks are rejected by the
 * caller; this function only handles non-ASCII text.
 */
function opng_mail_mime_header(string $value): string
{
    if (preg_match('/[\r\n]/', $value) === 1) {
        throw new InvalidArgumentException('Mail headers must not contain line breaks.');
    }
    if (preg_match('/[\x80-\xFF]/', $value) !== 1) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * Build a complete MIME message.
 *
 * @param array<string, mixed>|null $config
 * @return array{headers: list<string>, body: string}
 */
function opng_mail_build_message(
    string $to,
    string $subject,
    string $text,
    ?string $html = null,
    ?array $config = null
): array {
    $config = $config ?? opng_mail_config();
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Invalid mail recipient.');
    }
    if (preg_match('/[\r\n]/', $subject) === 1) {
        throw new InvalidArgumentException('Invalid mail subject.');
    }

    $from_name = $config['from_name'] !== ''
        ? opng_mail_mime_header($config['from_name']) . ' <' . $config['from'] . '>'
        : $config['from'];
    $headers = [
        'Date: ' . gmdate('r'),
        'From: ' . $from_name,
        'To: ' . $to,
        'Subject: ' . opng_mail_mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url('http://' . ($config['from']), PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'X-Mailer: Open Pastebin NG',
    ];

    if ($html === null || trim($html) === '') {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text), 76, "\r\n");
    } else {
        $boundary = 'opng-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n") . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n"
            . '--' . $boundary . "--\r\n";
    }

    return ['headers' => $headers, 'body' => $body];
}

/**
 * Send one message. Returns false and logs the reason on failure; callers must
 * never reveal transport details to an unauthenticated visitor.
 */
function opng_mail_send(
    string $to,
    string $subject,
    string $text,
    ?string $html = null
): bool {
    $config = opng_mail_config();
    $errors = opng_mail_validate_config($config);
    if ($errors !== []) {
        opng_log('error', 'mail_configuration_invalid', ['errors' => $errors]);
        return false;
    }

    try {
        $message = opng_mail_build_message($to, $subject, $text, $html, $config);
    } catch (InvalidArgumentException $exception) {
        opng_log('error', 'mail_message_invalid', ['error' => $exception->getMessage()]);
        return false;
    }

    $recipient_hash = hash('sha256', strtolower($to));
    $context = ['transport' => $config['transport'], 'recipient' => $recipient_hash];

    $sent = match ($config['transport']) {
        'smtp' => opng_mail_send_smtp($config, $to, $subject, $message),
        'sendmail' => opng_mail_send_sendmail($config, $to, $subject, $message),
        'log' => opng_mail_send_log($config, $to, $subject, $text, $context),
        default => false,
    };

    if ($sent) {
        opng_log('info', 'mail_sent', $context);
    } else {
        opng_log('error', 'mail_delivery_failed', $context);
    }

    return $sent;
}

/**
 * @param array{log_path: string} $config
 * @param array<string, mixed> $context
 */
function opng_mail_send_log(array $config, string $to, string $subject, string $text, array $context): bool
{
    $record = [
        'ts' => gmdate('c'),
        'request_id' => opng_request_id(),
        'transport' => 'log',
        'to' => $to,
        'recipient_hash' => $context['recipient'] ?? '',
        'subject' => $subject,
        'text' => $text,
    ];
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($line)) {
        return false;
    }

    if ($config['log_path'] !== '') {
        $written = @file_put_contents($config['log_path'], $line . "\n", FILE_APPEND | LOCK_EX);
        return is_int($written) && $written > 0;
    }

    opng_log('info', 'mail_logged', $record);
    return true;
}

/**
 * @param array<string, mixed> $config
 * @param array{headers: list<string>, body: string} $message
 */
function opng_mail_send_sendmail(array $config, string $to, string $subject, array $message): bool
{
    // mail() takes the subject as its own argument, so it must not also appear
    // in the header block (duplicate Subject headers break some MTAs).
    $headers = implode("\r\n", array_filter(
        $message['headers'],
        static fn (string $header): bool => !str_starts_with($header, 'To: ')
            && !str_starts_with($header, 'Subject: ')
    ));

    return @mail($to, opng_mail_mime_header($subject), $message['body'], $headers);
}

/**
 * Minimal SMTP client. Supports implicit TLS (ssl), STARTTLS (tls) and AUTH
 * LOGIN/PLAIN. Every response is checked; a non-2xx reply fails the delivery.
 *
 * @param array<string, mixed> $config
 * @param array{headers: list<string>, body: string} $message
 */
function opng_mail_send_smtp(array $config, string $to, string $subject, array $message): bool
{
    $host = (string) $config['smtp_host'];
    $port = (int) $config['smtp_port'];
    $encryption = (string) $config['smtp_encryption'];
    $timeout = (int) $config['smtp_timeout'];
    $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
    $error_number = 0;
    $error_message = '';
    $socket = @stream_socket_client(
        $scheme . '://' . $host . ':' . $port,
        $error_number,
        $error_message,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        opng_log('error', 'smtp_connect_failed', [
            'host' => $host,
            'port' => $port,
            'error_number' => $error_number,
            'error' => $error_message,
        ]);
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        if (opng_smtp_expect($socket, [220], 'greeting') === null) {
            return false;
        }

        $hostname = gethostname();
        $hostname = is_string($hostname) && $hostname !== '' ? $hostname : 'localhost';
        if (opng_smtp_command($socket, 'EHLO ' . $hostname, [250], 'ehlo') === null) {
            return false;
        }

        if ($encryption === 'tls') {
            if (opng_smtp_command($socket, 'STARTTLS', [220], 'starttls') === null) {
                return false;
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $crypto) !== true) {
                opng_log('error', 'smtp_starttls_failed', ['host' => $host]);
                return false;
            }
            if (opng_smtp_command($socket, 'EHLO ' . $hostname, [250], 'ehlo_tls') === null) {
                return false;
            }
        }

        if ((string) $config['smtp_user'] !== '') {
            if (!opng_smtp_authenticate($socket, (string) $config['smtp_user'], (string) $config['smtp_pass'])) {
                return false;
            }
        }

        if (opng_smtp_command($socket, 'MAIL FROM:<' . $config['from'] . '>', [250], 'mail_from') === null) {
            return false;
        }
        if (opng_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'rcpt_to') === null) {
            return false;
        }
        if (opng_smtp_command($socket, 'DATA', [354], 'data') === null) {
            return false;
        }

        $payload = implode("\r\n", $message['headers']) . "\r\n\r\n" . $message['body'];
        $payload = preg_replace("/(?m)^\./", '..', $payload) ?? $payload;
        $payload = str_replace(["\r\n", "\r"], "\n", $payload);
        $payload = str_replace("\n", "\r\n", $payload);
        if (!str_ends_with($payload, "\r\n")) {
            $payload .= "\r\n";
        }
        fwrite($socket, $payload . ".\r\n");
        if (opng_smtp_expect($socket, [250], 'data_end') === null) {
            return false;
        }

        opng_smtp_command($socket, 'QUIT', [221], 'quit');
        return true;
    } catch (Throwable $exception) {
        opng_log_exception('smtp_delivery_failed', $exception);
        return false;
    } finally {
        @fclose($socket);
    }
}

/**
 * @param resource $socket
 * @param list<int> $codes
 */
function opng_smtp_command($socket, string $command, array $codes, string $stage): ?string
{
    if (@fwrite($socket, $command . "\r\n") === false) {
        opng_log('error', 'smtp_write_failed', ['stage' => $stage]);
        return null;
    }

    return opng_smtp_expect($socket, $codes, $stage);
}

/**
 * Read a (possibly multiline) SMTP response and validate its status code.
 *
 * @param resource $socket
 * @param list<int> $codes
 */
function opng_smtp_expect($socket, array $codes, string $stage): ?string
{
    $response = '';
    $code = null;
    $complete = false;
    for ($lines = 0; $lines < 100; $lines++) {
        $line = @fgets($socket, 4096);
        if (!is_string($line) || $line === '') {
            opng_log('error', 'smtp_read_failed', ['stage' => $stage]);
            return null;
        }
        $response .= $line;
        if (preg_match('/\A(\d{3})([ -])/', $line, $match) !== 1) {
            opng_log('error', 'smtp_response_invalid', ['stage' => $stage]);
            return null;
        }
        $code = (int) $match[1];
        if ($match[2] === ' ') {
            $complete = true;
            break;
        }
    }

    if (!$complete) {
        opng_log('error', 'smtp_response_truncated', ['stage' => $stage]);
        return null;
    }

    if (!in_array($code, $codes, true)) {
        opng_log('error', 'smtp_response_rejected', [
            'stage' => $stage,
            'code' => $code,
            'response' => trim($response),
        ]);
        return null;
    }

    return $response;
}

/**
 * @param resource $socket
 */
function opng_smtp_authenticate($socket, string $user, string $password): bool
{
    $login = opng_smtp_command($socket, 'AUTH LOGIN', [334], 'auth_login');
    if ($login !== null) {
        if (opng_smtp_command($socket, base64_encode($user), [334], 'auth_user') === null) {
            return false;
        }
        if (opng_smtp_command($socket, base64_encode($password), [235], 'auth_pass') === null) {
            return false;
        }
        return true;
    }

    $plain = base64_encode("\0" . $user . "\0" . $password);
    if (opng_smtp_command($socket, 'AUTH PLAIN ' . $plain, [235], 'auth_plain') === null) {
        return false;
    }

    return true;
}
