<?php
/**
 * Generate a deterministic runtime/config/amf/api.stone.getCaveInfo.json big table.
 *
 * This drives the "宝石副本 / 矿坑夺宝" chapter UI (StonePanel).
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/generate_stone_caveinfo_bigtable.php --write
 */

declare(strict_types=1);

function arg_has(string $name): bool
{
    global $argv;
    return in_array($name, $argv, true);
}

function game_root_dir(): string
{
    // server/game_root/server/app/bin -> server/game_root
    $root = realpath(__DIR__ . '/../../..');
    return $root !== false ? $root : dirname(__DIR__, 3);
}

function make_monster(int $id): array
{
    // Fields used by StoneGateData.get*Zombies() when entering a battle ready window.
    return [
        'id' => $id,
        'pi' => 1,
        'ak' => 1,
        'gd' => 1,
        'mi' => 0,
        'hp' => 1,
        'ps' => 0,
        'boss' => 0,
        'sp' => 1,
        'new_miss' => 0,
        'new_precision' => 0,
        'size' => 1,
        'quality_name' => 'Normal',
        'talent' => [],
        'skill' => [],
    ];
}

function make_cave(int $chapId, int $index): array
{
    $caveId = $chapId * 100 + $index; // e.g. 101..112
    $imgId = (($index - 1) % 9) + 1;  // 1..9 loops

    return [
        'id' => $caveId,
        'name' => '洞口 ' . $chapId . '-' . $index,
        // 1=open, 3=closed (StoneCData)
        'actived' => 1,
        'star' => 1,
        'boss' => 0,
        'open_cave_grid' => 12,
        'pre_star' => 0,
        // 0:none, 1=already rewarded(show static), 3=can claim(plays effect)
        'through_reward' => 1,
        'img_id' => $imgId,
        'reward' => [
            'must' => [
                ['id' => 1, 'num' => 1],
            ],
            'poss' => [
                ['id' => 2, 'num' => 1],
            ],
            'through' => [
                ['id' => 3, 'num' => 1],
            ],
        ],
        'monsters' => [
            'star_1' => [make_monster(900000 + $caveId)],
            'star_2' => [make_monster(910000 + $caveId)],
            'star_3' => [make_monster(920000 + $caveId)],
        ],
    ];
}

$chapCount = 9;
$cavesPerChap = 12; // 2 rows (6 columns each) to fill StonePanel layout.

$out = [];
for ($chapId = 1; $chapId <= $chapCount; $chapId++) {
    $caves = [];
    for ($i = 1; $i <= $cavesPerChap; $i++) {
        $caves[] = make_cave($chapId, $i);
    }
    $out[(string)$chapId] = [
        'caves' => $caves,
        'cha_count' => 10,
        'has_star' => 0,
        'tol_star' => 30,
    ];
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}
$json .= "\n";

$path = game_root_dir() . '/runtime/config/amf/api.stone.getCaveInfo.json';

if (arg_has('--write')) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    // Write UTF-8 without BOM.
    file_put_contents($path, $json);
    echo "WROTE: {$path}\n";
} else {
    echo $json;
}

