<?php
/**
 * HTTP entrypoint for /pvz/index.php/* (boot chain + text/xml endpoints).
 *
 * Keep this file thin:
 * - Set cookies required by the client
 * - Parse the path
 * - Dispatch to app/http controllers
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/http/Response.php';
require_once __DIR__ . '/../app/http/HttpUtil.php';
require_once __DIR__ . '/../app/http/BootChainController.php';
require_once __DIR__ . '/../app/core/SessionResolver.php';

function http_send(HttpResponse $resp): void
{
    http_response_code($resp->status);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    foreach ($resp->headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo $resp->body;
    exit;
}

// Client-visible cookie behavior (matches real traffic style).
HttpUtil::setShortLivedTokenCookie();
$seed = HttpUtil::pvzolSeed();
$uid = HttpUtil::userIdFromSeed($seed);

$path = HttpUtil::routePath((string)($_SERVER['REQUEST_URI'] ?? '/'));
$cookieSig = strtolower(trim((string)($_COOKIE['sig'] ?? '')));
$sess = SessionResolver::resolveFromRequest($cookieSig);

if ($path === '') {
    if ((int)($sess['user_id'] ?? 0) <= 0) {
        header('Location: /pvz/usersetting');
        exit;
    }
    // Browser entry: forward to Flash boot file with required flashvars.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8081');
    $basePath = '/pvz/';
    $baseUrl = $scheme . '://' . $host . $basePath . 'index.php/';
    $baseInfo = $scheme . '://' . $host . $basePath;
    $swfUrl = '/pvz/main.swf?base_url=' . rawurlencode($baseUrl)
        . '&base_url_info=' . rawurlencode($baseInfo)
        . '&seed=' . rawurlencode($seed);
    header('Location: ' . $swfUrl);
    exit;
}

$ctx = [
    'seed' => $seed,
    'uid' => (int)($sess['user_id'] ?? $uid),
    'sig' => (string)($sess['sig'] ?? $cookieSig),
];
$resp = BootChainController::dispatch($path, $ctx);
if ($resp instanceof HttpResponse) {
    http_send($resp);
}

http_send(HttpResponse::text('Not Found', 404));
