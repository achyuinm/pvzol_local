<?php
declare(strict_types=1);

/**
 * MySQL database access (PDO).
 *
 * Config sources (priority order):
 * 1) Environment variables:
 *    PVZ_DB_HOST, PVZ_DB_PORT, PVZ_DB_NAME, PVZ_DB_USER, PVZ_DB_PASS, PVZ_DB_CHARSET
 * 2) Local file (not committed): server/app/config.local.php
 *
 * NOTE:
 * - Keep secrets out of git.
 * - This file should not echo anything (safe for AMF binary responses).
 */

final class DB
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $cfg = self::loadConfig();
        $db = $cfg['db'] ?? null;
        if (!is_array($db)) {
            throw new \RuntimeException('DB config missing.');
        }

        $driver = (string)($db['driver'] ?? 'mysql');
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported DB driver: ' . $driver);
        }

        $host = (string)($db['host'] ?? '127.0.0.1');
        $port = (int)($db['port'] ?? 3306);
        $name = (string)($db['name'] ?? '');
        $user = (string)($db['user'] ?? 'root');
        $pass = (string)($db['pass'] ?? '');
        $charset = (string)($db['charset'] ?? 'utf8mb4');

        if ($name === '') {
            throw new \RuntimeException('DB name is empty.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        $opts = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        self::$pdo = new \PDO($dsn, $user, $pass, $opts);
        return self::$pdo;
    }

    public static function loadConfig(): array
    {
        $env = self::envConfig();
        if ($env !== null) {
            return $env;
        }

        $local = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
        if (is_file($local)) {
            $cfg = require $local;
            if (is_array($cfg)) {
                return $cfg;
            }
        }

        // Safe defaults for developer machines. Password is intentionally empty.
        return [
            'db' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'pvzol_root',
                'user' => 'root',
                'pass' => '',
                'charset' => 'utf8mb4',
            ],
        ];
    }

    private static function envConfig(): ?array
    {
        $name = getenv('PVZ_DB_NAME');
        if ($name === false || $name === '') {
            return null;
        }

        $host = getenv('PVZ_DB_HOST');
        $port = getenv('PVZ_DB_PORT');
        $user = getenv('PVZ_DB_USER');
        $pass = getenv('PVZ_DB_PASS');
        $charset = getenv('PVZ_DB_CHARSET');

        return [
            'db' => [
                'driver' => 'mysql',
                'host' => ($host === false || $host === '') ? '127.0.0.1' : (string)$host,
                'port' => ($port === false || $port === '') ? 3306 : (int)$port,
                'name' => (string)$name,
                'user' => ($user === false || $user === '') ? 'root' : (string)$user,
                'pass' => ($pass === false) ? '' : (string)$pass,
                'charset' => ($charset === false || $charset === '') ? 'utf8mb4' : (string)$charset,
            ],
        ];
    }
}

