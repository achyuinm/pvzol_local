<?php
declare(strict_types=1);

// Debug helper: decode a captured api.duty.getAll AMF response into a readable summary.
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/dump_duty_getAll.php
//   C:\php\php.exe server/game_root/server/app/bin/dump_duty_getAll.php path\to\rsp.amf

require_once __DIR__ . '/../amf/AmfGateway.php';

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$default = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure' . DIRECTORY_SEPARATOR . '0065_api.duty.getAll.rsp.amf';
$path = $argv[1] ?? $default;
$pathReal = realpath($path);
if ($pathReal === false) {
    fwrite(STDERR, "AMF file not found: {$path}\n");
    exit(1);
}

$raw = file_get_contents($pathReal);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "Failed to read AMF file: {$pathReal}\n");
    exit(1);
}

$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$decoded = Amf0::readValueDecode($r);

if (!is_array($decoded)) {
    fwrite(STDOUT, "Decoded root is not an object.\n");
    var_export($decoded);
    fwrite(STDOUT, "\n");
    exit(0);
}

// Summary: top-level keys + counts.
$topKeys = array_keys($decoded);
sort($topKeys);
fwrite(STDOUT, "Top-level keys (" . count($topKeys) . "): " . implode(', ', $topKeys) . "\n");

foreach (['mainTask', 'dailyTask', 'sideTask', 'activeTask'] as $k) {
    $v = $decoded[$k] ?? null;
    $n = is_array($v) ? count($v) : 0;
    fwrite(STDOUT, "{$k}: {$n}\n");

    if (!is_array($v) || $n === 0) {
        continue;
    }

    $first = $v[0] ?? null;
    if (!is_array($first)) {
        continue;
    }

    $keys = array_keys($first);
    sort($keys);
    fwrite(STDOUT, "  sample[0] keys (" . count($keys) . "): " . implode(', ', $keys) . "\n");

    // Print a compact view of common fields to understand types/shape.
    $pick = ['id', 'title', 'state', 'curCount', 'maxCount', 'reward', 'gotoId', 'icon', 'dis'];
    $out = [];
    foreach ($pick as $pk) {
        if (array_key_exists($pk, $first)) {
            $out[$pk] = $first[$pk];
        }
    }
    fwrite(STDOUT, "  sample[0] values:\n");
    fwrite(STDOUT, "    " . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

// Optional: write full decoded JSON next to the AMF file (same directory).
// This is intentionally not enabled by default to avoid huge diffs / accidental commits.
exit(0);
