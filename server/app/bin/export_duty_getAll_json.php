<?php
declare(strict_types=1);

// Export a captured api.duty.getAll AMF response into a configurable JSON file.
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/export_duty_getAll_json.php
//   C:\php\php.exe server/game_root/server/app/bin/export_duty_getAll_json.php path\to\rsp.amf
//   C:\php\php.exe server/game_root/server/app/bin/export_duty_getAll_json.php --force
//
// Output:
//   server/game_root/runtime/config/amf/api.duty.getAll.json

require_once __DIR__ . '/../amf/AmfGateway.php';

$force = in_array('--force', $argv, true);

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$defaultCapture = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure' . DIRECTORY_SEPARATOR . '0065_api.duty.getAll.rsp.amf';
$capturePath = $defaultCapture;
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--force') continue;
    $capturePath = $a;
    break;
}

$captureReal = realpath($capturePath);
if ($captureReal === false) {
    fwrite(STDERR, "Capture not found: {$capturePath}\n");
    exit(1);
}

$raw = file_get_contents($captureReal);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "Failed to read capture: {$captureReal}\n");
    exit(1);
}

$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$decoded = Amf0::readValueDecode($r);

if (!is_array($decoded)) {
    fwrite(STDERR, "Decoded body is not an object; refusing to export.\n");
    exit(1);
}

// Basic sanity: expect these 4 arrays.
foreach (['mainTask', 'dailyTask', 'sideTask', 'activeTask'] as $k) {
    if (!array_key_exists($k, $decoded) || !is_array($decoded[$k])) {
        fwrite(STDERR, "Decoded body missing expected array: {$k}\n");
        exit(1);
    }
}

$outDir = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'game_root' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'amf';
$outFile = $outDir . DIRECTORY_SEPARATOR . 'api.duty.getAll.json';

if (is_file($outFile) && !$force) {
    fwrite(STDERR, "Refusing to overwrite existing file (use --force): {$outFile}\n");
    exit(1);
}

if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        fwrite(STDERR, "Failed to create dir: {$outDir}\n");
        exit(1);
    }
}

$json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || $json === '') {
    fwrite(STDERR, "Failed to encode JSON.\n");
    exit(1);
}

// UTF-8 without BOM.
$json .= "\n";
if (file_put_contents($outFile, $json) === false) {
    fwrite(STDERR, "Failed to write: {$outFile}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote: {$outFile}\n");
exit(0);

