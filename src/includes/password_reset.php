<?php

function password_reset_simulation_enabled(): bool
{
    if (getenv('OPNG_DEV_RESET') !== '1') {
        return false;
    }

    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    $request_host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($remote_addr) || !is_string($request_host)) {
        return false;
    }

    $host = parse_url('http://' . $request_host, PHP_URL_HOST);

    return in_array($remote_addr, ['127.0.0.1', '::1'], true)
        && is_string($host)
        && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

function password_reset_token_hash(string $token): ?string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }

    return hash('sha256', $token);
}
