<?php
declare(strict_types=1);

// Query tool.xml by Chinese name (substring match) and print toolId + picId (img_id).
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/query_tool.php 金券
//   C:\php\php.exe server/game_root/server/app/bin/query_tool.php "时之沙"
//
// Output columns:
//   id, name, picId(img_id), type_name, rare, icon_swf

$q = trim(implode(' ', array_slice($argv, 1)));
if ($q === '') {
    fwrite(STDERR, "Usage: php query_tool.php <name-substring>\n");
    exit(2);
}

$serverDir = dirname(__DIR__, 2); // .../server/game_root/server
$toolXml = $serverDir
    . DIRECTORY_SEPARATOR . 'public'
    . DIRECTORY_SEPARATOR . 'php_xml'
    . DIRECTORY_SEPARATOR . 'tool.xml';
if (!is_file($toolXml)) {
    fwrite(STDERR, "tool.xml not found: {$toolXml}\n");
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($toolXml);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse tool.xml\n");
    exit(1);
}

$items = $xml->tools->item ?? null;
if ($items === null) {
    fwrite(STDERR, "tool.xml missing /tools/item\n");
    exit(1);
}

$matches = [];

foreach ($items as $item) {
    $id = (string)($item['id'] ?? '');
    $name = (string)($item['name'] ?? '');
    if ($id === '' || $name === '') {
        continue;
    }

    // tool names are mostly Chinese; case-folding isn't needed and mbstring may be unavailable.
    if (strpos($name, $q) === false) {
        continue;
    }

    $picId = (string)($item['img_id'] ?? '');
    $typeName = (string)($item['type_name'] ?? '');
    $rare = (string)($item['rare'] ?? '');
    $iconSwf = $picId !== '' ? ("IconRes/IconTool/{$picId}.swf") : '';

    $matches[] = [
        'id' => $id,
        'name' => $name,
        'picId' => $picId,
        'type_name' => $typeName,
        'rare' => $rare,
        'icon_swf' => $iconSwf,
    ];
}

if (!$matches) {
    fwrite(STDOUT, "No match for: {$q}\n");
    exit(0);
}

// Stable order: numeric id ascending.
usort($matches, static function (array $a, array $b): int {
    return (int)$a['id'] <=> (int)$b['id'];
});

foreach ($matches as $m) {
    fwrite(
        STDOUT,
        "{$m['id']}\t{$m['name']}\tpicId={$m['picId']}\t{$m['type_name']}\trare={$m['rare']}\t{$m['icon_swf']}\n"
    );
}

exit(0);
