<?php
declare(strict_types=1);

require_once __DIR__ . '/../DB.php';

final class EventApplier
{
    /**
     * @param array<int,array<string,mixed>> $events
     */
    public static function apply(int $userId, array $events): void
    {
        if ($userId <= 0 || $events === []) {
            return;
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($events as $event) {
                $type = (string)($event['type'] ?? '');
                if ($type !== 'InventoryAdded') {
                    continue;
                }
                $itemId = (string)($event['item_id'] ?? '');
                $qty = (int)($event['qty'] ?? 0);
                if ($itemId === '' || $qty <= 0) {
                    continue;
                }

                $sql = <<<SQL
INSERT INTO inventory (user_id, item_id, qty)
VALUES (:uid, :item_id, :qty)
ON DUPLICATE KEY UPDATE
  qty = qty + VALUES(qty),
  updated_at = CURRENT_TIMESTAMP
SQL;
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':uid' => $userId,
                    ':item_id' => $itemId,
                    ':qty' => $qty,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

