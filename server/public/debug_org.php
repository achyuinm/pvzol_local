<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/DB.php';
require_once __DIR__ . '/../app/core/SessionResolver.php';
require_once __DIR__ . '/../app/dao/OrgDao.php';

header('Content-Type: application/json; charset=utf-8');

$sig = (string)($_GET['sig'] ?? '');
$orgId = (int)($_GET['orgId'] ?? 0);
$uid = (int)($_GET['userId'] ?? 0);

if ($uid <= 0) {
    $session = SessionResolver::resolveFromRequest($sig);
    $uid = (int)($session['user_id'] ?? 0);
}

if ($uid <= 0 || $orgId <= 0) {
    echo json_encode(['ok' => 0, 'error' => 'missing userId/orgId'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $org = $dao->getOne($uid, $orgId);

    $walletStmt = $pdo->prepare("SELECT item_id, qty FROM inventory WHERE user_id=:uid AND item_id IN ('gold','money') ORDER BY item_id");
    $walletStmt->execute([':uid' => $uid]);
    $wallet = $walletStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $related = [];
    $invStmt = $pdo->prepare("SELECT item_id, qty FROM inventory WHERE user_id=:uid AND (item_id LIKE 'tool:%' OR item_id LIKE 'org:%') ORDER BY qty DESC LIMIT 200");
    $invStmt->execute([':uid' => $uid]);
    $related = $invStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => 1,
        'user_id' => $uid,
        'org_id' => $orgId,
        'org' => $org,
        'wallet' => $wallet,
        'inventory_related' => $related,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

