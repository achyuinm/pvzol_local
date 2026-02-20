<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/http/Response.php';
require_once __DIR__ . '/../app/http/BootChainController.php';

function debug_warehouse_send(HttpResponse $resp): void
{
    http_response_code($resp->status);
    foreach ($resp->headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo $resp->body;
    exit;
}

$sig = trim((string)($_GET['sig'] ?? $_COOKIE['sig'] ?? ''));
$ctx = ['seed' => '', 'uid' => 0, 'sig' => $sig];
$resp = BootChainController::warehouse($ctx);

$logDir = __DIR__ . '/../../runtime/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logPath = $logDir . '/warehouse_debug.xml';
@file_put_contents($logPath, $resp->body);

debug_warehouse_send($resp);
