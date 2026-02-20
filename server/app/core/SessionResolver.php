<?php
declare(strict_types=1);

require_once __DIR__ . '/../DB.php';
require_once __DIR__ . '/AuthToken.php';

final class SessionResolver
{
    private const USER_ID_START = 100001;
    private const NAME_NUM_MIN = 11;
    private const NAME_NUM_MAX = 99;

    /**
     * Resolve session preferring cookie pvzol token, then optional legacy sig.
     * @return array{sig:string,user_id:int,xml_path:string,source:string}
     */
    public static function resolveFromRequest(?string $fallbackSig = null): array
    {
        $runtimeExtracted = realpath(__DIR__ . '/../../../runtime/extracted')
            ?: (__DIR__ . '/../../../runtime/extracted');
        $defaultPath = $runtimeExtracted . DIRECTORY_SEPARATOR . 'default_user_from_session.xml';

        $token = trim((string)($_COOKIE['pvzol'] ?? ''));
        if ($token !== '') {
            $v = AuthToken::verify($token);
            if (($v['valid'] ?? false) === true) {
                $uid = (int)($v['user_id'] ?? 0);
                return [
                    'sig' => '',
                    'user_id' => $uid,
                    'xml_path' => self::userXmlPathByUserId($uid),
                    'source' => 'pvzol',
                ];
            }
        }

        $sig = trim((string)($fallbackSig ?? ''));
        if ($sig === '') {
            $sig = trim((string)($_COOKIE['sig'] ?? ''));
        }
        if ($sig !== '' && preg_match('/^[a-f0-9]{32}$/i', $sig)) {
            $legacy = self::resolveFromSig(strtolower($sig));
            return $legacy + ['source' => 'sig'];
        }

        return [
            'sig' => '',
            'user_id' => 0,
            'xml_path' => $defaultPath,
            'source' => 'guest',
        ];
    }

    /**
     * @return array{sig:string,user_id:int,xml_path:string}
     */
    public static function resolveFromSig(string $sig): array
    {
        $runtimeExtracted = realpath(__DIR__ . '/../../../runtime/extracted')
            ?: (__DIR__ . '/../../../runtime/extracted');
        $defaultPath = $runtimeExtracted . DIRECTORY_SEPARATOR . 'default_user_from_session.xml';
        if ($sig === '' || !preg_match('/^[a-f0-9]{32}$/i', $sig)) {
            return ['sig' => '', 'user_id' => 0, 'xml_path' => $defaultPath];
        }
        $sig = strtolower($sig);
        $pdo = DB::pdo();
        self::ensureSigUserTable($pdo);
        $stmt = $pdo->prepare('SELECT user_id FROM sig_user WHERE sig = :sig LIMIT 1');
        $stmt->execute([':sig' => $sig]);
        $row = $stmt->fetch();
        if (is_array($row) && isset($row['user_id'])) {
            $uid = (int)$row['user_id'];
            $touch = $pdo->prepare('UPDATE sig_user SET last_seen_at = NOW() WHERE sig = :sig');
            $touch->execute([':sig' => $sig]);
            return ['sig' => $sig, 'user_id' => $uid, 'xml_path' => self::userXmlPathByUserId($uid)];
        }
        $uid = self::createUserAccount();
        $ins = $pdo->prepare('INSERT INTO sig_user (sig,user_id,created_at,last_seen_at) VALUES (:sig,:uid,NOW(),NOW())');
        $ins->execute([':sig' => $sig, ':uid' => $uid]);
        return ['sig' => $sig, 'user_id' => $uid, 'xml_path' => self::userXmlPathByUserId($uid)];
    }

