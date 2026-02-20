<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/DB.php';
require_once __DIR__ . '/../app/core/SessionResolver.php';

function sig_cookie_set(string $sig): void
{
    setcookie('pvzol', $sig, [
        'expires' => time() + 30 * 24 * 3600,
        'path' => '/pvz',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

function sig_cookie_clear(): void
{
    setcookie('pvzol', '', [
        'expires' => time() - 3600,
        'path' => '/pvz',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
    setcookie('sig', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
    setcookie('pvzol_seed', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

function ensure_sig_user_table(PDO $pdo): void
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
        // already exists
    }
    try {
        $pdo->exec("UPDATE sig_user SET last_seen_at = COALESCE(last_seen_at, NOW())");
    } catch (Throwable $e) {
        // no-op
    }
    try {
        $pdo->exec("ALTER TABLE sig_user ADD UNIQUE KEY uniq_sig_user_user_id (user_id)");
    } catch (Throwable $e) {
        // already exists
    }
}

function upsert_sig_user(PDO $pdo, string $sig, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO sig_user (sig,user_id,last_seen_at) VALUES (:sig,:uid,NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()'
    );
    $stmt->execute([':sig' => $sig, ':uid' => $userId]);
}

function load_sig_users(PDO $pdo, int $limit = 20): array
{
    $stmt = $pdo->prepare('SELECT sig, user_id, last_seen_at FROM sig_user ORDER BY last_seen_at DESC LIMIT ' . (int)$limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function cookie_xml_content(int $uid, string $token): string
{
    $xml = '<?xml version="1.0"?>'
        . '<UserSetting>'
        . '<UserID>' . $uid . '</UserID>'
        . '<UserDomain>http://pvzol.org</UserDomain>'
        . '<UserCookies>pvzol=' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</UserCookies>'
        . '<UserName>*</UserName>'
        . '<UserLevel>1</UserLevel>'
        . '</UserSetting>';
    return $xml;
}

$pdo = DB::pdo();
ensure_sig_user_table($pdo);

$action = (string)($_POST['action'] ?? '');
if ($action === 'use') {
    $sig = strtolower(trim((string)($_POST['sig'] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/', $sig)) {
        $session = SessionResolver::resolveFromSig($sig);
        $token = SessionResolver::issuePvzolToken((int)($session['user_id'] ?? 0));
        sig_cookie_set($token);
        upsert_sig_user($pdo, $sig, (int)($session['user_id'] ?? 0));
        header('Location: /pvz/sig?selected=' . urlencode($sig));
        exit;
    }
}

if ($action === 'new') {
    $sig = bin2hex(random_bytes(16));
    $session = SessionResolver::resolveFromSig($sig);
    $token = SessionResolver::issuePvzolToken((int)($session['user_id'] ?? 0));
    sig_cookie_set($token);
    upsert_sig_user($pdo, $sig, (int)($session['user_id'] ?? 0));
    header('Location: /pvz/sig?selected=' . urlencode($sig));
    exit;
}

if ($action === 'download_cookie') {
    $sig = strtolower(trim((string)($_POST['sig'] ?? '')));
    if ($sig !== '' && preg_match('/^[a-f0-9]{32}$/', $sig)) {
        $session = SessionResolver::resolveFromSig($sig);
        $uid = (int)($session['user_id'] ?? 0);
        if ($uid > 0) {
            $token = SessionResolver::issuePvzolToken($uid);
            sig_cookie_set($token);
            upsert_sig_user($pdo, $sig, $uid);
            $xml = cookie_xml_content($uid, $token);
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="cookie.xml"');
            echo $xml;
            exit;
        }
    }
    header('Location: /pvz/sig');
    exit;
}

if ($action === 'clear') {
    sig_cookie_clear();
    header('Location: /pvz/sig');
    exit;
}

if ($action === 'delete') {
    $sig = strtolower(trim((string)($_POST['sig'] ?? '')));
    if ($sig !== '' && preg_match('/^[a-f0-9]{32}$/', $sig)) {
        $stmt = $pdo->prepare('SELECT user_id FROM sig_user WHERE sig = :sig LIMIT 1');
        $stmt->execute([':sig' => $sig]);
        $row = $stmt->fetch();
        $uid = is_array($row) ? (int)($row['user_id'] ?? 0) : 0;

        $del = $pdo->prepare('DELETE FROM sig_user WHERE sig = :sig');
        $del->execute([':sig' => $sig]);

        if ($uid > 0) {
            $xmlPath = __DIR__ . '/../../runtime/extracted/users/' . $uid . '.xml';
            if (is_file($xmlPath)) {
                @unlink($xmlPath);
            }
        }
    }
    header('Location: /pvz/sig');
    exit;
}

$currentSig = strtolower(trim((string)($_GET['selected'] ?? '')));
$hasPvzol = (string)($_COOKIE['pvzol'] ?? '') !== '';
$rows = load_sig_users($pdo, 20);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PVZ SIG 选择器</title>
  <style>
    body { font-family: Segoe UI, Microsoft YaHei, sans-serif; margin: 24px; background: #f6f8fa; color: #1f2328; }
    .card { background: #fff; border: 1px solid #d0d7de; border-radius: 8px; padding: 16px; max-width: 960px; }
    table { border-collapse: collapse; width: 100%; margin-top: 12px; }
    th, td { border: 1px solid #d0d7de; padding: 8px; text-align: left; font-size: 14px; }
    button { padding: 6px 10px; cursor: pointer; }
    code { background: #eef1f4; padding: 2px 6px; border-radius: 4px; }
    .row { margin-top: 8px; }
  </style>
</head>
<body>
  <div class="card">
    <h2>SIG 选择器</h2>
    <div class="row">当前选择 sig：<code><?php echo htmlspecialchars($currentSig !== '' ? $currentSig : '(未选择)', ENT_QUOTES, 'UTF-8'); ?></code></div>
    <div class="row">当前 pvzol 状态：<code><?php echo $hasPvzol ? '已设置' : '未设置'; ?></code></div>
    <div class="row">
      <form method="post" style="display:inline-block;margin-right:8px;">
        <input type="hidden" name="action" value="new">
        <button type="submit">新建账号</button>
      </form>
      <form method="post" style="display:inline-block;margin-right:8px;">
        <input type="hidden" name="action" value="clear">
        <button type="submit">清除/游客</button>
      </form>
      <a href="/pvz/index.php/">进入游戏</a>
    </div>
    <table>
      <thead>
        <tr><th>sig</th><th>user_id</th><th>last_seen_at</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?php echo htmlspecialchars((string)($r['sig'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
          <td><?php echo (int)($r['user_id'] ?? 0); ?></td>
          <td><?php echo htmlspecialchars((string)($r['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <form method="post" style="display:inline-block;">
              <input type="hidden" name="action" value="use">
              <input type="hidden" name="sig" value="<?php echo htmlspecialchars((string)($r['sig'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit">使用</button>
            </form>
            <form method="post" style="display:inline-block;margin-left:6px;">
              <input type="hidden" name="action" value="download_cookie">
              <input type="hidden" name="sig" value="<?php echo htmlspecialchars((string)($r['sig'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit">下载Cookie</button>
            </form>
            <form method="post" style="display:inline-block;margin-left:6px;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="sig" value="<?php echo htmlspecialchars((string)($r['sig'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
