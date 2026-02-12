<?php
/**
 * AMF gateway entrypoint for local PVZ.
 *
 * Real traffic uses:
 *   POST /pvz/amf/
 *   Content-Type: application/x-amf
 *
 * Keep this file focused on AMF transport (decode/dispatch/encode).
 * Do not echo notices/warnings; AMF is binary.
 */

declare(strict_types=1);

function amf_send_text(string $text, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $text;
    exit;
}

function amf_short_token(int $bytes = 16): string
{
    return strtoupper(bin2hex(random_bytes($bytes)));
}

function amf_set_short_lived_token_cookie(): void
{
    // Real site sets a 1-second HttpOnly token frequently. Keep compatible.
    setcookie('token', amf_short_token(16), [
        'expires' => time() + 1,
        'path' => '/',
        'httponly' => true,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

function amf_pvzol_seed(): string
{
    $seed = (string)($_COOKIE['pvzol'] ?? '');
    if ($seed !== '') {
        return $seed;
    }

    $seed = (string)($_GET['seed'] ?? '');
    if ($seed === '') {
        $seed = 'local';
    }

    setcookie('pvzol', $seed, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);

    return $seed;
}

amf_set_short_lived_token_cookie();
amf_pvzol_seed();

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!preg_match('#^/pvz/amf/?$#', $path)) {
    amf_send_text('Not Found', 404);
}

// Placeholder until AMF decode/encode is implemented.
amf_send_text('AMF not implemented', 501);

