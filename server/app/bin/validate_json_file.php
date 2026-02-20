<?php
declare(strict_types=1);

/**
 * Validate JSON file with json_decode and report error.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/validate_json_file.php path/to/file.json
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: validate_json_file.php <path>\n");
    exit(2);
}

$path = (string)$argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "Not found: {$path}\n");
    exit(2);
}

$raw = file_get_contents($path);
if (!is_string($raw)) {
    fwrite(STDERR, "Read failed: {$path}\n");
    exit(2);
}

if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
    $raw = substr($raw, 3);
}

$v = json_decode($raw, true);
if ($v === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "INVALID: {$path}\n");
    fwrite(STDERR, json_last_error_msg() . "\n");
    exit(1);
}

fwrite(STDOUT, "OK: {$path}\n");

