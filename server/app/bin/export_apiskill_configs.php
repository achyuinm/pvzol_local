<?php
declare(strict_types=1);

/**
 * Export editable AMF config JSON for:
 * - api.apiskill.getAllSkills
 * - api.apiskill.getSpecSkillAll
 *
 * Source: file/real_amf/json_bodies/*.json (Charles-decoded AMF bodies)
 * Output: runtime/config/amf/api.apiskill.*.json (response body only)
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/export_apiskill_configs.php
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

/**
 * @return array<mixed>
 */
function readJson(string $path): array
{
    if (!is_file($path)) {
        fail("Not found: {$path}");
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        fail("Failed to read: {$path}");
    }
    // Some Charles exports are UTF-8 with BOM, which breaks json_decode().
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail("JSON decode failed: {$path} (" . json_last_error_msg() . ")");
    }
    return $data;
}

/**
 * @param array<mixed> $entries
 * @return mixed|null
 */
function pickLatestRspBody(array $entries)
{
    $picked = null;
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        if (($e['kind'] ?? null) !== 'RSP') continue;
        if ((int)($e['status'] ?? 0) !== 200) continue;
        $bodies = $e['bodies'] ?? null;
        if (!is_array($bodies) || $bodies === []) continue;
        $first = $bodies[0] ?? null;
        if (!is_array($first) || $first === []) continue;
        // In these exports, bodies[0] is the actual response value:
        // - for apiskill config APIs it is an array of objects
        $picked = $first; // last wins
    }
    return $picked;
}

$root = realpath(__DIR__ . '/../../../../../') ?: (__DIR__ . '/../../../../../');
$srcDir = $root . '/file/real_amf/json_bodies';
$outDir = $root . '/server/game_root/runtime/config/amf';

if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        fail("Failed to create dir: {$outDir}");
    }
}

$jobs = [
    [
        'src' => $srcDir . '/api.apiskill.getAllSkills.json',
        'out' => $outDir . '/api.apiskill.getAllSkills.json',
    ],
    [
        'src' => $srcDir . '/api.apiskill.getSpecSkillAll.json',
        'out' => $outDir . '/api.apiskill.getSpecSkillAll.json',
    ],
];

foreach ($jobs as $j) {
    $entries = readJson($j['src']);
    $body = pickLatestRspBody($entries);
    if ($body === null) {
        fail("No 200-RSP body found in: {$j['src']}");
    }
    $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        fail("JSON encode failed for: {$j['out']}");
    }
    if (file_put_contents($j['out'], $json . PHP_EOL) === false) {
        fail("Failed to write: {$j['out']}");
    }
    fwrite(STDOUT, "Wrote: {$j['out']}\n");
}
