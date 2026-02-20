<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/core/SessionResolver.php';
require_once __DIR__ . '/../app/core/UserXmlBucket.php';

$sess = SessionResolver::resolveFromRequest();
$uid = (int)($sess['user_id'] ?? 0);
if ($uid <= 0) {
    $uid = SessionResolver::createUserAccount();
}
$token = SessionResolver::issuePvzolToken($uid);
setcookie('pvzol', $token, [
    'expires' => time() + 30 * 24 * 3600,
    'path' => '/pvz',
    'httponly' => false,
    'secure' => false,
    'samesite' => 'Lax',
]);
UserXmlBucket::ensureUserXml($uid);

$raw = ((string)($_GET['xml'] ?? '')) === '1';
if ($raw) {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>'
        . '<root><response><status>success</status></response>'
        . '<UserSetting UserID="' . $uid . '" UserCookies="pvzol=' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" UserLevel="1" />'
        . '</root>';
    exit;
}

header('Location: /pvz/index.php/');
exit;
