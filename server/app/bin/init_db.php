<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';

function ensure_database_exists(array $cfg): void
{
    $db = $cfg['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('DB config missing.');
    }
    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? 'root');
    $pass = (string)($db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($name === '') {
        throw new RuntimeException('DB name is empty.');
    }

    // Connect without specifying dbname so we can CREATE DATABASE if missing.
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // MySQL doesn't allow binding identifiers; quote with backticks.
    $safeName = str_replace('`', '``', $name);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
}

function read_schema_file(string $path): string
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Failed to read schema: ' . $path);
    }
    return $sql;
}

function split_sql_statements(string $sql): array
{
    // Minimal splitter suitable for this repo's simple schema.sql (no procedures/triggers).
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
            continue;
        }
        $buf .= $line . "\n";
    }

    $stmts = [];
    foreach (explode(';', $buf) as $part) {
        $s = trim($part);
        if ($s !== '') {
            $stmts[] = $s . ';';
        }
    }
    return $stmts;
}

$schemaPath = realpath(__DIR__ . '/../sql/schema.sql') ?: (__DIR__ . '/../sql/schema.sql');

$cfg = DB::loadConfig();
try {
    $pdo = DB::pdo();
} catch (PDOException $e) {
    // 1049 = Unknown database
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1049) {
        ensure_database_exists($cfg);
        $pdo = DB::pdo();
    } else {
        throw $e;
    }
}

$sql = read_schema_file($schemaPath);
$stmts = split_sql_statements($sql);

foreach ($stmts as $stmt) {
    $pdo->exec($stmt);
}

fwrite(STDOUT, "OK: schema applied\n");
