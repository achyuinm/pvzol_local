<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';

/*
Usage:
  C:\php\php.exe server/game_root/server/app/bin/fix_org_integrity.php <user_id> [--apply]
Default is dry-run.
*/

$uid = (int)($argv[1] ?? 0);
$apply = in_array('--apply', $argv, true);
if ($uid <= 0) {
    fwrite(STDERR, "Usage: php fix_org_integrity.php <user_id> [--apply]\n");
    exit(1);
}

$defaultTalAdd = '<tal_add hp="0" attack="0" speed="0" miss="0" precision="0"/>';
$defaultSoulAdd = '<soul_add hp="0" attack="0" speed="0" miss="0" precision="0"/>';
$defaultTals = '<tals>'
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

try {
    $pdo = DB::pdo();
    $st = $pdo->prepare('SELECT * FROM organisms WHERE user_id=:uid ORDER BY org_id');
    $st->execute([':uid' => $uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $patches = [];
    foreach ($rows as $r) {
        $orgId = (int)$r['org_id'];
        $patch = [];

        $skillsRaw = is_string($r['skills_json'] ?? null) ? (string)$r['skills_json'] : '[]';
        $skills = json_decode($skillsRaw, true);
        $skillsEmpty = !is_array($skills) || $skills === [];

        if ($skillsEmpty) {
            if ((string)($r['skill'] ?? '') !== '') {
                $patch['skill'] = '';
            }
            if ((string)($r['exskill'] ?? '') !== '') {
                $patch['exskill'] = '';
            }
            if (!is_array($skills)) {
                $patch['skills_json'] = '[]';
            }
        }

        if (!isset($r['tal_add_xml']) || $r['tal_add_xml'] === null || (string)$r['tal_add_xml'] === '') {
            $patch['tal_add_xml'] = $defaultTalAdd;
        }
        if (!isset($r['soul_add_xml']) || $r['soul_add_xml'] === null || (string)$r['soul_add_xml'] === '') {
            $patch['soul_add_xml'] = $defaultSoulAdd;
        }
        if (!isset($r['tals_xml']) || $r['tals_xml'] === null || (string)$r['tals_xml'] === '') {
            $patch['tals_xml'] = $defaultTals;
        }

        if ($patch !== []) {
            $patches[] = ['org_id' => $orgId, 'patch' => $patch];
        }
    }

    echo json_encode([
        'user_id' => $uid,
        'dry_run' => !$apply,
        'rows_total' => count($rows),
        'patch_count' => count($patches),
        'patches' => $patches,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    if (!$apply || $patches === []) {
        exit(0);
    }

    $pdo->beginTransaction();
    $logLines = [];
    foreach ($patches as $p) {
        $orgId = (int)$p['org_id'];
        $data = (array)$p['patch'];
        $set = [];
        $bind = [':uid' => $uid, ':oid' => $orgId];
        foreach ($data as $k => $v) {
            $ph = ':v_' . $k;
            $set[] = "`{$k}`={$ph}";
            $bind[$ph] = $v;
        }
        $sql = 'UPDATE organisms SET ' . implode(',', $set) . ', updated_at=CURRENT_TIMESTAMP WHERE user_id=:uid AND org_id=:oid';
        $u = $pdo->prepare($sql);
        $u->execute($bind);
        $logLines[] = sprintf('uid=%d org_id=%d patch=%s', $uid, $orgId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $pdo->commit();

    $logPath = __DIR__ . '/../../../runtime/logs/org_integrity_fix.log';
    foreach ($logLines as $line) {
        @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    echo json_encode(['applied' => true, 'updated' => count($patches)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'fix failed: ' . $e->getMessage() . "\n");
    exit(1);
}
