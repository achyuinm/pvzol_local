<?php
declare(strict_types=1);

// Export captured shop AMF responses into editable JSON configs.
//
// Output:
// - server/game_root/runtime/config/amf/api.shop.init.json
// - server/game_root/runtime/config/amf/api.shop.getMerchandises/<type>.json
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/export_shop_json.php
//   C:\php\php.exe server/game_root/server/app/bin/export_shop_json.php --force

require_once __DIR__ . '/../amf/AmfGateway.php';

$force = in_array('--force', $argv, true);

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$pureDir = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure';

// Pick representative captures (stable sizes).
$initRsp = $pureDir . DIRECTORY_SEPARATOR . '0012_api.shop.init.rsp.amf';
$merchRspByType = [
    // type => rsp filename
    1 => '0301_api.shop.getMerchandises.rsp.amf',
    2 => '0302_api.shop.getMerchandises.rsp.amf',
    4 => '0172_api.shop.getMerchandises.rsp.amf',
    5 => '0303_api.shop.getMerchandises.rsp.amf',
    6 => '0300_api.shop.getMerchandises.rsp.amf',
    7 => '0013_api.shop.getMerchandises.rsp.amf',
    8 => '0134_api.shop.getMerchandises.rsp.amf',
];

$outBase = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'game_root' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'amf';
$outInit = $outBase . DIRECTORY_SEPARATOR . 'api.shop.init.json';
$outMerchDir = $outBase . DIRECTORY_SEPARATOR . 'api.shop.getMerchandises';

if (!is_dir($outBase) && !mkdir($outBase, 0777, true) && !is_dir($outBase)) {
    fwrite(STDERR, "Failed to create dir: {$outBase}\n");
    exit(1);
}
if (!is_dir($outMerchDir) && !mkdir($outMerchDir, 0777, true) && !is_dir($outMerchDir)) {
    fwrite(STDERR, "Failed to create dir: {$outMerchDir}\n");
    exit(1);
}

function decode_rsp_body(string $amfPath): mixed
{
    $raw = file_get_contents($amfPath);
    if ($raw === false || $raw === '') {
        throw new RuntimeException("Failed to read: {$amfPath}");
    }
    $body = AmfGateway::extractFirstMessageBodyRaw($raw);
    $r = new AmfByteReader($body);
    return Amf0::readValueDecode($r);
}

function write_json(string $path, mixed $data, bool $force): void
{
    if (is_file($path) && !$force) {
        throw new RuntimeException("Refusing to overwrite existing file (use --force): {$path}");
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException("Failed to json_encode for: {$path}");
    }
    $json .= "\n";
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Failed to write: {$path}");
    }
}

// api.shop.init
if (!is_file($initRsp)) {
    fwrite(STDERR, "Missing capture: {$initRsp}\n");
    exit(1);
}
$initDecoded = decode_rsp_body($initRsp);
write_json($outInit, $initDecoded, $force);
fwrite(STDOUT, "Wrote: {$outInit}\n");

// Convenience: api.shop.getMerchandises with type=3 (rmb) wasn't captured in our session.
// In the client, init.goods is used as the initial RMB list, and getMerchandises later expects a list.
if (is_array($initDecoded) && isset($initDecoded['goods']) && is_array($initDecoded['goods'])) {
    $out3 = $outMerchDir . DIRECTORY_SEPARATOR . '3.json';
    write_json($out3, $initDecoded['goods'], $force);
    fwrite(STDOUT, "Wrote: {$out3}\n");
}

// api.shop.getMerchandises variants
foreach ($merchRspByType as $type => $file) {
    $p = $pureDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($p)) {
        fwrite(STDERR, "Missing capture: {$p}\n");
        continue;
    }
    $decoded = decode_rsp_body($p);
    $out = $outMerchDir . DIRECTORY_SEPARATOR . $type . '.json';
    write_json($out, $decoded, $force);
    fwrite(STDOUT, "Wrote: {$out}\n");
}

exit(0);
