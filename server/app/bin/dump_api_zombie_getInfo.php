<?php
declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

/**
 * Dump decoded response body for api.zombie.getInfo.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/dump_api_zombie_getInfo.php
 *   C:\php\php.exe server/game_root/server/app/bin/dump_api_zombie_getInfo.php --write
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

$write = in_array('--write', $argv, true);

$root = realpath(__DIR__ . '/../../../../../') ?: (__DIR__ . '/../../../../../');
$cap = $root . '/file/real_amf/pure/api.zombie.getInfo.rsp.latest.amf';
if (!is_file($cap)) {
    $cap = $root . '/file/real_amf/pure/0029_api.zombie.getInfo.rsp.amf';
}
if (!is_file($cap)) {
    fail('Missing capture for api.zombie.getInfo');
}

$raw = file_get_contents($cap);
if (!is_string($raw) || $raw === '') {
    fail('Failed to read capture: ' . $cap);
}

$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$val = Amf0::readValueDecode($r);

$json = json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fail('Failed to JSON-encode decoded AMF');
}

echo "capture={$cap}\n";
echo $json . "\n";

if ($write) {
    $out = $root . '/server/game_root/runtime/config/amf/api.zombie.getInfo.json';
    $dir = dirname($out);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail('Failed to create dir: ' . $dir);
    }
    file_put_contents($out, $json . "\n");
    echo "wrote={$out}\n";
}

