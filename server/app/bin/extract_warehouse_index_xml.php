<?php
declare(strict_types=1);

/**
 * Extract the real Warehouse/index XML response from a Charles JSON session export (.chlsj)
 * and write it into runtime/config/http/warehouse.index.xml for local override.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/extract_warehouse_index_xml.php
 *   C:\php\php.exe server/game_root/server/app/bin/extract_warehouse_index_xml.php D:\path\to\json会话.chlsj
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

// Default points to workspace-root file/real_login (outside game_root).
$defaultSrc = __DIR__ . '/../../../../../file/real_login/json会话.chlsj';
$src = $argv[1] ?? $defaultSrc;
$src = is_string($src) && $src !== '' ? $src : $defaultSrc;

if (!is_file($src)) {
    fail("Source file not found: {$src}");
}

$raw = file_get_contents($src);
if (!is_string($raw) || $raw === '') {
    fail("Failed to read: {$src}");
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fail("JSON decode failed for: {$src}");
}

$candidates = [];
foreach ($data as $i => $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $path = $entry['path'] ?? null;
    if (!is_string($path)) {
        continue;
    }
    if (stripos($path, '/pvz/index.php/Warehouse/index') === false) {
        continue;
    }
    $body = $entry['response']['body']['text'] ?? null;
    if (!is_string($body) || trim($body) === '') {
        continue;
    }
    $candidates[] = [
        'idx' => $i,
        'path' => $path,
        'body' => $body,
    ];
}

if ($candidates === []) {
    fail("No Warehouse/index response body found in: {$src}");
}

// Prefer the last one (most recent in session export).
$picked = $candidates[count($candidates) - 1];
$xmlRaw = $picked['body'];

// Best-effort pretty print for readability. If parsing fails, keep original.
$xmlOut = $xmlRaw;
if (ltrim($xmlRaw) !== '') {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xmlRaw, LIBXML_NONET);
    if ($ok) {
        $pretty = $dom->saveXML();
        if (is_string($pretty) && $pretty !== '') {
            $xmlOut = $pretty;
        }
    }
    libxml_clear_errors();
}

$out = __DIR__ . '/../../../runtime/config/http/warehouse.index.xml';
$outDir = dirname($out);
if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        fail("Failed to create dir: {$outDir}");
    }
}

if (file_put_contents($out, $xmlOut) === false) {
    fail("Failed to write: {$out}");
}

fwrite(STDOUT, "OK\n");
fwrite(STDOUT, "Picked entry idx={$picked['idx']} path={$picked['path']}\n");
fwrite(STDOUT, "Wrote: {$out}\n");
