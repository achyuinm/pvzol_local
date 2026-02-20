<?php
/**
 * Mark fuben (InsideWorld) as "finished" everywhere we can via runtime configs.
 *
 * Effects:
 * - api.fuben.display.json: set every cave status=5 (Checkpoint.FINISHED)
 * - api.fuben.reward.json: set current integral/medal to max (so reward panel shows completed)
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/mark_fuben_all_finished.php --write
 */

declare(strict_types=1);

function strip_bom(string $s): string
{
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

function read_json(string $path): array
{
    $s = file_get_contents($path);
    if (!is_string($s) || $s === '') {
        throw new RuntimeException('Failed to read: ' . $path);
    }
    $s = strip_bom($s);
    $d = json_decode($s, true);
    if (!is_array($d)) {
        throw new RuntimeException('json_decode failed: ' . json_last_error_msg() . ' path=' . $path);
    }
    return $d;
}

function write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode failed');
    }
    file_put_contents($path, $json . "\n");
}

function max_reward_value(array $rules): int
{
    $max = 0;
    foreach ($rules as $r) {
        if (!is_array($r)) {
            continue;
        }
        $v = $r['current'] ?? 0;
        if (is_string($v) && preg_match('/^-?\\d+$/', $v)) {
            $v = (int)$v;
        }
        if (is_int($v) && $v > $max) {
            $max = $v;
        }
    }
    return $max;
}

$write = in_array('--write', $argv, true);

// server/app/bin -> server/game_root
$gameRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);

$displayPath = $gameRoot . '/runtime/config/amf/api.fuben.display.json';
$rewardPath = $gameRoot . '/runtime/config/amf/api.fuben.reward.json';

// 1) display: set all status=FINISHED (5)
$display = read_json($displayPath);
$count = 0;
foreach ($display as $scene => $cfg) {
    if (!is_array($cfg) || !isset($cfg['_caves']) || !is_array($cfg['_caves'])) {
        continue;
    }
    foreach ($cfg['_caves'] as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['status'] = 5;
        $cfg['_caves'][$i] = $row;
        $count++;
    }
    $display[$scene] = $cfg;
}

echo "display caves updated: $count\n";

// 2) reward: set current to max so panel is "complete"
if (is_file($rewardPath)) {
    $reward = read_json($rewardPath);
    $intMax = 0;
    $medMax = 0;
    if (isset($reward['rule']['integral']) && is_array($reward['rule']['integral'])) {
        $intMax = max_reward_value($reward['rule']['integral']);
    }
    if (isset($reward['rule']['medal']) && is_array($reward['rule']['medal'])) {
        $medMax = max_reward_value($reward['rule']['medal']);
    }

    $reward['current'] = $reward['current'] ?? [];
    $reward['current']['integral'] = (string)$intMax;
    $reward['current']['medal'] = (string)$medMax;
    $reward['integral'] = (string)$intMax;
    $reward['medal'] = $reward['medal'] ?? [];
    $reward['medal']['amount'] = (string)$medMax;
    $reward['medal']['tool_id'] = $reward['medal']['tool_id'] ?? null;

    echo "reward current set: integral=$intMax medal=$medMax\n";
} else {
    $reward = null;
    echo "reward config missing: $rewardPath\n";
}

if ($write) {
    write_json($displayPath, $display);
    echo "wrote $displayPath\n";
    if (is_array($reward)) {
        write_json($rewardPath, $reward);
        echo "wrote $rewardPath\n";
    }
} else {
    echo "dry-run (pass --write)\n";
}

