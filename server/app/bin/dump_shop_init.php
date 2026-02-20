<?php
declare(strict_types=1);

// Debug helper: decode captured api.shop.init response into a readable summary.
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/dump_shop_init.php
//   C:\php\php.exe server/game_root/server/app/bin/dump_shop_init.php path\\to\\rsp.amf

require_once __DIR__ . '/../amf/AmfGateway.php';

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$defaultRsp = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure' . DIRECTORY_SEPARATOR . '0012_api.shop.init.rsp.amf';
$rspPath = $argv[1] ?? $defaultRsp;
$rspReal = realpath($rspPath);
if ($rspReal === false) {
    fwrite(STDERR, "RSP not found: {$rspPath}\n");
    exit(1);
}

$rawRsp = file_get_contents($rspReal);
if (!is_string($rawRsp) || $rawRsp === '') {
    fwrite(STDERR, "Failed to read: {$rspReal}\n");
    exit(1);
}

$body = AmfGateway::extractFirstMessageBodyRaw($rawRsp);
$r = new AmfByteReader($body);
$decoded = Amf0::readValueDecode($r);

if (!is_array($decoded)) {
    fwrite(STDOUT, "Decoded type=" . gettype($decoded) . "\n");
    exit(0);
}

$keys = array_keys($decoded);
sort($keys);
fwrite(STDOUT, "keys (" . count($keys) . "): " . implode(', ', $keys) . "\n");

foreach (['money', 'time', 'goods', 'type_all'] as $k) {
    if (!array_key_exists($k, $decoded)) continue;
    $v = $decoded[$k];
    $desc = gettype($v);
    if (is_array($v)) $desc .= "(n=" . count($v) . ")";
    fwrite(STDOUT, "{$k}: {$desc}\n");
}

if (isset($decoded['goods']) && is_array($decoded['goods']) && count($decoded['goods']) > 0) {
    $first = $decoded['goods'][0];
    if (is_array($first)) {
        $gk = array_keys($first);
        sort($gk);
        fwrite(STDOUT, "goods[0] keys (" . count($gk) . "): " . implode(', ', $gk) . "\n");
        fwrite(STDOUT, "goods[0] json: " . json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}

exit(0);
