<?php
declare(strict_types=1);

require_once __DIR__ . '/../DB.php';
require_once __DIR__ . '/SessionResolver.php';

final class UserXmlBucket
{
    public static function ensureUserXml(int $userId): string
    {
        $runtimeExtracted = realpath(__DIR__ . '/../../../runtime/extracted')
            ?: (__DIR__ . '/../../../runtime/extracted');
        $defaultPath = $runtimeExtracted . DIRECTORY_SEPARATOR . 'default_user_from_session.xml';
        if ($userId <= 0) {
            return $defaultPath;
        }
        $path = SessionResolver::userXmlPathByUserId($userId);
        if (!is_file($path)) {
            self::reset($userId);
        }
        return $path;
    }

    public static function reset(int $userId): string
    {
        $runtimeExtracted = realpath(__DIR__ . '/../../../runtime/extracted')
            ?: (__DIR__ . '/../../../runtime/extracted');
        $defaultPath = $runtimeExtracted . DIRECTORY_SEPARATOR . 'default_user_from_session.xml';
        $xml = is_file($defaultPath) ? (string)file_get_contents($defaultPath) : '';
        if ($xml === '') {
            $xml = '<?xml version="1.0" encoding="utf-8"?><root><response><status>success</status></response><user id="0" name="U0" money="0" rmb_money="0"></user></root>';
        }
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        @$dom->loadXML($xml, LIBXML_NOBLANKS);
        $users = $dom->getElementsByTagName('user');
        if ($users->length > 0) {
            /** @var DOMElement $user */
            $user = $users->item(0);
            $user->setAttribute('id', (string)$userId);
            $user->setAttribute('name', self::playerNickname($userId));
            $user->setAttribute('money', (string)self::inventoryQty($userId, 'gold'));
            $user->setAttribute('rmb_money', (string)self::inventoryQty($userId, 'money'));

            // New account baseline: level 20 (avoid inheriting high-level template values).
            foreach ($user->getElementsByTagName('grade') as $grade) {
                if ($grade instanceof DOMElement) {
                    $grade->setAttribute('id', '20');
                    $grade->setAttribute('exp', '0');
                    $grade->setAttribute('exp_min', '0');
                    $grade->setAttribute('exp_max', '100');
                    $grade->setAttribute('today_exp', '0');
                    $grade->setAttribute('today_exp_max', '2200');
                    break;
                }
            }
        }
        $out = $dom->saveXML();
        $path = SessionResolver::userXmlPathByUserId($userId);
        @file_put_contents($path, is_string($out) && $out !== '' ? $out : $xml);
        return $path;
    }

    private static function inventoryQty(int $userId, string $itemId): int
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT qty FROM inventory WHERE user_id=:uid AND item_id=:iid LIMIT 1');
        $stmt->execute([':uid' => $userId, ':iid' => $itemId]);
        $row = $stmt->fetch();
        return is_array($row) ? (int)($row['qty'] ?? 0) : 0;
    }

    private static function playerNickname(int $userId): string
    {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('SELECT nickname FROM players WHERE id=:id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();
            $name = is_array($row) ? (string)($row['nickname'] ?? '') : '';
            if ($name !== '') {
                return $name;
            }
        } catch (Throwable $e) {
            // no-op
        }
        return SessionResolver::localDisplayName($userId);
    }
}
