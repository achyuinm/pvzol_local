<?php
/**
 * Dump the first AMF message body value (decoded) as JSON.
 *
 * Supports both request and response AMF files captured by proxy.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/dump_amf_value.php <path-to-amf>
 */

declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: dump_amf_value.php <path-to-amf>\n");
    exit(2);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "Not a file: {$path}\n");
    exit(2);
}

$raw = file_get_contents($path);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "Empty: {$path}\n");
    exit(2);
}

try {
    $body = AmfGateway::extractFirstMessageBodyRaw($raw);
    $r = new AmfByteReader($body);
    $value = Amf0::readValueDecode($r);
} catch (Throwable $e) {
    fwrite(STDERR, "Decode failed: " . $e->getMessage() . "\n");
    exit(1);
}

$json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

echo $json . "\n";