    public static function createUserAccount(): int
    {
        $pdo = DB::pdo();
        // DDL (CREATE/ALTER) can trigger implicit commit in MySQL, so keep it out of DML transaction.
        self::ensureOrganismsTable($pdo);
        self::ensurePlayerAutoIncrementFloor($pdo, self::USER_ID_START);
        $pdo->beginTransaction();
        try {
            $insPlayer = $pdo->prepare('INSERT INTO players (seed, nickname) VALUES (:seed, :nick)');
            $seed = 'u:' . bin2hex(random_bytes(8));
            $insPlayer->execute([':seed' => $seed, ':nick' => 'U_new']);
            $uid = (int)$pdo->lastInsertId();
            if ($uid <= 0) {
                throw new RuntimeException('create user id failed');
            }
            $upNick = $pdo->prepare('UPDATE players SET nickname=:n WHERE id=:id');
            $upNick->execute([':n' => self::localDisplayName($uid), ':id' => $uid]);

            $insState = $pdo->prepare(
                'INSERT INTO player_state (user_id, phase, state_json) VALUES (:uid,:phase,:state)
                 ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
            );
            $initState = [
                'vip' => [
                    'grade' => 0,
                    'etime' => 0,
                    'exp' => 0,
                    'restore_hp' => 0,
                ],
                'counters' => [
                    'stone_cha_count' => 0,
                ],
            ];
            $insState->execute([
                ':uid' => $uid,
                ':phase' => 'INIT',
                ':state' => json_encode($initState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $insInv = $pdo->prepare(
                'INSERT INTO inventory (user_id,item_id,qty) VALUES (:uid,:iid,:qty)
                 ON DUPLICATE KEY UPDATE qty=qty'
            );
            $insInv->execute([':uid' => $uid, ':iid' => 'gold', ':qty' => 10000000]);
            $insInv->execute([':uid' => $uid, ':iid' => 'money', ':qty' => 100000]);
            // New-account starter inventory (non-empty warehouse to avoid UI edge cases).
            $insInv->execute([':uid' => $uid, ':iid' => 'tool:1', ':qty' => 99]);
            $insInv->execute([':uid' => $uid, ':iid' => 'tool:861', ':qty' => 500]);
            $insInv->execute([':uid' => $uid, ':iid' => 'tool:1003', ':qty' => 5]);
            $insInv->execute([':uid' => $uid, ':iid' => 'tool:481', ':qty' => 99999]);

            // New account default plant: keep exactly one level-1 organism (tpl_id=151).
            $pdo->prepare('DELETE FROM organisms WHERE user_id = :uid')->execute([':uid' => $uid]);
            $insOrg = $pdo->prepare(
                'INSERT INTO organisms (user_id, tpl_id, level, quality, exp, hp, skills_json)
                 VALUES (:uid, :tpl, 1, 1, 0, 100, :skills)'
            );
            $insOrg->execute([
                ':uid' => $uid,
                ':tpl' => 151,
                ':skills' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $uid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function localDisplayName(int $userId): string
    {
        // Keep existing user 100006 untouched by naming scheme changes.
        if ($userId === 100006) {
            return 'U100006';
        }
        $idx = max(0, $userId - self::USER_ID_START);
        $letters = range('a', 'z');
        $letterCount = count($letters);
        $numSpan = self::NAME_NUM_MAX - self::NAME_NUM_MIN + 1; // 89
        $total = $letterCount * $numSpan;
        if ($total <= 0) {
            return '黑塔小人a11';
        }
        $idx = $idx % $total;
        $numOffset = intdiv($idx, $letterCount);
        $letterOffset = $idx % $letterCount;
        $suffix = $letters[$letterOffset] . (string)(self::NAME_NUM_MIN + $numOffset);
        return '黑塔小人' . $suffix;
    }

    private static function ensurePlayerAutoIncrementFloor(PDO $pdo, int $floor): void
    {
        if ($floor <= 1) {
            return;
        }
        $sql = "SELECT AUTO_INCREMENT
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players'
                LIMIT 1";
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : false;
        $next = is_array($row) && isset($row['AUTO_INCREMENT']) ? (int)$row['AUTO_INCREMENT'] : 0;
        if ($next > 0 && $next < $floor) {
            $pdo->exec('ALTER TABLE players AUTO_INCREMENT = ' . $floor);
        }
    }

    public static function issuePvzolToken(int $userId): string
    {
        return AuthToken::issue($userId);
    }

    public static function userXmlPathByUserId(int $userId): string
    {
        $runtimeExtracted = realpath(__DIR__ . '/../../../runtime/extracted')
            ?: (__DIR__ . '/../../../runtime/extracted');
        $usersDir = $runtimeExtracted . DIRECTORY_SEPARATOR . 'users';
        if (!is_dir($usersDir)) {
            @mkdir($usersDir, 0777, true);
        }
        return $usersDir . DIRECTORY_SEPARATOR . $userId . '.xml';
    }

    private static function ensureSigUserTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sig_user (
              sig VARCHAR(128) NOT NULL,
              user_id BIGINT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (sig),
              UNIQUE KEY uniq_sig_user_user_id (user_id),
              KEY idx_sig_user_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            $pdo->exec("ALTER TABLE sig_user ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        } catch (Throwable $e) {
        }
    }

    private static function ensureOrganismsTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS organisms (
              org_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT NOT NULL,
              tpl_id INT NOT NULL,
              level INT NOT NULL DEFAULT 1,
              quality INT NOT NULL DEFAULT 1,
              exp BIGINT NOT NULL DEFAULT 0,
              hp BIGINT NOT NULL DEFAULT 100,
              hp_max BIGINT NOT NULL DEFAULT 100,
              attack BIGINT NOT NULL DEFAULT 100,
              miss BIGINT NOT NULL DEFAULT 0,
              speed BIGINT NOT NULL DEFAULT 50,
              precision_val BIGINT NOT NULL DEFAULT 0,
              new_miss BIGINT NOT NULL DEFAULT 0,
              new_precision BIGINT NOT NULL DEFAULT 0,
              quality_name VARCHAR(32) NOT NULL DEFAULT '普通',
              dq INT NOT NULL DEFAULT 0,
              gi INT NOT NULL DEFAULT 0,
              mature INT NOT NULL DEFAULT 1,
              ss BIGINT NOT NULL DEFAULT 0,
              sh BIGINT NOT NULL DEFAULT 0,
              sa BIGINT NOT NULL DEFAULT 0,
              spr BIGINT NOT NULL DEFAULT 0,
              sm BIGINT NOT NULL DEFAULT 0,
              new_syn_precision BIGINT NOT NULL DEFAULT 0,
              new_syn_miss BIGINT NOT NULL DEFAULT 0,
              fight BIGINT NOT NULL DEFAULT 0,
              skill TEXT NULL,
              exskill TEXT NULL,
              tal_add_xml TEXT NULL,
              soul_add_xml TEXT NULL,
              tals_xml TEXT NULL,
              soul_value INT NOT NULL DEFAULT 0,
              skills_json JSON NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (org_id),
              KEY idx_organisms_user (user_id),
              KEY idx_organisms_user_tpl (user_id, tpl_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $alterCols = [
            "ADD COLUMN hp_max BIGINT NOT NULL DEFAULT 100",
            "ADD COLUMN attack BIGINT NOT NULL DEFAULT 100",
            "ADD COLUMN miss BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN speed BIGINT NOT NULL DEFAULT 50",
            "ADD COLUMN precision_val BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN new_miss BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN new_precision BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN quality_name VARCHAR(32) NOT NULL DEFAULT '普通'",
            "ADD COLUMN dq INT NOT NULL DEFAULT 0",
            "ADD COLUMN gi INT NOT NULL DEFAULT 0",
            "ADD COLUMN mature INT NOT NULL DEFAULT 1",
            "ADD COLUMN ss BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN sh BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN sa BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN spr BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN sm BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN new_syn_precision BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN new_syn_miss BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN fight BIGINT NOT NULL DEFAULT 0",
            "ADD COLUMN skill VARCHAR(255) NOT NULL DEFAULT ''",
            "ADD COLUMN exskill VARCHAR(255) NOT NULL DEFAULT ''",
            "ADD COLUMN tal_add_xml TEXT NULL",
            "ADD COLUMN soul_add_xml TEXT NULL",
            "ADD COLUMN tals_xml TEXT NULL",
            "ADD COLUMN soul_value INT NOT NULL DEFAULT 0",
        ];
        foreach ($alterCols as $sql) {
            try {
                $pdo->exec("ALTER TABLE organisms {$sql}");
            } catch (Throwable $e) {
            }
        }
    }
}
