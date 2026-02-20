<?php
declare(strict_types=1);

require __DIR__ . '/../DB.php';
require __DIR__ . '/../amf/AmfGateway.php';
require __DIR__ . '/../dao/OrgDao.php';

function build_current_org(PDO $pdo, int $userId, int $orgId): array
{
    $dao = new OrgDao($pdo);
    $row = $dao->getOne($userId, $orgId);
    if (!is_array($row)) {
        return [];
    }
    return [
        'id' => (int)($row['org_id'] ?? 0),
        'pid' => (int)($row['tpl_id'] ?? 151),
        'gr' => (int)($row['level'] ?? 1),
        'im' => (int)($row['quality'] ?? 1),
        'ex' => (int)($row['exp'] ?? 0),
        'hp' => (string)((int)($row['hp'] ?? 0)),
        'hm' => (string)((int)($row['hp_max'] ?? 0)),
        'at' => (string)((int)($row['attack'] ?? 0)),
        'mi' => (string)((int)($row['miss'] ?? 0)),
        'sp' => (string)((int)($row['speed'] ?? 0)),
        'pr' => (string)((int)($row['precision_val'] ?? 0)),
        'new_miss' => (string)((int)($row['new_miss'] ?? 0)),
        'new_precision' => (string)((int)($row['new_precision'] ?? 0)),
        'qu' => (string)($row['quality_name'] ?? '普通'),
        'ma' => (int)($row['mature'] ?? 1),
        'fight' => (string)((int)($row['fight'] ?? 0)),
    ];
}

/** @return array{missing:array<int,string>,type_mismatch:array<int,string>} */
function diff_keys(mixed $baseline, mixed $current, string $path = ''): array
{
    $missing = [];
    $typeMismatch = [];
    if (is_array($baseline)) {
        if (!is_array($current)) {
            $typeMismatch[] = $path === '' ? 'root' : $path;
            return ['missing' => $missing, 'type_mismatch' => $typeMismatch];
        }
        foreach ($baseline as $k => $v) {
            $p = $path === '' ? (string)$k : ($path . '.' . $k);
            if (!array_key_exists($k, $current)) {
                $missing[] = $p;
                continue;
            }
            $d = diff_keys($v, $current[$k], $p);
            $missing = array_merge($missing, $d['missing']);
            $typeMismatch = array_merge($typeMismatch, $d['type_mismatch']);
        }
        return ['missing' => $missing, 'type_mismatch' => $typeMismatch];
    }
    $bt = gettype($baseline);
    $ct = gettype($current);
    if ($bt !== $ct) {
        $typeMismatch[] = ($path === '' ? 'root' : $path) . ':' . $bt . '->' . $ct;
    }
    return ['missing' => $missing, 'type_mismatch' => $typeMismatch];
}

$capture = $argv[1] ?? '';
$userId = (int)($argv[2] ?? 0);
$orgId = (int)($argv[3] ?? 0);
if ($capture === '' || $userId <= 0 || $orgId <= 0) {
    fwrite(STDERR, "Usage: php validate_org_response.php <capture_rsp_amf> <user_id> <org_id>\n");
    exit(1);
}
if (!is_file($capture)) {
    fwrite(STDERR, "capture not found: {$capture}\n");
    exit(1);
}
$raw = file_get_contents($capture);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "read capture failed\n");
    exit(1);
}
$body = AmfGateway::extractFirstMessageBodyRaw($raw);
$r = new AmfByteReader($body);
$baseline = Amf0::readValueDecode($r);
$pdo = DB::pdo();
$current = build_current_org($pdo, $userId, $orgId);
$diff = diff_keys($baseline, $current);
$out = [
    'missing' => $diff['missing'],
    'type_mismatch' => $diff['type_mismatch'],
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
if ($out['missing'] !== [] || $out['type_mismatch'] !== []) {
    @file_put_contents(
        __DIR__ . '/../../../runtime/logs/org_mismatch.log',
        '[' . date('Y-m-d H:i:s') . '] validate ' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

