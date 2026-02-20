<?php
declare(strict_types=1);

/**
 * Generate runtime/config/items_effect.json from tool.xml by type_name.
 *
 * Rules:
 * - type_name = 宝箱      => open_box
 * - type_name = 挑战书    => times_delta
 * - contains VIP + N天    => vip_days
 *
 * Existing entries with type != none are preserved.
 */

$root = realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..');
$toolXml = $root . '/server/public/php_xml/tool.xml';
$effectJson = $root . '/runtime/config/items_effect.json';

if (!is_file($toolXml)) {
    fwrite(STDERR, "tool.xml not found: {$toolXml}\n");
    exit(2);
}

$existing = [];
if (is_file($effectJson)) {
    $raw = file_get_contents($effectJson);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $existing = $decoded;
    }
}

$dom = new DOMDocument();
if (!@$dom->load($toolXml)) {
    fwrite(STDERR, "failed to parse xml: {$toolXml}\n");
    exit(2);
}

$items = $dom->getElementsByTagName('item');
$generated = 0;
$preserved = 0;

foreach ($items as $item) {
    if (!$item instanceof DOMElement) {
        continue;
    }
    $id = trim($item->getAttribute('id'));
    if ($id === '' || !preg_match('/^\d+$/', $id)) {
        continue;
    }

    if (isset($existing[$id]) && is_array($existing[$id])) {
        $t = (string)($existing[$id]['type'] ?? '');
        if ($t !== '' && $t !== 'none') {
            $preserved++;
            continue;
        }
    }

    $name = trim($item->getAttribute('name'));
    $typeName = trim($item->getAttribute('type_name'));
    $useCond = trim($item->getAttribute('use_condition'));
    $useResult = trim($item->getAttribute('use_result'));
    $describe = trim($item->getAttribute('describe'));
    $lotteryName = trim($item->getAttribute('lottery_name'));
    $blob = $name . ' ' . $useCond . ' ' . $useResult . ' ' . $describe . ' ' . $lotteryName;

    $cfg = null;

    if (preg_match('/vip/i', $blob) && preg_match('/(\d+)\s*天/u', $blob, $mDay)) {
        $cfg = ['type' => 'vip_days', 'days' => max(1, (int)$mDay[1])];
    } elseif ($typeName === '宝箱') {
        $boxId = (int)$id;
        if (preg_match('/box(\d+)/i', $lotteryName, $mBox)) {
            $boxId = max(1, (int)$mBox[1]);
        }
        $cfg = ['type' => 'open_box', 'boxId' => $boxId, 'count' => 1];
    } elseif ($typeName === '挑战书') {
        $delta = 1;
        if (preg_match('/增加\s*(\d+)\s*次/u', $blob, $mCnt)) {
            $delta = max(1, (int)$mCnt[1]);
        } elseif (preg_match('/(\d+)\s*次/u', $blob, $mCnt2)) {
            $delta = max(1, (int)$mCnt2[1]);
        }

        $key = 'tool_times';
        if (preg_match('/狩猎场/u', $blob)) {
            $key = 'hunt_times';
        } elseif (preg_match('/斗技场/u', $blob)) {
            $key = 'arena_times';
        } elseif (preg_match('/副本|矿坑|洞/u', $blob)) {
            $key = 'fuben_times';
        }
        $cfg = ['type' => 'times_delta', 'key' => $key, 'delta' => $delta];
    } else {
        $cfg = ['type' => 'none'];
    }

    $existing[$id] = $cfg;
    $generated++;
}

ksort($existing, SORT_NUMERIC);
$json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "failed to encode json\n");
    exit(1);
}
file_put_contents($effectJson, $json . PHP_EOL);

echo "generated={$generated} preserved={$preserved} total=" . count($existing) . PHP_EOL;
echo "output={$effectJson}\n";
