<?php
declare(strict_types=1);

final class HttpUtil
{
    public static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function shortToken(int $bytes = 16): string
    {
        return strtoupper(bin2hex(random_bytes($bytes)));
    }

    public static function setShortLivedTokenCookie(): void
    {
        // Matches real traffic: token cookie with Max-Age ~ 1s.
        setcookie('token', self::shortToken(16), [
            'expires' => time() + 1,
            'path' => '/',
            'httponly' => true,
            'secure' => false,
            'samesite' => 'Lax',
        ]);
    }

    public static function pvzolSeed(): string
    {
        $seed = (string)($_COOKIE['pvzol_seed'] ?? '');
        if ($seed !== '') {
            return $seed;
        }

        // Allow bootstrap via query string (launcher passes ?seed=...).
        $seed = (string)($_GET['seed'] ?? '');
        if ($seed === '') {
            $seed = 'local';
        }

        // Persist for Flash cookie jar.
        setcookie('pvzol_seed', $seed, [
            'expires' => time() + 365 * 24 * 3600,
            'path' => '/',
            'httponly' => false,
            'secure' => false,
            'samesite' => 'Lax',
        ]);

        return $seed;
    }

    public static function userIdFromSeed(string $seed): int
    {
        // Stable 1..2^31-1 derived from seed.
        $h = hash('sha256', $seed, true);
        $v = unpack('N', substr($h, 0, 4))[1];
        $id = (int)($v & 0x7fffffff);
        return $id > 0 ? $id : 1;
    }

    public static function routePath(string $requestUri, string $prefix = '/pvz/index.php/'): string
    {
        $uri = (string)parse_url($requestUri, PHP_URL_PATH);
        $pos = strpos($uri, $prefix);
        if ($pos === false) {
            return '';
        }
        return trim(substr($uri, $pos + strlen($prefix)), '/');
    }
}
