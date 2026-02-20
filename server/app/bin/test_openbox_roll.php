<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/LootRoller.php';

function sampleBox(int $boxId, int $times): array
{
    $acc = [];
    for ($i = 0; $i < $times; $i++) {
        $r = LootRoller::roll($boxId, 1, 1000 + $i);
        foreach (($r['rewards'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int)($it['itemId'] ?? 0);
            $cnt = (int)($it['count'] ?? 0);
            if ($id <= 0 || $cnt <= 0) {
                continue;
            }
            $acc[$id] = ($acc[$id] ?? 0) + $cnt;
        }
    }
    ksort($acc);
    return $acc;
}

$a = sampleBox(1001, 20);
$b = sampleBox(1002, 20);

echo "box1001 rewards:\n";
echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "box1002 rewards:\n";
echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

