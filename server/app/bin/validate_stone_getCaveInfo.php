<?php
/**
 * Validate runtime/config/amf/api.stone.getCaveInfo.json against what the client code reads.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/validate_stone_getCaveInfo.php
 */

declare(strict_types=1);

function game_root_dir(): string
{
    $root = realpath(__DIR__ . '/../../..');
    return $root !== false ? $root : dirname(__DIR__, 3);
}

function fail(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function ensure_key(array $a, string $k, string $ctx): void
{
    if (!array_key_exists($k, $a)) {
        fail("Missing key {$ctx}.{$k}");
    }
}

function ensure_array(mixed $v, string $ctx): array
{
    if (!is_array($v)) {
        fail("Expected array at {$ctx}");
    }
    return $v;
}

function ensure_intlike(mixed $v, string $ctx): void
{
    if (is_int($v)) {
        return;
    }
    if (is_float($v) && is_finite($v) && floor($v) === $v) {
        return;
    }
    if (is_string($v) && preg_match('/^-?\\d+$/', $v)) {
        return;
    }
    fail("Expected int-like at {$ctx}");
}

function ensure_string(mixed $v, string $ctx): void
{
    if (!is_string($v)) {
        fail("Expected string at {$ctx}");
    }
}

$path = game_root_dir() . '/runtime/config/amf/api.stone.getCaveInfo.json';
if (!is_file($path)) {
    fail("Missing file: {$path}");
}

$json = file_get_contents($path);
if (!is_string($json) || $json === '') {
    fail("Empty file: {$path}");
}

if (str_starts_with($json, "\xEF\xBB\xBF")) {
    $json = substr($json, 3);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    fail("Invalid JSON: {$path}");
}

foreach ($data as $chapId => $chap) {
    $chapCtx = "chap[{$chapId}]";
    $chap = ensure_array($chap, $chapCtx);
    ensure_key($chap, 'caves', $chapCtx);
    ensure_key($chap, 'cha_count', $chapCtx);
    ensure_key($chap, 'has_star', $chapCtx);
    ensure_key($chap, 'tol_star', $chapCtx);
    ensure_intlike($chap['cha_count'], $chapCtx . '.cha_count');
    ensure_intlike($chap['has_star'], $chapCtx . '.has_star');
    ensure_intlike($chap['tol_star'], $chapCtx . '.tol_star');

    $caves = ensure_array($chap['caves'], $chapCtx . '.caves');
    if (!array_is_list($caves)) {
        // Client iterates with `for each`, so object is ok too, but we keep list for determinism.
        $caves = array_values($caves);
    }

    foreach ($caves as $i => $c) {
        $ctx = "{$chapCtx}.caves[{$i}]";
        $c = ensure_array($c, $ctx);
        foreach (['id','name','actived','star','boss','open_cave_grid','pre_star','through_reward','img_id','reward','monsters'] as $k) {
            ensure_key($c, $k, $ctx);
        }
        ensure_intlike($c['id'], $ctx . '.id');
        ensure_string($c['name'], $ctx . '.name');
        ensure_intlike($c['actived'], $ctx . '.actived');
        ensure_intlike($c['star'], $ctx . '.star');
        ensure_intlike($c['boss'], $ctx . '.boss');
        ensure_intlike($c['open_cave_grid'], $ctx . '.open_cave_grid');
        ensure_intlike($c['pre_star'], $ctx . '.pre_star');
        ensure_intlike($c['through_reward'], $ctx . '.through_reward');
        ensure_intlike($c['img_id'], $ctx . '.img_id');

        $reward = ensure_array($c['reward'], $ctx . '.reward');
        foreach (['must','poss','through'] as $rk) {
            ensure_key($reward, $rk, $ctx . '.reward');
            $arr = ensure_array($reward[$rk], $ctx . ".reward.{$rk}");
            if (!array_is_list($arr)) {
                $arr = array_values($arr);
            }
            foreach ($arr as $ri => $tool) {
                $tctx = $ctx . ".reward.{$rk}[{$ri}]";
                $tool = ensure_array($tool, $tctx);
                ensure_key($tool, 'id', $tctx);
                ensure_key($tool, 'num', $tctx);
                ensure_intlike($tool['id'], $tctx . '.id');
                ensure_intlike($tool['num'], $tctx . '.num');
            }
        }

        $mon = ensure_array($c['monsters'], $ctx . '.monsters');
        foreach (['star_1','star_2','star_3'] as $mk) {
            ensure_key($mon, $mk, $ctx . '.monsters');
            $arr = ensure_array($mon[$mk], $ctx . ".monsters.{$mk}");
            if (!array_is_list($arr)) {
                $arr = array_values($arr);
            }
            if (count($arr) < 1) {
                fail("Expected at least 1 monster at {$ctx}.monsters.{$mk}");
            }
            foreach ($arr as $mi => $m) {
                $mctx = $ctx . ".monsters.{$mk}[{$mi}]";
                $m = ensure_array($m, $mctx);
                foreach (['id','pi','ak','gd','mi','hp','ps','boss','sp','new_miss','new_precision','size','quality_name','talent','skill'] as $kk) {
                    ensure_key($m, $kk, $mctx);
                }
            }
        }
    }
}

echo "OK: {$path}\n";

