<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/DB.php';
require_once __DIR__ . '/../app/core/SessionResolver.php';
require_once __DIR__ . '/../app/dao/OrgDao.php';

function removeskill_xml(string $status, string $error = ''): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<root><response><status>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</status>';
    if ($error !== '') {
        $xml .= '<error>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</error>';
    }
    $xml .= '</response></root>';
    return $xml;
}

function removeskill_log(string $line): void
{
    $path = __DIR__ . '/../../runtime/logs/removeskill.log';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** @return array{0:int,1:int} */
function removeskill_parse_ids(): array
{
    $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/pvz/(?:index\.php/+)?organism/removeskill/id/([^/]+)/organism_id/([^/]+)#', $uri, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    $skillId = (int)($_GET['id'] ?? 0);
    $orgId = (int)($_GET['organism_id'] ?? 0);
    return [$skillId, $orgId];
}

function removeskill_load_defs(string $file): array
{
    $path = __DIR__ . '/../../runtime/config/skills/' . $file;
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $byId = [];
    foreach ($arr as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
    }
    return $byId;
}

function removeskill_pending_path(int $uid, int $orgId): string
{
    $dir = __DIR__ . '/../../runtime/state/skill_replace';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/' . $uid . '_' . $orgId . '.json';
}

/** @return array<string,mixed> */
function removeskill_skills_assoc(array $skills): array
{
    $out = [];
    foreach ($skills as $k => $v) {
        $key = (string)$k;
        if ($key !== '' && (preg_match('/^\d+$/', $key) || str_starts_with($key, 'spec:'))) {
            $out[$key] = $v;
            continue;
        }
        if (is_array($v)) {
            $sid = (int)($v['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $isSpec = !empty($v['spec']) || !empty($v['is_spec']) || ((string)($v['type'] ?? '') === 'spec');
            $lk = $isSpec ? ('spec:' . $sid) : (string)$sid;
            $lv = max(1, (int)($v['level'] ?? $v['grade'] ?? 1));
            $out[$lk] = ['level' => $lv];
            continue;
        }
        if (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v))) {
            $sid = (int)$v;
            if ($sid > 0) {
                $out[(string)$sid] = ['level' => 1];
            }
        }
    }
    return $out;
}

header('Content-Type: application/xml; charset=utf-8');

[$skillId, $orgId] = removeskill_parse_ids();
$sig = (string)($_GET['sig'] ?? $_COOKIE['sig'] ?? '');
$sess = SessionResolver::resolveFromRequest($sig);
$uid = (int)($sess['user_id'] ?? 0);

if ($uid <= 0 || $orgId <= 0 || $skillId <= 0) {
    echo removeskill_xml('failed', 'invalid params');
    exit;
}

try {
    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $org = $dao->getOne($uid, $orgId);
    if (!is_array($org)) {
        echo removeskill_xml('failed', 'org not found');
        exit;
    }
    $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
    if (!is_array($skills)) {
        $skills = [];
    }
    $skills = removeskill_skills_assoc($skills);

    $oldKey = null;
    if (isset($skills[(string)$skillId])) {
        $oldKey = (string)$skillId;
    } elseif (isset($skills['spec:' . $skillId])) {
        $oldKey = 'spec:' . $skillId;
    } else {
        // Compatibility for legacy clients: allow pending when skill not found in json.
        $oldKey = (string)$skillId;
    }
    if (str_starts_with($oldKey, 'spec:')) {
        $v = $skills[$oldKey] ?? null;
        $lv = is_array($v) ? (int)($v['level'] ?? $v['grade'] ?? 0) : (int)$v;
        // EX replacement gate: only level 1 can be replaced.
        if ($lv > 1) {
            removeskill_log(sprintf('uid=%d org=%d skill=%d pending=0 reason=ex_level_limit lv=%d', $uid, $orgId, $skillId, $lv));
            echo removeskill_xml('failed', 'ex skill level too high');
            exit;
        }
    }
    $pending = [
        'skill_id' => $skillId,
        'old_key' => $oldKey,
        'ts' => time(),
    ];
    @file_put_contents(removeskill_pending_path($uid, $orgId), json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    removeskill_log(sprintf('uid=%d org=%d skill=%d pending=1', $uid, $orgId, $skillId));
    echo removeskill_xml('success');
} catch (Throwable $e) {
    removeskill_log(sprintf('uid=%d org=%d skill=%d error=%s', $uid, $orgId, $skillId, $e->getMessage()));
    echo removeskill_xml('failed', 'server error');
}
