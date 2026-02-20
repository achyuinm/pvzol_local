<?php
declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

/**
 * Dump the decoded first AMF message body as JSON.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/dump_amf_first_body.php path\to\file.amf
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

$path = $argv[1] ?? '';
if ($path === '') {
    fail('Missing AMF file path');
}

$real = realpath($path);
if ($real === false || !is_file($real)) {
    fail('AMF file not found: ' . $path);
}

$raw = file_get_contents($real);
if (!is_string($raw) || $raw === '') {
    fail('Failed to read AMF file (empty): ' . $real);
}

$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$val = Amf0::readValueDecode($r);

$json = json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fail('Failed to JSON-encode decoded AMF');
}

echo $json . PHP_EOL;

