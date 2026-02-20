<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';
require __DIR__ . '/../core/SessionResolver.php';

$sig = '';
$full = false;
for ($i = 1; $i < $argc; $i++) {
    $arg = (string)$argv[$i];
    if ($arg === '--full') {
        $full = true;
        continue;
    }
    if ($arg === '--sig' && isset($argv[$i + 1])) {
        $sig = strtolower(trim((string)$argv[$i + 1]));
        $i++;
    }
}

if ($sig === '' || !preg_match('/^[a-f0-9]{32}$/', $sig)) {
    fwrite(STDERR, "Usage: php reset_user.php --sig <32hex> [--full]\n");
    exit(1);
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT user_id FROM sig_user WHERE sig = :sig LIMIT 1');
$stmt->execute([':sig' => $sig]);
$row = $stmt->fetch();
$uid = is_array($row) ? (int)($row['user_id'] ?? 0) : 0;

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM sig_user WHERE sig = :sig');
    $del->execute([':sig' => $sig]);

    if ($full && $uid > 0) {
        $d1 = $pdo->prepare('DELETE FROM inventory WHERE user_id = :uid');
        $d1->execute([':uid' => $uid]);
        $d2 = $pdo->prepare('DELETE FROM player_state WHERE user_id = :uid');
        $d2->execute([':uid' => $uid]);
        $d3 = $pdo->prepare('DELETE FROM players WHERE id = :uid');
        $d3->execute([':uid' => $uid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$xml = realpath(__DIR__ . '/../../../runtime/extracted/users/' . $sig . '.xml')
    ?: (__DIR__ . '/../../../runtime/extracted/users/' . $sig . '.xml');
if (is_file($xml)) {
    @unlink($xml);
}

fwrite(STDOUT, "OK reset sig={$sig} uid={$uid} full=" . ($full ? '1' : '0') . "\n");

