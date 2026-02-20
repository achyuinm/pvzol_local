<?php
declare(strict_types=1);

require_once __DIR__ . '/../DB.php';

final class AuthToken
{
    public static function issue(int $userId, int $ttl = 2592000): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('invalid user id');
        }
        $issuedAt = time();
        $payload = [
            'uid' => $userId,
            'issued_at' => $issuedAt,
            'nonce' => bin2hex(random_bytes(8)),
            'exp' => $issuedAt + $ttl,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('token payload encode failed');
        }
        $b64 = self::b64urlEncode($json);
        $sig = hash_hmac('sha256', $b64, self::secret());
        $token = $b64 . '.' . $sig;
        self::saveTokenHash($token, $userId, $issuedAt, $payload['exp']);
        return $token;
    }

    /**
     * @return array{valid:bool,user_id:int,reason:string}
     */
    public static function verify(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'empty'];
        }
        [$b64, $sig] = explode('.', $token, 2);
        if ($b64 === '' || $sig === '') {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'format'];
        }
        $want = hash_hmac('sha256', $b64, self::secret());
        if (!hash_equals($want, $sig)) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'bad-signature'];
        }
        $raw = self::b64urlDecode($b64);
        if ($raw === '') {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'bad-payload'];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'bad-json'];
        }
        $uid = (int)($payload['uid'] ?? 0);
        $exp = (int)($payload['exp'] ?? 0);
        if ($uid <= 0 || $exp <= 0) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'invalid-fields'];
        }
        if (time() > $exp) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'expired'];
        }
        if (!self::existsTokenHash($token, $uid)) {
            return ['valid' => false, 'user_id' => 0, 'reason' => 'revoked-or-missing'];
        }
        self::touchTokenHash($token);
        return ['valid' => true, 'user_id' => $uid, 'reason' => 'ok'];
    }

    private static function secret(): string
    {
        $s = getenv('PVZ_AUTH_SECRET');
        if ($s !== false && $s !== '') {
            return (string)$s;
        }
        return 'pvzol_local_dev_secret_change_me';
    }

    private static function saveTokenHash(string $token, int $uid, int $issuedAt, int $expiresAt): void
    {
        $pdo = DB::pdo();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'INSERT INTO auth_tokens (token_hash,user_id,issued_at,expires_at,last_seen_at)
             VALUES (:h,:u,FROM_UNIXTIME(:ia),FROM_UNIXTIME(:ea),NOW())
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), issued_at=VALUES(issued_at), expires_at=VALUES(expires_at), last_seen_at=NOW()'
        );
        $stmt->execute([':h' => $hash, ':u' => $uid, ':ia' => $issuedAt, ':ea' => $expiresAt]);
    }

    private static function existsTokenHash(string $token, int $uid): bool
    {
        $pdo = DB::pdo();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT user_id FROM auth_tokens WHERE token_hash=:h AND expires_at >= NOW() LIMIT 1');
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        return is_array($row) && (int)($row['user_id'] ?? 0) === $uid;
    }

    private static function touchTokenHash(string $token): void
    {
        $pdo = DB::pdo();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('UPDATE auth_tokens SET last_seen_at = NOW() WHERE token_hash=:h');
        $stmt->execute([':h' => $hash]);
    }

    private static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        return is_string($out) ? $out : '';
    }
}

