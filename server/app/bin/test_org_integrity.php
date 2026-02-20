<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';

/*
Usage:
  C:\php\php.exe server/game_root/server/app/bin/test_org_integrity.php <user_id>
*/

$uid = (int)($argv[1] ?? 0);
if ($uid <= 0) {
    fwrite(STDERR, "Usage: php test_org_integrity.php <user_id>\n");
    exit(1);
}

try {
    $pdo = DB::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$checks = [];

try {
    $checks['rows_total'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid}")->fetchColumn();
    $checks['skills_json_empty_but_skill_nonempty'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND (skills_json IS NULL OR JSON_LENGTH(skills_json)=0) AND CHAR_LENGTH(COALESCE(skill,''))>0")->fetchColumn();
    $checks['skills_json_empty_but_exskill_nonempty'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND (skills_json IS NULL OR JSON_LENGTH(skills_json)=0) AND CHAR_LENGTH(COALESCE(exskill,''))>0")->fetchColumn();
    $checks['null_tal_add_xml'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND tal_add_xml IS NULL")->fetchColumn();
    $checks['null_soul_add_xml'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND soul_add_xml IS NULL")->fetchColumn();
    $checks['null_tals_xml'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND tals_xml IS NULL")->fetchColumn();
    $checks['hp_gt_hpmax'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND hp>hp_max")->fetchColumn();
    $checks['negative_core_stats'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND (hp<0 OR hp_max<0 OR attack<0 OR miss<0 OR speed<0 OR precision_val<0)")->fetchColumn();
    $checks['core_stats_equal_100'] = (int)$pdo->query("SELECT COUNT(*) FROM organisms WHERE user_id={$uid} AND hp=100 AND hp_max=100 AND attack=100 AND miss=100 AND speed=100 AND precision_val=100")->fetchColumn();

    $topStmt = $pdo->prepare(
        "SELECT org_id,tpl_id,level,quality,exp,hp,hp_max,attack,miss,speed,precision_val,new_miss,new_precision,fight,
                CHAR_LENGTH(COALESCE(skill,'')) AS skill_len,
                CHAR_LENGTH(COALESCE(exskill,'')) AS exskill_len,
                CHAR_LENGTH(COALESCE(tal_add_xml,'')) AS tal_add_len,
                CHAR_LENGTH(COALESCE(soul_add_xml,'')) AS soul_add_len,
                CHAR_LENGTH(COALESCE(tals_xml,'')) AS tals_len
         FROM organisms
         WHERE user_id=:uid
         ORDER BY org_id
         LIMIT 20"
    );
    $topStmt->execute([':uid' => $uid]);
    $topRows = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [
        'user_id' => $uid,
        'checks' => $checks,
        'sample_rows' => $topRows,
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "query failed: " . $e->getMessage() . "\n");
    exit(1);
}

