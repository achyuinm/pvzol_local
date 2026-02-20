<?php
declare(strict_types=1);

/*
AMF safety guard for large-file editing.

Usage:
  C:\php\php.exe server/game_root/server/app/bin/amf_guard.php backup
  C:\php\php.exe server/game_root/server/app/bin/amf_guard.php verify
  C:\php\php.exe server/game_root/server/app/bin/amf_guard.php restore <backup_file>

What it checks:
  1) PHP syntax lint
  2) required function/handler anchors still exist
*/

$root = realpath(__DIR__ . '/../../..');
if (!is_string($root) || $root === '') {
    fwrite(STDERR, "cannot resolve project root\n");
    exit(1);
}

$amfPath = $root . '/server/public/amf.php';
$backupDir = $root . '/runtime/backup/hotfiles/manual_policy_guard';

$requiredAnchors = [
    '$orgSkillUp = static function',
    '$orgSkillLearn = static function',
    '$orgSpecSkillUp = static function',
    "'api.apiorganism.skillUp' => \$orgSkillUp",
    "'api.apiorganism.skillLearn' => \$orgSkillLearn",
    "'api.apiorganism.specSkillUp' => \$orgSpecSkillUp",
];

function fail(string $msg): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}

function ok(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function runLint(string $phpExe, string $file): bool
{
    $cmd = escapeshellarg($phpExe) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $out, $code);
    foreach ($out as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
    return $code === 0;
}

function detectPhpExe(): string
{
    $candidates = [
        'C:\\php\\php.exe',
        __DIR__ . '/../../../php.exe',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    fail('php.exe not found (checked C:\\php\\php.exe and server/php.exe)');
}

if (!is_file($amfPath)) {
    fail("amf.php not found: {$amfPath}");
}

$action = $argv[1] ?? '';
$phpExe = detectPhpExe();

if ($action === 'backup') {
    if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        fail("cannot create backup dir: {$backupDir}");
    }
    $ts = date('Ymd_His');
    $dst = $backupDir . '/amf.php.' . $ts . '.bak';
    if (!copy($amfPath, $dst)) {
        fail("backup failed: {$dst}");
    }
    ok("backup created: {$dst}");
    exit(0);
}

if ($action === 'verify') {
    $raw = file_get_contents($amfPath);
    if (!is_string($raw) || $raw === '') {
        fail('cannot read amf.php');
    }

    $missing = [];
    foreach ($requiredAnchors as $anchor) {
        if (strpos($raw, $anchor) === false) {
            $missing[] = $anchor;
        }
    }
    if ($missing !== []) {
        fail('anchor check failed, missing: ' . implode(' | ', $missing));
    }
    ok('anchor check passed');

    if (!runLint($phpExe, $amfPath)) {
        fail('lint failed');
    }
    ok('verify passed');
    exit(0);
}

if ($action === 'restore') {
    $src = $argv[2] ?? '';
    if ($src === '' || !is_file($src)) {
        fail('restore source missing or not found');
    }
    if (!copy($src, $amfPath)) {
        fail('restore copy failed');
    }
    ok("restored from: {$src}");
    if (!runLint($phpExe, $amfPath)) {
        fail('lint failed after restore');
    }
    ok('restore complete');
    exit(0);
}

fail('usage: amf_guard.php backup|verify|restore <backup_file>');

