<?php
declare(strict_types=1);

final class OrgDao
{
    private PDO $pdo;
    private static bool $tableReady = false;
    // Growth ranges are DB-driven in runtime logic; keep DAO seed deterministic.

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->pdo->exec(
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
              quality_name VARCHAR(32) NOT NULL DEFAULT 'Q1',
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
              KEY idx_organisms_user_org (user_id, org_id),
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
            "ADD COLUMN quality_name VARCHAR(32) NOT NULL DEFAULT 'Q1'",
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
            "ADD COLUMN skill TEXT NULL",
            "ADD COLUMN exskill TEXT NULL",
            "ADD COLUMN tal_add_xml TEXT NULL",
            "ADD COLUMN soul_add_xml TEXT NULL",
            "ADD COLUMN tals_xml TEXT NULL",
            "ADD COLUMN soul_value INT NOT NULL DEFAULT 0",
        ];
        foreach ($alterCols as $sql) {
            try {
                $this->pdo->exec("ALTER TABLE organisms {$sql}");
            } catch (Throwable $e) {
            }
        }
        // Old schema used VARCHAR(255), which is too short for full <sk>/<ssk> xml.
        try {
            $this->pdo->exec("ALTER TABLE organisms MODIFY COLUMN skill TEXT NULL");
        } catch (Throwable $e) {
        }
        try {
            $this->pdo->exec("ALTER TABLE organisms MODIFY COLUMN exskill TEXT NULL");
        } catch (Throwable $e) {
        }
        self::$tableReady = true;
    }

    /** @return array<int,array<string,mixed>> */
    public function getByUser(int $userId): array
    {
        $this->ensureTable();
        $started = microtime(true);
        $stmt = $this->pdo->prepare('SELECT * FROM organisms WHERE user_id=:uid ORDER BY org_id');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $this->logRead(sprintf('uid=%d rows=%d elapsed=%dms', $userId, count($rows), $elapsed));
        return array_map([$this, 'normalizeRow'], $rows);
    }

    public function getOne(int $userId, int $orgId): ?array
    {
        $this->ensureTable();
        $started = microtime(true);
        $stmt = $this->pdo->prepare('SELECT * FROM organisms WHERE user_id=:uid AND org_id=:oid LIMIT 1');
        $stmt->execute([':uid' => $userId, ':oid' => $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $this->logRead(sprintf('uid=%d org_id=%d hit=%s elapsed=%dms', $userId, $orgId, is_array($row) ? '1' : '0', $elapsed));
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    public function upsertOrg(int $userId, array $orgRow): void
    {
        $this->ensureTable();
        $orgId = (int)($orgRow['org_id'] ?? 0);
        if ($orgId > 0) {
            $patch = $orgRow;
            unset($patch['org_id'], $patch['user_id']);
            $this->updateFields($userId, $orgId, $patch);
            return;
        }
        $row = $this->normalizeRow($orgRow + ['user_id' => $userId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO organisms (user_id,tpl_id,level,quality,exp,hp,hp_max,attack,miss,speed,precision_val,new_miss,new_precision,quality_name,dq,gi,mature,ss,sh,sa,spr,sm,new_syn_precision,new_syn_miss,fight,skill,exskill,tal_add_xml,soul_add_xml,tals_xml,soul_value,skills_json)
             VALUES (:user_id,:tpl_id,:level,:quality,:exp,:hp,:hp_max,:attack,:miss,:speed,:precision_val,:new_miss,:new_precision,:quality_name,:dq,:gi,:mature,:ss,:sh,:sa,:spr,:sm,:new_syn_precision,:new_syn_miss,:fight,:skill,:exskill,:tal_add_xml,:soul_add_xml,:tals_xml,:soul_value,:skills_json)'
        );
        $stmt->execute($this->bindRow($row));
        $this->logWrite(sprintf('uid=%d org_id=new tpl_id=%d level=%d', $userId, (int)$row['tpl_id'], (int)$row['level']));
    }

    public function updateFields(int $userId, int $orgId, array $patch): void
    {
        $this->ensureTable();
        if ($patch === []) {
            return;
        }
        $allowed = [
            'tpl_id', 'level', 'quality', 'exp', 'hp', 'hp_max', 'attack', 'miss', 'speed', 'precision_val',
            'new_miss', 'new_precision', 'quality_name', 'dq', 'gi', 'mature', 'ss', 'sh', 'sa', 'spr', 'sm',
            'new_syn_precision', 'new_syn_miss', 'fight', 'skill', 'exskill', 'tal_add_xml', 'soul_add_xml', 'tals_xml', 'soul_value', 'skills_json'
        ];
        $set = [];
        $bind = [':uid' => $userId, ':oid' => $orgId];
        foreach ($patch as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $ph = ':v_' . $k;
            $set[] = "`{$k}`={$ph}";
            $bind[$ph] = $v;
        }
        if ($set === []) {
            return;
        }
        $sql = 'UPDATE organisms SET ' . implode(',', $set) . ', updated_at=CURRENT_TIMESTAMP WHERE user_id=:uid AND org_id=:oid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $this->logWrite(sprintf('uid=%d org_id=%d patch=%s', $userId, $orgId, json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function seedFromWarehouseTemplate(int $userId): void
    {
        $rows = $this->getByUser($userId);
        if ($rows !== []) {
            return;
        }
        // DB-only seed: do not read XML/JSON template values.
        $this->upsertOrg($userId, [
            'tpl_id' => 1,
            'level' => 1,
            'quality' => 1,
            'exp' => 0,
            'hp' => 100,
            'hp_max' => 100,
            'attack' => 100,
            'miss' => 100,
            'speed' => 100,
            'precision_val' => 100,
            'new_miss' => 100,
            'new_precision' => 100,
            'quality_name' => 'Q1',
            'dq' => 0,
            'gi' => 0,
            'mature' => 3,
            'ss' => 0,
            'sh' => 0,
            'sa' => 0,
            'spr' => 0,
            'sm' => 0,
            'new_syn_precision' => 0,
            'new_syn_miss' => 0,
            'fight' => 0,
            'skill' => '',
            'exskill' => '',
            'tal_add_xml' => '<tal_add hp="0" attack="0" speed="0" miss="0" precision="0"/>',
            'soul_add_xml' => '<soul_add hp="0" attack="0" speed="0" miss="0" precision="0"/>',
            'tals_xml' => '<tals><tal id="talent_1" level="0"/><tal id="talent_2" level="0"/><tal id="talent_3" level="0"/><tal id="talent_4" level="0"/><tal id="talent_5" level="0"/><tal id="talent_6" level="0"/><tal id="talent_7" level="0"/><tal id="talent_8" level="0"/><tal id="talent_9" level="0"/></tals>',
            'soul_value' => 0,
            'skills_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function normalizeRow(array $row): array
    {
        $tplId = (int)($row['tpl_id'] ?? 151);
        $mature = (int)($row['mature'] ?? 0);
        if ($mature <= 0) {
            $mature = $this->resolveGrowthByTpl($tplId);
        }
        $talAdd = (string)($row['tal_add_xml'] ?? '');
        if ($talAdd === '') {
            $talAdd = '<tal_add hp="0" attack="0" speed="0" miss="0" precision="0"/>';
        }
        $soulAdd = (string)($row['soul_add_xml'] ?? '');
        if ($soulAdd === '') {
            $soulAdd = '<soul_add hp="0" attack="0" speed="0" miss="0" precision="0"/>';
        }
        $talsXml = (string)($row['tals_xml'] ?? '');
        if ($talsXml === '') {
            $talsXml = '<tals>'
                . '<tal id="talent_1" level="0"/>'
                . '<tal id="talent_2" level="0"/>'
                . '<tal id="talent_3" level="0"/>'
                . '<tal id="talent_4" level="0"/>'
                . '<tal id="talent_5" level="0"/>'
                . '<tal id="talent_6" level="0"/>'
                . '<tal id="talent_7" level="0"/>'
                . '<tal id="talent_8" level="0"/>'
                . '<tal id="talent_9" level="0"/>'
                . '</tals>';
        }
        $skillsJson = is_string($row['skills_json'] ?? null) ? (string)$row['skills_json'] : '[]';
        $decodedSkills = json_decode($skillsJson, true);
        if (!is_array($decodedSkills)) {
            $decodedSkills = [];
            $skillsJson = '[]';
        }
        $skillXml = (string)($row['skill'] ?? '');
        $exSkillXml = (string)($row['exskill'] ?? '');
        // If no learned skills are persisted, never expose template/default skill XML.
        if ($decodedSkills === []) {
            $skillXml = '';
            $exSkillXml = '';
        } else {
            $hasSpec = false;
            foreach ($decodedSkills as $k => $_v) {
                if (is_string($k) && str_starts_with($k, 'spec:')) {
                    $hasSpec = true;
                    break;
                }
            }
            // Prevent stale template EX skill (e.g. id=100) leaking when no spec skill is learned.
            if (!$hasSpec) {
                $exSkillXml = '';
            }
        }

        return [
            'org_id' => (int)($row['org_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'tpl_id' => $tplId,
            'level' => (int)($row['level'] ?? 1),
            'quality' => (int)($row['quality'] ?? 1),
            'exp' => (int)($row['exp'] ?? 0),
            'hp' => (int)($row['hp'] ?? 100),
            'hp_max' => (int)($row['hp_max'] ?? 100),
            'attack' => (int)($row['attack'] ?? 100),
            'miss' => (int)($row['miss'] ?? 100),
            'speed' => (int)($row['speed'] ?? 100),
            'precision_val' => (int)($row['precision_val'] ?? 100),
            'new_miss' => (int)($row['new_miss'] ?? 100),
            'new_precision' => (int)($row['new_precision'] ?? 100),
            'quality_name' => (string)($row['quality_name'] ?? 'Q1'),
            'dq' => (int)($row['dq'] ?? 0),
            'gi' => (int)($row['gi'] ?? 0),
            'mature' => $mature,
            'ss' => (int)($row['ss'] ?? 0),
            'sh' => (int)($row['sh'] ?? 0),
            'sa' => (int)($row['sa'] ?? 0),
            'spr' => (int)($row['spr'] ?? 0),
            'sm' => (int)($row['sm'] ?? 0),
            'new_syn_precision' => (int)($row['new_syn_precision'] ?? 0),
            'new_syn_miss' => (int)($row['new_syn_miss'] ?? 0),
            'fight' => (int)($row['fight'] ?? 0),
            'skill' => $skillXml,
            'exskill' => $exSkillXml,
            'tal_add_xml' => $talAdd,
            'soul_add_xml' => $soulAdd,
            'tals_xml' => $talsXml,
            'soul_value' => (int)($row['soul_value'] ?? 0),
            'skills_json' => $skillsJson,
        ];
    }

    private function bindRow(array $row): array
    {
        return [
            ':user_id' => $row['user_id'],
            ':tpl_id' => $row['tpl_id'],
            ':level' => $row['level'],
            ':quality' => $row['quality'],
            ':exp' => $row['exp'],
            ':hp' => $row['hp'],
            ':hp_max' => $row['hp_max'],
            ':attack' => $row['attack'],
            ':miss' => $row['miss'],
            ':speed' => $row['speed'],
            ':precision_val' => $row['precision_val'],
            ':new_miss' => $row['new_miss'],
            ':new_precision' => $row['new_precision'],
            ':quality_name' => $row['quality_name'],
            ':dq' => $row['dq'],
            ':gi' => $row['gi'],
            ':mature' => $row['mature'],
            ':ss' => $row['ss'],
            ':sh' => $row['sh'],
            ':sa' => $row['sa'],
            ':spr' => $row['spr'],
            ':sm' => $row['sm'],
            ':new_syn_precision' => $row['new_syn_precision'],
            ':new_syn_miss' => $row['new_syn_miss'],
            ':fight' => $row['fight'],
            ':skill' => $row['skill'],
            ':exskill' => $row['exskill'],
            ':tal_add_xml' => $row['tal_add_xml'],
            ':soul_add_xml' => $row['soul_add_xml'],
            ':tals_xml' => $row['tals_xml'],
            ':soul_value' => $row['soul_value'],
            ':skills_json' => $row['skills_json'],
        ];
    }

    private function logWrite(string $line): void
    {
        @file_put_contents(__DIR__ . '/../../../runtime/logs/org_write.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private function logRead(string $line): void
    {
        @file_put_contents(__DIR__ . '/../../../runtime/logs/org_read.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private function resolveGrowthByTpl(int $tplId): int
    {
        $min = 3;
        $max = 6;
        try {
            return random_int($min, $max);
        } catch (Throwable $e) {
            return $min;
        }
    }

}


