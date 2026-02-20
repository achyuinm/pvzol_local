<?php
/**
 * Generate a merged api.fuben.caveInfo.json mapping every cave_id found in api.fuben.display.json
 * to a simple deterministic placeholder caveInfo.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/generate_fuben_caveinfo_bigtable.php --write
 */

declare(strict_types=1);

function strip_bom(string $s): string
{
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

function read_json(string $path): array
{
    $s = file_get_contents($path);
    if (!is_string($s) || $s === '') {
        throw new RuntimeException('Failed to read: ' . $path);
    }
    $s = strip_bom($s);
    $d = json_decode($s, true);
    if (!is_array($d)) {
        throw new RuntimeException('json_decode failed: ' . json_last_error_msg() . ' path=' . $path);
    }
    return $d;
}

function write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode failed');
    }
    file_put_contents($path, $json . "\n");
}

$write = in_array('--write', $argv, true);

// server/app/bin -> server/game_root
$gameRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
$displayPath = $gameRoot . '/runtime/config/amf/api.fuben.display.json';
$outPath = $gameRoot . '/runtime/config/amf/api.fuben.caveInfo.json';

$display = read_json($displayPath);

$caveIds = [];
foreach ($display as $scene => $cfg) {
    if (!is_array($cfg)) {
        continue;
    }
    $caves = $cfg['_caves'] ?? null;
    if (!is_array($caves)) {
        continue;
    }
    foreach ($caves as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['cave_id'] ?? '');
        if ($id === '' || !preg_match('/^\\d+$/', $id)) {
            continue;
        }
        $caveIds[(int)$id] = true;
    }
}

ksort($caveIds);
$ids = array_keys($caveIds);

// User-requested placeholder:
// - one monster: id=1, attributes all 1
// - one award: tool id=2
$placeholder = [
    '_monsters' => [
        [
            'skills' => [],
            'new_precision' => '1',
            'attack' => '1',
            'quality_id' => 1,
            'grade' => '1',
            'precision' => '1',
            'talent' => [0, 0, 0, 0],
            'hp' => '1',
            'id' => '1',
            'new_miss' => '1',
            'speed' => '1',
            'miss' => '1',
        ],
    ],
    '_award' => [
        [
            'type' => 'tool',
            'value' => 2,
        ],
    ],
];

$out = [];
foreach ($ids as $id) {
    $out[(string)$id] = $placeholder;
}

echo "caves " . count($ids) . "\n";
if ($ids) {
    echo "range " . $ids[0] . ".." . $ids[count($ids) - 1] . "\n";
}
echo "out " . $outPath . "\n";

if ($write) {
    write_json($outPath, $out);
    echo "wrote\n";
} else {
    echo "dry-run (pass --write)\n";
}

