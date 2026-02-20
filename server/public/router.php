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

function http_access_log(string $line): void
{
    $gr = game_root();
    $path = $gr . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'http_access.log';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

function http_log_enabled(): bool
{
    // Opt-in request logging to avoid noisy logs during normal play.
    // Create: server/game_root/runtime/config/enable_http_log.txt
    $gr = game_root();
    $flag = $gr . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'enable_http_log.txt';
    return is_file($flag);
}

$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

// Let built-in server serve existing files under docroot.
$docRoot = __DIR__;
$target = $docRoot . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if (is_file($target)) {
    if (http_log_enabled()) {
        http_access_log('[' . date('Y-m-d H:i:s') . '] ' . $method . ' ' . $uri . ' -> docroot');
    }
    return false;
}

// Normalize accidental leading spaces: /pvz/%20xxx -> /pvz/xxx
$normalized = preg_replace('#/pvz/%20+#', '/pvz/', $uri);
if (http_log_enabled()) {
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $hint = $ct !== '' ? (' ct=' . $ct) : '';
    http_access_log('[' . date('Y-m-d H:i:s') . '] ' . $method . ' ' . $normalized . $hint);
}

// Dynamic routes.
// DelSkillWindow sends "/pvz/index.php//organism/removeskill/..." (double slash style).
if (preg_match('#^/pvz/index\.php/+organism/removeskill/.*$#', $normalized)) {
    require $docRoot . '/removeskill.php';
    exit;
}
if (preg_match('#^/pvz/index\\.php/.*$#', $normalized)) {
    require $docRoot . '/index.php';
    exit;
}
if ($normalized === '/pvz/usersetting' || $normalized === '/pvz/usersetting/') {
    require $docRoot . '/usersetting.php';
    exit;
}
if ($normalized === '/pvz/sig' || $normalized === '/pvz/sig/') {
    require $docRoot . '/sig.php';
    exit;
}
if ($normalized === '/pvz/debug/warehouse' || $normalized === '/pvz/debug/warehouse/') {
    require $docRoot . '/debug_warehouse.php';
    exit;
}
if ($normalized === '/pvz/debug/org' || $normalized === '/pvz/debug/org/') {
    require $docRoot . '/debug_org.php';
    exit;
}
if ($normalized === '/pvz/admin/db' || $normalized === '/pvz/admin/db/') {
    require $docRoot . '/db_admin.php';
    exit;
}
if (preg_match('#^/pvz/organism/removeskill/.*$#', $normalized)) {
    require $docRoot . '/removeskill.php';
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
    $clientAvatar = $clientAssets . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'avatar';
    $clientEvents = $clientAssets . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'events';
    $clientLocale = $client . DIRECTORY_SEPARATOR . 'locale';
    $clientManifest = $client . DIRECTORY_SEPARATOR . 'manifest';

    $try = [];
    if ($rel === '' || $rel === '/') {
        // nothing
    } else {
        // SWF and libs are usually under client/swf/*
        $try[] = $clientSwf . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        // Real traffic often uses /pvz/avatar/<id>.jpg
        if (str_starts_with($rel, 'avatar/')) {
            $try[] = $clientAvatar . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('avatar/')));
        }

        // Some configs use /pvz/events/<name>.png
        if (str_starts_with($rel, 'events/')) {
            $try[] = $clientEvents . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('events/')));
        }

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
