<?php
declare(strict_types=1);

// Merge runtime/config/amf/api.shop.getMerchandises/<typeId>.json into a single file:
//   runtime/config/amf/api.shop.getMerchandises.json
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/merge_shop_getMerchandises_json.php
//   C:\php\php.exe server/game_root/server/app/bin/merge_shop_getMerchandises_json.php --force

$force = in_array('--force', $argv, true);

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$srcDir = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'game_root'
    . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'amf' . DIRECTORY_SEPARATOR . 'api.shop.getMerchandises';

$outFile = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'game_root'
    . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'amf' . DIRECTORY_SEPARATOR . 'api.shop.getMerchandises.json';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Source dir not found: {$srcDir}\n");
    exit(1);
}

if (is_file($outFile) && !$force) {
    fwrite(STDERR, "Refusing to overwrite existing file (use --force): {$outFile}\n");
    exit(1);
}

$merged = [];

foreach (glob($srcDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
    $base = basename($path);
    if (!preg_match('/^(\\d+)\\.json$/', $base, $m)) {
        continue;
    }
    $typeId = (string)$m[1];
    $json = file_get_contents($path);
    if (!is_string($json) || $json === '') {
        continue;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        continue;
    }
    $merged[$typeId] = $data;
}

if (!$merged) {
    fwrite(STDERR, "No per-type JSON found in: {$srcDir}\n");
    exit(1);
}

ksort($merged, SORT_NATURAL);

$outJson = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($outJson) || $outJson === '') {
    fwrite(STDERR, "Failed to encode merged JSON.\n");
    exit(1);
}
$outJson .= "\n";

if (file_put_contents($outFile, $outJson) === false) {
    fwrite(STDERR, "Failed to write: {$outFile}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote: {$outFile}\n");
exit(0);

