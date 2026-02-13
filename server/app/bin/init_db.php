<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';

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
$pdo = DB::pdo();

$sql = read_schema_file($schemaPath);
$stmts = split_sql_statements($sql);

foreach ($stmts as $stmt) {
    $pdo->exec($stmt);
}

fwrite(STDOUT, "OK: schema applied\n");

