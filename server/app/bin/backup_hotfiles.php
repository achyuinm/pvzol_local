<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/../../..');
if ($root === false) {
    fwrite(STDERR, "workspace root resolve failed\n");
    exit(1);
}

$hotFiles = [
    'server/public/amf.php',
    'server/app/dao/OrgDao.php',
    'server/app/http/BootChainController.php',
    'server/app/http/WarehouseXmlBuilder.php',
    'runtime/config/items_effect.json',
    'runtime/config/loot/boxes.json',
    'runtime/extracted/default_user_from_session.xml',
];

$backupRoot = $root . '/runtime/backup/hotfiles';
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0777, true) && !is_dir($backupRoot)) {
    fwrite(STDERR, "failed to create backup dir: {$backupRoot}\n");
    exit(1);
}

$ts = date('Ymd_His');
foreach ($hotFiles as $rel) {
    $src = $root . '/' . str_replace('\\', '/', $rel);
    if (!is_file($src)) {
        echo "skip missing: {$rel}\n";
        continue;
    }

    $dstDir = $backupRoot . '/' . dirname($rel);
    if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true) && !is_dir($dstDir)) {
        fwrite(STDERR, "failed to create dir: {$dstDir}\n");
        continue;
    }

    $base = basename($rel);
    $dstTs = $dstDir . '/' . $base . '.' . $ts . '.bak';
    $dstLatest = $dstDir . '/' . $base . '.latest.bak';

    if (!copy($src, $dstTs)) {
        fwrite(STDERR, "copy failed: {$rel} -> {$dstTs}\n");
        continue;
    }
    copy($src, $dstLatest);
    echo "backup ok: {$rel}\n";
}

exit(0);
