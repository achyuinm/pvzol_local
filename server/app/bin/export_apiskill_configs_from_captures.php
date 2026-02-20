<?php
declare(strict_types=1);

/**
 * Export editable JSON configs for skill tables by decoding captured AMF responses.
 *
 * This is preferred over file/real_amf/json_bodies because json_bodies may be truncated,
 * while the raw AMF captures contain the full tables.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/export_apiskill_configs_from_captures.php
 */

require_once __DIR__ . '/../amf/AmfGateway.php';

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

/**
 * @return mixed
 */
function decodeFirstBody(string $amfPath)
{
    if (!is_file($amfPath)) {
        fail("Not found: {$amfPath}");
    }
    $raw = file_get_contents($amfPath);
    if (!is_string($raw) || $raw === '') {
        fail("Failed to read: {$amfPath}");
    }
    $bodyRaw = AmfGateway::extractFirstMessageBodyRaw($raw);
    $r = new AmfByteReader($bodyRaw);
    return Amf0::readValueDecode($r);
}

$root = realpath(__DIR__ . '/../../../../../') ?: (__DIR__ . '/../../../../../');
$outDir = $root . '/server/game_root/runtime/config/amf';
if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        fail("Failed to create dir: {$outDir}");
    }
}

$jobs = [
    [
        'cap' => $root . '/file/real_amf/pure/api.apiskill.getAllSkills.rsp.latest.amf',
        'out' => $outDir . '/api.apiskill.getAllSkills.json',
    ],
    [
        'cap' => $root . '/file/real_amf/pure/api.apiskill.getSpecSkillAll.rsp.latest.amf',
        'out' => $outDir . '/api.apiskill.getSpecSkillAll.json',
    ],
];

foreach ($jobs as $j) {
    $val = decodeFirstBody($j['cap']);
    $json = json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        fail("JSON encode failed for: {$j['out']}");
    }
    if (file_put_contents($j['out'], $json . PHP_EOL) === false) {
        fail("Failed to write: {$j['out']}");
    }
    fwrite(STDOUT, "Wrote: {$j['out']}\n");
}

