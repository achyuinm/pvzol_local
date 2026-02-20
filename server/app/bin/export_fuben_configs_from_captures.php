<?php
declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

/**
 * Export api.fuben.* configs from captured AMF req/rsp pairs into runtime/config/amf/.
 *
 * Exports:
 * - api.fuben.caveInfo/<caveId>.json (monster tables)
 * - api.fuben.display/<sceneId>.json (caves list)
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/export_fuben_configs_from_captures.php
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

/**
 * @return array<int, array{req:string,rsp:string}>
 */
function find_pairs(string $capDir, string $method): array
{
    $pairs = [];

    $files = scandir($capDir);
    if (!is_array($files)) {
        return [];
    }

    $byStem = [];
    foreach ($files as $f) {
        if (!is_string($f) || $f === '.' || $f === '..') {
            continue;
        }
        $path = $capDir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) {
            continue;
        }

        // Scheme A (proxy_web captures):
        //   <stem>_REQ_<method>.amf
        //   <stem>_RSP_<status>_<method>.amf
        if (preg_match('/_REQ_' . preg_quote($method, '/') . '\\.amf$/i', $f)) {
            $stem = preg_replace('/_REQ_.*$/i', '', $f);
            $byStem[$stem]['req'] = $path;
            continue;
        }
        if (preg_match('/_RSP_\\d+_' . preg_quote($method, '/') . '\\.amf$/i', $f)) {
            $stem = preg_replace('/_RSP_\\d+_.*$/i', '', $f);
            $byStem[$stem]['rsp'] = $path;
            continue;
        }

        // Scheme B (file/real_amf/pure captures):
        //   <prefix>_<method>.req.amf
        //   <prefix>_<method>.rsp.amf
        // or:
        //   <method>.req.latest.amf / <method>.rsp.latest.amf
        if (stripos($f, $method) !== false && preg_match('/\\.(req|req\\.latest)\\.amf$/i', $f)) {
            $stem = preg_replace('/\\.(req|req\\.latest)\\.amf$/i', '', $f);
            $byStem[$stem]['req'] = $path;
            continue;
        }
        if (stripos($f, $method) !== false && preg_match('/\\.(rsp|rsp\\.latest)\\.amf$/i', $f)) {
            $stem = preg_replace('/\\.(rsp|rsp\\.latest)\\.amf$/i', '', $f);
            $byStem[$stem]['rsp'] = $path;
            continue;
        }
    }

    foreach ($byStem as $stem => $v) {
        if (!isset($v['req'], $v['rsp'])) {
            continue;
        }
        $pairs[] = ['req' => $v['req'], 'rsp' => $v['rsp']];
    }
    return $pairs;
}

/**
 * Decode first AMF message body into PHP value.
 */
function decode_first_body(string $amfPath): mixed
{
    $raw = file_get_contents($amfPath);
    if (!is_string($raw) || $raw === '') {
        fail('Failed to read AMF file: ' . $amfPath);
    }
    $body = AmfGateway::extractFirstMessageBodyRaw($raw);
    $r = new AmfByteReader($body);
    return Amf0::readValueDecode($r);
}

function ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail('Failed to create dir: ' . $dir);
    }
}

function write_json(string $path, mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        fail('Failed to JSON-encode: ' . $path);
    }
    file_put_contents($path, $json . "\n");
}

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fail('Failed to resolve repo root.');
}
$capDir = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'proxy_web' . DIRECTORY_SEPARATOR . 'AMF_CAPTURE' . DIRECTORY_SEPARATOR . 'raw';
$capDir2 = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure';

$outBase = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'game_root' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'amf';

// Prefer proxy_web captures (newest), fallback to file/real_amf/pure (older).
$sources = [];
if (is_dir($capDir)) {
    $sources[] = $capDir;
}
if (is_dir($capDir2)) {
    $sources[] = $capDir2;
}
if ($sources === []) {
    fail('No capture directories found.');
}

$exported = 0;

foreach ($sources as $srcDir) {
    // api.fuben.caveInfo
    foreach (find_pairs($srcDir, 'api.fuben.caveInfo') as $p) {
        $req = decode_first_body($p['req']);
        if (!is_array($req) || !array_is_list($req) || !isset($req[0])) {
            continue;
        }
        $caveId = (int)$req[0];
        if ($caveId <= 0) {
            continue;
        }
        $rsp = decode_first_body($p['rsp']);
        $dir = $outBase . DIRECTORY_SEPARATOR . 'api.fuben.caveInfo';
        ensure_dir($dir);
        $out = $dir . DIRECTORY_SEPARATOR . $caveId . '.json';
        // Always overwrite: newest capture should win.
        write_json($out, $rsp);
        $exported++;
    }

    // api.fuben.display
    foreach (find_pairs($srcDir, 'api.fuben.display') as $p) {
        $req = decode_first_body($p['req']);
        if (!is_array($req) || !array_is_list($req) || !isset($req[0])) {
            continue;
        }
        $sceneId = (int)$req[0];
        if ($sceneId <= 0) {
            continue;
        }
        $rsp = decode_first_body($p['rsp']);
        $dir = $outBase . DIRECTORY_SEPARATOR . 'api.fuben.display';
        ensure_dir($dir);
        $out = $dir . DIRECTORY_SEPARATOR . $sceneId . '.json';
        write_json($out, $rsp);
        $exported++;
    }
}

fwrite(STDOUT, "exported={$exported}\n");
