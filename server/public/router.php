<?php
/**
 * PHP built-in server router for local PVZ.
 *
 * Usage:
 *   C:\php\php.exe -S 127.0.0.1:8081 -t D:\Hreta_working\server\game_root\server\public D:\Hreta_working\server\game_root\server\public\router.php
 *
 * What it does:
 * - Serves files that exist under docroot normally (return false).
 * - Maps /pvz/* resource paths to server/game_root/client/* for offline resources (SWF/libs/etc),
 *   with a fallback to docroot for editable copies under server/public/*.
 * - Dispatches dynamic routes to index.php for /pvz/index.php/* and to amf.php for /pvz/amf/*.
 */

declare(strict_types=1);

function game_root(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }
    // server/game_root/server/public -> server/game_root
    $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    return $root;
}

function guess_mime(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return [
        'xml' => 'text/xml; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'swf' => 'application/x-shockwave-flash',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
    ][$ext] ?? 'application/octet-stream';
}

function serve_file(string $path): void
{
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }

    header('Content-Type: ' . guess_mime($path));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($path);
    exit;
}

function is_safe_rel(string $rel): bool
{
    // Block traversal and absolute paths.
    if ($rel === '' || $rel === '/' || $rel === '\\') {
        return false;
    }
    if (str_contains($rel, '..')) {
        return false;
    }
    if (preg_match('#^[a-zA-Z]:#', $rel)) {
        return false;
    }
    if (str_starts_with($rel, '/') || str_starts_with($rel, '\\')) {
        return false;
    }
    return true;
}

$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Let built-in server serve existing files under docroot.
$docRoot = __DIR__;
$target = $docRoot . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if (is_file($target)) {
    return false;
}

// Normalize accidental leading spaces: /pvz/%20xxx -> /pvz/xxx
$normalized = preg_replace('#/pvz/%20+#', '/pvz/', $uri);

// Dynamic routes.
if (preg_match('#^/pvz/index\\.php/.*$#', $normalized)) {
    require $docRoot . '/index.php';
    exit;
}
if (preg_match('#^/pvz/amf/.*$#', $normalized)) {
    require $docRoot . '/amf.php';
    exit;
}

// Resource mapping to workspace cache.
if (preg_match('#^/pvz/(.*)$#', $normalized, $m)) {
    $rel = $m[1];
    if (!is_safe_rel($rel)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad Request';
        exit;
    }

    $gr = game_root();
    $client = $gr . DIRECTORY_SEPARATOR . 'client';
    $clientSwf = $client . DIRECTORY_SEPARATOR . 'swf';
    $clientAssets = $client . DIRECTORY_SEPARATOR . 'assets';
    $clientLocale = $client . DIRECTORY_SEPARATOR . 'locale';
    $clientManifest = $client . DIRECTORY_SEPARATOR . 'manifest';

    $try = [];
    if ($rel === '' || $rel === '/') {
        // nothing
    } else {
        // SWF and libs are usually under client/swf/*
        $try[] = $clientSwf . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        // Optional: if you mirror /pvz/assets/*, /pvz/locale/*, /pvz/manifest/* under client.
        if (str_starts_with($rel, 'assets/')) {
            $try[] = $clientAssets . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('assets/')));
        }
        if (str_starts_with($rel, 'locale/')) {
            $try[] = $clientLocale . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('locale/')));
        }
        if (str_starts_with($rel, 'manifest/')) {
            $try[] = $clientManifest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('manifest/')));
        }

        // Also allow serving editable copies under server/public for assets/config/php_xml/json.
        $try[] = $docRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    foreach ($try as $p) {
        if (is_file($p)) {
            serve_file($p);
        }
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
