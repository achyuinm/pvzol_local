<?php
declare(strict_types=1);

final class LootRoller
{
    /**
     * @return array{rewards:array<int,array{itemId:int,count:int,bind:int}>,goldDelta:int,diamondDelta:int,expDelta:int}
     */
    public static function roll(int $boxId, int $openCount, int $seed = 0): array
    {
        $openCount = max(1, $openCount);
        $cfg = self::loadConfig();
        $box = $cfg[(string)$boxId] ?? null;
        if (!is_array($box)) {
            $total = 0;
            for ($i = 0; $i < $openCount; $i++) {
                $total += random_int(20, 100);
            }
            return [
                'rewards' => [['itemId' => 850, 'count' => $total, 'bind' => 1]],
                'goldDelta' => 0,
                'diamondDelta' => 0,
                'expDelta' => 0,
            ];
        }

        $pool = isset($box['pool']) && is_array($box['pool']) ? $box['pool'] : [];
        $rewardMap = [];
        $goldDelta = 0;
        $diamondDelta = 0;
        $expDelta = 0;

        $grantAll = !empty($box['grant_all']);
        for ($i = 0; $i < $openCount; $i++) {
            if ($grantAll) {
                foreach ($pool as $idx => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $itemId = (int)($entry['itemId'] ?? 0);
                    $count = self::rollCount($entry['count'] ?? 1, $seed + $i + 17 + $idx);
                    $bind = (int)($entry['bind'] ?? 1) > 0 ? 1 : 0;
                    if ($itemId <= 0 || $count <= 0) {
                        continue;
                    }
                    $key = (string)$itemId . ':' . $bind;
                    if (!isset($rewardMap[$key])) {
                        $rewardMap[$key] = ['itemId' => $itemId, 'count' => 0, 'bind' => $bind];
                    }
                    $rewardMap[$key]['count'] += $count;
                }
            } else {
                $pick = self::pickByWeight($pool, $seed + $i);
                if (is_array($pick)) {
                    $itemId = (int)($pick['itemId'] ?? 0);
                    $count = self::rollCount($pick['count'] ?? 1, $seed + $i + 17);
                    $bind = (int)($pick['bind'] ?? 1) > 0 ? 1 : 0;
                    if ($itemId > 0 && $count > 0) {
                        $key = (string)$itemId . ':' . $bind;
                        if (!isset($rewardMap[$key])) {
                            $rewardMap[$key] = ['itemId' => $itemId, 'count' => 0, 'bind' => $bind];
                        }
                        $rewardMap[$key]['count'] += $count;
                    }
                }
            }
            $goldDelta += self::rollCount($box['gold'] ?? [0, 0], $seed + $i + 101);
            $diamondDelta += self::rollCount($box['diamond'] ?? [0, 0], $seed + $i + 202);
            $expDelta += self::rollCount($box['exp'] ?? [0, 0], $seed + $i + 303);
        }

        return [
            'rewards' => array_values($rewardMap),
            'goldDelta' => $goldDelta,
            'diamondDelta' => $diamondDelta,
            'expDelta' => $expDelta,
        ];
    }

    private static function loadConfig(): array
    {
        $path = realpath(__DIR__ . '/../../../runtime/config/loot/boxes.json')
            ?: (__DIR__ . '/../../../runtime/config/loot/boxes.json');
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function pickByWeight(array $pool, int $seed): ?array
    {
        $total = 0;
        foreach ($pool as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $w = max(0, (int)($entry['weight'] ?? 0));
            $total += $w;
        }
        if ($total <= 0) {
            return null;
        }
        mt_srand($seed ^ (int)(microtime(true) * 1000000));
        $r = mt_rand(1, $total);
        $acc = 0;
        foreach ($pool as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $w = max(0, (int)($entry['weight'] ?? 0));
            if ($w <= 0) {
                continue;
            }
            $acc += $w;
            if ($r <= $acc) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param mixed $count
     */
    private static function rollCount($count, int $seed): int
    {
        if (is_int($count)) {
            return max(0, $count);
        }
        if (is_string($count) && preg_match('/^-?\d+$/', $count)) {
            return max(0, (int)$count);
        }
        if (is_array($count) && count($count) >= 2) {
            $a = (int)($count[0] ?? 0);
            $b = (int)($count[1] ?? 0);
            $min = min($a, $b);
            $max = max($a, $b);
            if ($max <= $min) {
                return max(0, $min);
            }
            mt_srand($seed ^ (int)(microtime(true) * 1000000));
            return max(0, mt_rand($min, $max));
        }
        return 0;
    }
}
