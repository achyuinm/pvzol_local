<?php
declare(strict_types=1);

require_once __DIR__ . '/../DB.php';

/**
 * Persist per-user runtime state into player_state.
 */
final class StateStore
{
    /**
     * @return array{phase:string,state:array}
     */
    public function load(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'phase' => 'GUEST',
                'state' => [],
            ];
        }

        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT phase, state_json FROM player_state WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'phase' => 'INIT',
                'state' => [],
            ];
        }

        $phase = isset($row['phase']) && is_string($row['phase']) && $row['phase'] !== '' ? $row['phase'] : 'INIT';
        $state = [];
        if (isset($row['state_json']) && is_string($row['state_json']) && $row['state_json'] !== '') {
            $decoded = json_decode($row['state_json'], true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        return [
            'phase' => $phase,
            'state' => $state,
        ];
    }

    public function save(int $userId, string $phase, array $state): void
    {
        if ($userId <= 0) {
            // guest mode: no DB persistence.
            return;
        }

        $pdo = DB::pdo();
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode player_state JSON.');
        }

        $sql = <<<SQL
INSERT INTO player_state (user_id, phase, state_json)
VALUES (:uid, :phase, :state_json)
ON DUPLICATE KEY UPDATE
  phase = VALUES(phase),
  state_json = VALUES(state_json),
  updated_at = CURRENT_TIMESTAMP
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':phase' => $phase,
            ':state_json' => $json,
        ]);
    }

    // Backward-compatible alias for previous call sites.
    public function persist(int $userId, string $phase, array $state): void
    {
        $this->save($userId, $phase, $state);
    }
}
