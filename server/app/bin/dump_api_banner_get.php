<?php
declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

/**
 * Dump decoded response body for api.banner.get (OtherActitvyWindow).
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/dump_api_banner_get.php
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

$root = realpath(__DIR__ . '/../../../../../') ?: (__DIR__ . '/../../../../../');
$cap = $root . '/file/real_amf/pure/0141_api.banner.get.rsp.amf';
if (!is_file($cap)) {
    $cap = $root . '/file/real_amf/pure/api.banner.get.rsp.latest.amf';
}
if (!is_file($cap)) {
    fail('Missing capture for api.banner.get');
}

$raw = file_get_contents($cap);
if (!is_string($raw) || $raw === '') {
    fail('Failed to read capture: ' . $cap);
}

$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$val = Amf0::readValueDecode($r);

echo "capture={$cap}\n";
echo json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

