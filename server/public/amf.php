<?php
declare(strict_types=1);
/**
 * AMF gateway entrypoint for local PVZ.
 *
 * Real traffic uses:
 *   POST /pvz/amf/
 *   Content-Type: application/x-amf
 *
 * Keep this file focused on AMF transport (decode/dispatch/encode).
 * Do not echo notices/warnings; AMF is binary.
 */

require_once __DIR__ . '/../app/amf/AmfGateway.php';
require_once __DIR__ . '/../app/DB.php';
require_once __DIR__ . '/../app/core/StateStore.php';
require_once __DIR__ . '/../app/core/SessionResolver.php';
require_once __DIR__ . '/../app/core/RequestContext.php';
require_once __DIR__ . '/../app/core/EventApplier.php';
require_once __DIR__ . '/../app/core/LootRoller.php';
require_once __DIR__ . '/../app/dao/OrgDao.php';

/**
 * Load a runtime JSON config as PHP value (array/object/scalar).
 * Returns null when missing or invalid.
 */
function amf_load_runtime_json(string $method, ?string $suffix = null): mixed
{
    $name = $suffix === null ? ($method . '.json') : ($method . '.' . $suffix . '.json');
    $cfgPath = __DIR__ . '/../../runtime/config/amf/' . $name;
    if (!is_file($cfgPath)) {
        return null;
    }
    $json = file_get_contents($cfgPath);
    if (!is_string($json) || $json === '') {
        return null;
    }
    // Some editors (and PowerShell) may write UTF-8 with BOM. json_decode treats BOM as syntax error.
    if (str_starts_with($json, "\xEF\xBB\xBF")) {
        $json = substr($json, 3);
    }
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $data;
}

/**
 * Decode first AMF message params and return the first param as int when possible.
 * This is used for endpoints like api.stone.getCaveInfo(chapId).
 */
function amf_extract_first_param_int(string $raw): ?int
{
    try {
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($raw);
        $r = new AmfByteReader($reqBody);
        $params = Amf0::readValueDecode($r);
        if (is_int($params)) {
            return $params;
        }
        if (is_float($params) && is_finite($params) && floor($params) === $params) {
            return (int)$params;
        }
        if (is_string($params) && preg_match('/^-?\d+$/', $params)) {
            return (int)$params;
        }
        if (is_array($params) && array_is_list($params) && count($params) > 0) {
            $v = $params[0];
            if (is_int($v)) {
                return $v;
            }
            if (is_float($v) && is_finite($v) && floor($v) === $v) {
                return (int)$v;
            }
            if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
                return (int)$v;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

/**
 * Extract an integer-like AMF param at a given index.
 *
 * Many Flash client calls look like: method(cmd:int, id:int|obj)
 * so index 0 is often the cmd, and index 1 is the real id/map.
 */
function amf_extract_param_int_at(string $raw, int $index): ?int
{
    try {
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($raw);
        $r = new AmfByteReader($reqBody);
        $params = Amf0::readValueDecode($r);

        $v = null;
        if (is_array($params) && array_is_list($params)) {
            $v = $params[$index] ?? null;
        } elseif ($index === 0) {
            $v = $params;
        }

        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v) {
            return (int)$v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            return (int)$v;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

function amf_extract_param_string_at(string $raw, int $index): ?string
{
    try {
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($raw);
        $r = new AmfByteReader($reqBody);
        $params = Amf0::readValueDecode($r);

        $v = null;
        if (is_array($params) && array_is_list($params)) {
            $v = $params[$index] ?? null;
        } elseif ($index === 0) {
            $v = $params;
        }
        if (is_string($v)) {
            return $v;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

/**
 * Decode first AMF body to PHP value (typically request params array).
 */
function amf_decode_first_params(string $raw): mixed
{
    try {
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($raw);
        $r = new AmfByteReader($reqBody);
        return Amf0::readValueDecode($r);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Try to find remoting identifiers from decoded params/raw bytes.
 * Priority: messageId -> clientId -> DSId -> rawHash.
 */
function amf_extract_replay_token(string $raw): string
{
    $params = amf_decode_first_params($raw);

    // Use per-request id first. clientId is often session-stable and would over-hit replay guard.
    $messageId = amf_find_string_field_recursive($params, ['messageid']);
    if ($messageId !== null && $messageId !== '') {
        return 'mid:' . strtolower($messageId);
    }

    // Binary fallback for message UUID embedded in body.
    if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}/', $raw, $m)) {
        return 'uuid:' . strtolower($m[0]);
    }

    // Final fallback: request body hash (same body within TTL is treated as replay).
    return 'raw:' . sha1($raw);
}

function amf_find_string_field_recursive(mixed $value, array $keys, int $depth = 0): ?string
{
    if ($depth > 8) {
        return null;
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $keys, true) && is_string($v) && $v !== '') {
                return $v;
            }
            $nested = amf_find_string_field_recursive($v, $keys, $depth + 1);
            if (is_string($nested) && $nested !== '') {
                return $nested;
            }
        }
    }
    return null;
}

function amf_server_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $path = realpath(__DIR__ . '/../config.json') ?: (__DIR__ . '/../config.json');
    if (!is_file($path)) {
        $cfg = [];
        return $cfg;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        $cfg = [];
        return $cfg;
    }
    $decoded = json_decode($raw, true);
    $cfg = is_array($decoded) ? $decoded : [];
    return $cfg;
}

/**
 * @return array{enabled:bool,ttl:int}
 */
function amf_openbox_replay_options(): array
{
    $cfg = amf_server_config();
    $enabled = true;
    $ttl = 5;
    if (isset($cfg['openbox']['replay_guard']) && is_array($cfg['openbox']['replay_guard'])) {
        $rg = $cfg['openbox']['replay_guard'];
        if (isset($rg['enabled'])) {
            $enabled = (bool)$rg['enabled'];
        }
        if (isset($rg['ttl_seconds'])) {
            $ttl = (int)$rg['ttl_seconds'];
        }
    }
    $ttl = max(2, min(5, $ttl));
    return ['enabled' => $enabled, 'ttl' => $ttl];
}

function amf_tool_use_debug_enabled(): bool
{
    return is_file(__DIR__ . '/../../runtime/config/enable_tool_use_debug.txt');
}

function amf_skill_replace_pending_path(int $uid, int $orgId): string
{
    $dir = __DIR__ . '/../../runtime/state/skill_replace';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/' . $uid . '_' . $orgId . '.json';
}

function amf_skill_replace_pending_get(int $uid, int $orgId): ?array
{
    $p = amf_skill_replace_pending_path($uid, $orgId);
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return null;
    }
    $ts = (int)($arr['ts'] ?? 0);
    if ($ts > 0 && (time() - $ts) > 300) {
        @unlink($p);
        return null;
    }
    return $arr;
}

function amf_skill_replace_pending_clear(int $uid, int $orgId): void
{
    $p = amf_skill_replace_pending_path($uid, $orgId);
    if (is_file($p)) {
        @unlink($p);
    }
}

/** @return array<string,mixed> */
function amf_remove_skill_from_assoc(array $skills, int $skillId): array
{
    if ($skillId <= 0) {
        return $skills;
    }
    $out = [];
    foreach ($skills as $k => $v) {
        $kk = (string)$k;
        if ($kk === (string)$skillId || $kk === ('spec:' . $skillId)) {
            continue;
        }
        $out[$kk] = $v;
    }
    return $out;
}

/**
 * @return array{itemId:int,count:int,targetId:int,extra:mixed,rawParams:mixed}
 */
function amf_parse_tool_use_input(string $raw): array
{
    $params = amf_decode_first_params($raw);
    $arr = is_array($params) && array_is_list($params) ? $params : [$params];
    $ints = [];
    foreach ($arr as $v) {
        if (is_int($v)) {
            $ints[] = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $ints[] = (int)$v;
        }
    }

    $itemId = 0;
    $count = 1;
    $targetId = 0;
    $extra = null;
    // Usually: [itemId,count] or [cmd,itemId,count,targetId,...]
    if (count($ints) >= 3 && $ints[0] >= 0 && $ints[0] <= 20 && $ints[1] > 0) {
        $itemId = max(0, $ints[1]);
        $count = max(1, $ints[2] ?? 1);
        $targetId = max(0, $ints[3] ?? 0);
    } elseif (count($ints) >= 2) {
        $itemId = max(0, $ints[0]);
        $count = max(1, $ints[1]);
        $targetId = max(0, $ints[2] ?? 0);
    } elseif (count($ints) === 1) {
        $itemId = max(0, $ints[0]);
    }
    if (count($arr) > 3) {
        $extra = array_slice($arr, 3);
    }

    return [
        'itemId' => $itemId,
        'count' => $count,
        'targetId' => $targetId,
        'extra' => $extra,
        'rawParams' => $params,
    ];
}

function amf_load_items_effects(): array
{
    $path = realpath(__DIR__ . '/../../runtime/config/items_effect.json')
        ?: (__DIR__ . '/../../runtime/config/items_effect.json');
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Apply loot roll and rewards without replay checks (caller transaction scope).
 * @return array{tools:array<int,array{id:int,amount:int}>,organisms:array,gold_delta:int,diamond_delta:int,exp_delta:int}
 */
function amf_apply_openbox_effect(PDO $pdo, int $userId, int $boxId, int $openCount): array
{
    $openCount = max(1, $openCount);
    $seed = crc32($userId . '|' . microtime(true) . '|' . $boxId . '|' . $openCount);
    $rolled = LootRoller::roll($boxId, $openCount, $seed);
    $rewards = is_array($rolled['rewards'] ?? null) ? $rolled['rewards'] : [];
    $goldDelta = (int)($rolled['goldDelta'] ?? 0);
    $diamondDelta = (int)($rolled['diamondDelta'] ?? 0);
    $expDelta = (int)($rolled['expDelta'] ?? 0);

    $toolRewards = [];
    foreach ($rewards as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $itemId = (int)($entry['itemId'] ?? 0);
        $cnt = (int)($entry['count'] ?? 0);
        if ($itemId <= 0 || $cnt <= 0) {
            continue;
        }
        amf_inventory_add($pdo, $userId, 'tool:' . $itemId, $cnt);
        $toolRewards[] = ['id' => $itemId, 'amount' => $cnt];
    }

    if ($goldDelta > 0) {
        amf_inventory_add($pdo, $userId, 'gold', $goldDelta);
    }
    if ($diamondDelta > 0) {
        amf_inventory_add($pdo, $userId, 'money', $diamondDelta);
    }
    if ($expDelta > 0) {
        $stateStore = new StateStore();
        $loaded = $stateStore->load($userId);
        $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
        $st = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
        $st['exp'] = (int)($st['exp'] ?? 0) + $expDelta;
        $stateStore->save($userId, $phase, $st);
    }

    return [
        'tools' => $toolRewards,
        'organisms' => [],
        'gold_delta' => $goldDelta,
        'diamond_delta' => $diamondDelta,
        'exp_delta' => $expDelta,
    ];
}

function amf_replay_guard_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS replay_guard (
          replay_key VARCHAR(191) NOT NULL,
          user_id BIGINT NOT NULL,
          api_name VARCHAR(64) NOT NULL,
          response_json JSON NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at DATETIME NOT NULL,
          PRIMARY KEY (replay_key),
          KEY idx_replay_guard_api_exp (api_name, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function amf_battle_session_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS battle_session (
            battle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT NOT NULL,
            source VARCHAR(32) NOT NULL,
            cave_id INT NOT NULL DEFAULT 0,
            seed VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'created',
            result_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (battle_id),
            KEY idx_battle_user (user_id),
            KEY idx_battle_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensured = true;
}

function amf_cave_seed(int $userId, string $ymd): string
{
    return sha1($userId . ':' . $ymd . ':cave');
}

function amf_cave_power_by_index(int $index, int $base): int
{
    $index = max(1, $index);
    $v = (float)$base;
    for ($i = 1; $i < $index; $i++) {
        $v *= 2.0;
        if ($v > (float)PHP_INT_MAX) {
            return PHP_INT_MAX;
        }
    }
    return (int)$v;
}

/** @return int[] */
function amf_cave_level_sequence_up_to(int $userLevel): array
{
    $userLevel = max(1, $userLevel);
    $seq = [];
    $offsets = [1, 3, 6, 9];
    for ($band = 0; $band < 200; $band++) {
        $base = $band * 10;
        foreach ($offsets as $o) {
            $lv = $base + $o;
            if ($lv > $userLevel) {
                return $seq === [] ? [1] : $seq;
            }
            $seq[] = $lv;
        }
    }
    return $seq === [] ? [1] : $seq;
}

/** @return array<int,array{id:int,name:string,difficulty:int,rewardTier:int,unlock_level:int,monster_level:int,monster_id:int,monster_orid:int,hp:int,atk:int}> */
function amf_cave_make_list(string $seed, int $count = 6, int $userLevel = 1): array
{
    $count = max(1, min(200, $count));
    $levels = amf_cave_level_sequence_up_to($userLevel);
    $rows = [];
    $n = min($count, count($levels));
    for ($i = 0; $i < $n; $i++) {
        $id = $i + 1;
        $lv = (int)$levels[$i];
        $hp = amf_cave_power_by_index($id, 5000);
        $atk = amf_cave_power_by_index($id, 1000);
        $rows[] = [
            'id' => (int)$id,
            'name' => 'Cave-Lv' . $lv,
            'difficulty' => $lv,
            'rewardTier' => 1 + ($i % 4),
            'unlock_level' => $lv,
            'monster_level' => $lv,
            // Cave enemy is a monster/zombie template, not a player org.
            'monster_id' => 3000 + (int)$id,
            'monster_orid' => 19,
            'hp' => $hp,
            'atk' => $atk,
        ];
    }
    return $rows;
}

/** @return array{state:array<string,mixed>,changed:bool} */
function amf_cave_state_prepare(int $userId, array $state): array
{
    $changed = false;
    $today = date('Y-m-d');
    $before = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $state = amf_state_prepare_gameplay_counters($state);
    $after = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($before !== $after) {
        $changed = true;
    }
    if (!isset($state['cave']) || !is_array($state['cave'])) {
        $state['cave'] = [];
        $changed = true;
    }
    $cave = &$state['cave'];
    if (!isset($state['counters']) || !is_array($state['counters'])) {
        $state['counters'] = [];
        $changed = true;
    }
    if (($cave['date'] ?? '') !== $today) {
        $cave['date'] = $today;
        unset($cave['seed'], $cave['list']);
        $changed = true;
    }
    $seed = (string)($cave['seed'] ?? '');
    if ($seed === '') {
        $seed = amf_cave_seed($userId, $today);
        $cave['seed'] = $seed;
        $changed = true;
    }
    $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
    $expected = amf_cave_make_list($seed, 200, $userLevel);
    if (!isset($cave['list']) || !is_array($cave['list']) || count($cave['list']) === 0) {
        $cave['list'] = $expected;
        $changed = true;
    } else {
        $sameCount = count($cave['list']) === count($expected);
        $tailOld = (array)end($cave['list']);
        $tailNew = (array)end($expected);
        $tailOldLv = (int)($tailOld['monster_level'] ?? 0);
        $tailNewLv = (int)($tailNew['monster_level'] ?? 0);
        if (!$sameCount || $tailOldLv !== $tailNewLv) {
            $cave['list'] = $expected;
            $changed = true;
        }
    }
    if (!is_array($cave['list']) || $cave['list'] === []) {
        $cave['list'] = $expected;
        $changed = true;
    }
    unset($cave);
    return ['state' => $state, 'changed' => $changed];
}

/** @return array<int,array{item_id:int,qty:int}> */
function amf_cave_reward_roll(int $caveId, string $seed): array
{
    $cfg = amf_load_cfg_json('cave_rewards.json');
    if (is_array($cfg) && isset($cfg[(string)$caveId]) && is_array($cfg[(string)$caveId])) {
        $rows = [];
        foreach ($cfg[(string)$caveId] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $iid = max(1, (int)($r['item_id'] ?? 0));
            $qty = max(1, (int)($r['qty'] ?? 1));
            $rows[] = ['item_id' => $iid, 'qty' => $qty];
        }
        if ($rows !== []) {
            return $rows;
        }
    }
    $base = 100 + ($caveId % 20);
    $qty = (abs(crc32($seed . ':' . $caveId)) % 3) + 1;
    return [['item_id' => $base, 'qty' => $qty]];
}

function amf_lottery_settle_by_battle(int $userId, int $battleId): array
{
    if ($userId <= 0) {
        return ['state' => 0, 'error' => 'invalid battle'];
    }
    $pdo = DB::pdo();
    amf_battle_session_ensure_table($pdo);
    if ($battleId <= 0) {
        // Real capture often sends api.reward.lottery with payload [" "].
        // Fallback to latest cave battle for this user.
        $q = $pdo->prepare(
            "SELECT battle_id
             FROM battle_session
             WHERE user_id=:uid AND source='cave'
             ORDER BY battle_id DESC
             LIMIT 1"
        );
        $q->execute([':uid' => $userId]);
        $latest = $q->fetch();
        $battleId = is_array($latest) ? (int)($latest['battle_id'] ?? 0) : 0;
        if ($battleId <= 0) {
            return ['state' => 0, 'error' => 'battle not found'];
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM battle_session WHERE battle_id=:bid AND user_id=:uid LIMIT 1');
    $stmt->execute([':bid' => $battleId, ':uid' => $userId]);
    $sess = $stmt->fetch();
    if (!is_array($sess)) {
        return ['state' => 0, 'error' => 'battle not found'];
    }
    $saved = $sess['result_json'] ?? null;
    $status = (string)($sess['status'] ?? '');
    if ($status === 'settled' && is_string($saved) && $saved !== '') {
        $decoded = json_decode($saved, true);
        if (is_array($decoded) && !empty($decoded['state'])) {
            return $decoded;
        }
    }
    $caveId = (int)($sess['cave_id'] ?? 0);
    $seed = (string)($sess['seed'] ?? '');
    $rewards = amf_cave_reward_roll($caveId, $seed);
    $pdo->beginTransaction();
    try {
        foreach ($rewards as $r) {
            $iid = max(1, (int)($r['item_id'] ?? 0));
            $qty = max(1, (int)($r['qty'] ?? 1));
            amf_inventory_add($pdo, $userId, 'tool:' . $iid, $qty);
            amf_inventory_delta_log($userId, 'tool:' . $iid, $qty, 'api.reward.lottery');
        }
        $goldGain = 100 + ($caveId * 10);
        amf_wallet_add($pdo, $userId, 'gold', $goldGain);
        amf_inventory_delta_log($userId, 'gold', $goldGain, 'api.reward.lottery');
        $tools = array_map(static function (array $r): array {
            return [
                'id' => (int)($r['item_id'] ?? 0),
                'amount' => (int)($r['qty'] ?? 0),
            ];
        }, $rewards);
        $list = array_map(static function (array $r): array {
            return [
                'prize_money' => 0,
                'type' => 'tool',
                'value' => (int)($r['item_id'] ?? 0),
            ];
        }, $rewards);
        $result = [
            'state' => 1,
            'battle_id' => $battleId,
            'rewards' => $rewards,
            // Real captures expose these two fields as primary reward payload.
            'list' => $list,
            'tools' => $tools,
            'organisms' => [],
            'prize_money' => $goldGain,
            'prize_exp' => 1,
            'up_grade' => [],
            'gold' => amf_wallet_get($pdo, $userId, 'gold'),
            'diamond' => amf_wallet_get($pdo, $userId, 'money'),
            'exp' => 0,
        ];
        $up = $pdo->prepare('UPDATE battle_session SET status=:st, result_json=:res WHERE battle_id=:bid');
        $up->execute([
            ':st' => 'settled',
            ':res' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':bid' => $battleId,
        ]);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['state' => 0, 'error' => $e->getMessage()];
    }
}

/** @return int[] */
function amf_cave_extract_selected_org_ids(string $raw): array
{
    $collect = static function (mixed $node, array &$out, int $depth = 0) use (&$collect): void {
        if ($depth > 10 || $node === null) {
            return;
        }
        if (is_array($node)) {
            if (array_is_list($node)) {
                // Pure numeric id list.
                if ($node !== []) {
                    $allNumeric = true;
                    foreach ($node as $v) {
                        if (
                            !is_int($v) &&
                            !(is_float($v) && is_finite($v) && floor($v) === $v) &&
                            !(is_string($v) && preg_match('/^-?\d+$/', $v))
                        ) {
                            $allNumeric = false;
                            break;
                        }
                    }
                    if ($allNumeric) {
                        foreach ($node as $v) {
                            $n = (int)$v;
                            if ($n > 0 && $n < 1000000) {
                                $out[$n] = true;
                            }
                        }
                        return;
                    }
                }
                foreach ($node as $v) {
                    $collect($v, $out, $depth + 1);
                }
                return;
            }
            foreach ($node as $k => $v) {
                $ks = is_string($k) ? strtolower($k) : '';
                if (
                    $ks === 'org' || $ks === 'orgs' || $ks === 'orgid' || $ks === 'orgids' ||
                    $ks === 'organism' || $ks === 'organisms' || $ks === 'plant' || $ks === 'plants'
                ) {
                    $collect($v, $out, $depth + 1);
                    continue;
                }
                // Object with id field.
                if (($ks === 'id' || $ks === 'org_id') && (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v)))) {
                    $n = (int)$v;
                    if ($n > 0 && $n < 1000000) {
                        $out[$n] = true;
                    }
                    continue;
                }
                $collect($v, $out, $depth + 1);
            }
        }
    };

    try {
        $params = amf_decode_first_params($raw);
        if (!is_array($params)) {
            return [];
        }
        // Primary shape in client:
        // [caveId:int, selectedOrgIds:Array, difficulty:int]
        // Prefer strict extraction from index 1 to avoid pulling unrelated ids.
        if (array_is_list($params) && isset($params[1])) {
            $sel = $params[1];
            if (is_array($sel)) {
                $strict = [];
                foreach ($sel as $v) {
                    if (is_int($v) && $v > 0 && $v < 1000000) {
                        $strict[$v] = true;
                        continue;
                    }
                    if (is_string($v) && preg_match('/^\d+$/', $v)) {
                        $n = (int)$v;
                        if ($n > 0 && $n < 1000000) {
                            $strict[$n] = true;
                        }
                    }
                }
                if ($strict !== []) {
                    return array_values(array_map('intval', array_keys($strict)));
                }
            }
        }
        $found = [];
        $collect($params, $found, 0);
        return array_values(array_map('intval', array_keys($found)));
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array{defenders:array<int,array<string,mixed>>, assailants:array<int,array<string,mixed>>, proceses:array<int,array<string,mixed>>, is_winning:bool, die_status:int, hp_after:array<int,int>} */
function amf_cave_simulate_battle_payload(
    int $userId,
    int $caveId,
    string $seed,
    array $mineRows,
    array $selectedOrgIds = [],
    ?array $caveMeta = null
): array {
    $selectedMap = [];
    foreach ($selectedOrgIds as $oid) {
        $selectedMap[(int)$oid] = true;
    }

    $fighters = [];
    foreach ($mineRows as $mr) {
        $orgId = (int)($mr['org_id'] ?? 0);
        if ($orgId <= 0) {
            continue;
        }
        // If selected lineup ids are present, strictly use them.
        // If not present, leave fighters empty and let the fallback choose one stable attacker.
        if ($selectedMap === []) {
            continue;
        }
        if (empty($selectedMap[$orgId])) {
            continue;
        }
        $hp = max(1, (int)($mr['hp'] ?? $mr['hp_max'] ?? 1));
        $hpMax = max($hp, (int)($mr['hp_max'] ?? $hp));
        $atk = max(10, (int)($mr['at'] ?? $mr['attack'] ?? 100));
        $spd = max(0, (int)($mr['sp'] ?? $mr['speed'] ?? 0));
        $grade = max(1, (int)($mr['grade'] ?? $mr['level'] ?? 1));
        $quality = max(1, (int)($mr['quality_id'] ?? $mr['quality'] ?? 1));
        $fighters[] = [
            'id' => $orgId,
            'orid' => (int)($mr['pid'] ?? $mr['tpl_id'] ?? 1),
            'hp' => $hp,
            'hp_max' => $hpMax,
            'atk' => $atk,
            'speed' => $spd,
            'grade' => $grade,
            'quality_id' => $quality,
            'side' => 'assailant',
        ];
    }
    if ($fighters === []) {
        // When request selected ids cannot be decoded, only use one fallback attacker.
        // Using the full warehouse list can reference non-deployed ids and freeze battle playback.
        usort($mineRows, static function (array $a, array $b): int {
            $ga = (int)($a['grade'] ?? $a['level'] ?? 1);
            $gb = (int)($b['grade'] ?? $b['level'] ?? 1);
            if ($ga !== $gb) {
                return $gb <=> $ga;
            }
            $ha = (int)($a['hp'] ?? $a['hp_max'] ?? 0);
            $hb = (int)($b['hp'] ?? $b['hp_max'] ?? 0);
            return $hb <=> $ha;
        });
        foreach ($mineRows as $mr) {
            $orgId = (int)($mr['org_id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            $hp = max(1, (int)($mr['hp'] ?? $mr['hp_max'] ?? 1));
            $hpMax = max($hp, (int)($mr['hp_max'] ?? $hp));
            $fighters[] = [
                'id' => $orgId,
                'orid' => (int)($mr['pid'] ?? $mr['tpl_id'] ?? 1),
                'hp' => $hp,
                'hp_max' => $hpMax,
                'atk' => max(10, (int)($mr['at'] ?? $mr['attack'] ?? 100)),
                'speed' => max(0, (int)($mr['sp'] ?? $mr['speed'] ?? 0)),
                'grade' => max(1, (int)($mr['grade'] ?? $mr['level'] ?? 1)),
                'quality_id' => max(1, (int)($mr['quality_id'] ?? $mr['quality'] ?? 1)),
                'side' => 'assailant',
            ];
            break;
        }
    }
    if ($fighters === []) {
        $fighters[] = [
            'id' => 1,
            'orid' => 1,
            'hp' => 500,
            'hp_max' => 500,
            'atk' => 100,
            'speed' => 0,
            'grade' => 1,
            'quality_id' => 1,
            'side' => 'assailant',
        ];
    }

    // Cave defender is a monster entry. Never reuse player org ids here.
    $enemyId = max(
        1,
        (int)($caveMeta['monster_id'] ?? $caveMeta['enemy_id'] ?? (3000 + $caveId))
    );
    $enemyOrid = max(1, (int)($caveMeta['monster_orid'] ?? $caveMeta['orid'] ?? 19));
    $monsterLevel = max(1, (int)($caveMeta['monster_level'] ?? $caveMeta['difficulty'] ?? $caveId));
    $enemyHpBase = (int)($caveMeta['hp'] ?? amf_cave_power_by_index(max(1, $caveId), 5000));
    $enemyAtkBase = (int)($caveMeta['atk'] ?? amf_cave_power_by_index(max(1, $caveId), 1000));
    $enemy = [
        'id' => $enemyId,
        'orid' => $enemyOrid,
        'hp_max' => max(1, $enemyHpBase),
        'hp' => max(1, $enemyHpBase),
        'atk' => max(1, $enemyAtkBase),
        'speed' => max(1, 40 + $monsterLevel * 6),
        'grade' => $monsterLevel,
        'quality_id' => 1,
        'side' => 'defender',
    ];

    $rand01 = static function (string $salt) use ($seed, $userId, $caveId): float {
        $h = abs(crc32($seed . ':' . $userId . ':' . $caveId . ':' . $salt));
        return ($h % 10000) / 10000.0;
    };

    $processes = [];
    $round = 0;
    while ($round < 30 && $enemy['hp'] > 0) {
        $round++;
        $alive = array_values(array_filter($fighters, static fn(array $f): bool => (int)$f['hp'] > 0));
        if ($alive === []) {
            break;
        }
        usort($alive, static function (array $a, array $b): int {
            $cmp = ((int)$b['speed']) <=> ((int)$a['speed']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        });
        $topSpeed = (int)$alive[0]['speed'];
        $enemyActsFirst = (int)$enemy['speed'] > $topSpeed;

        $appendEnemyHit = static function () use (&$fighters, &$enemy, &$processes, $round, $rand01): void {
            if ((int)$enemy['hp'] <= 0) {
                return;
            }
            $aliveTargets = array_values(array_filter($fighters, static fn(array $f): bool => (int)$f['hp'] > 0));
            if ($aliveTargets === []) {
                return;
            }
            usort($aliveTargets, static function (array $a, array $b): int {
                $cmp = ((int)$b['speed']) <=> ((int)$a['speed']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ((int)$a['id']) <=> ((int)$b['id']);
            });
            $targetId = (int)$aliveTargets[0]['id'];
            $targetIdx = null;
            foreach ($fighters as $k => $f) {
                if ((int)$f['id'] === $targetId) {
                    $targetIdx = $k;
                    break;
                }
            }
            if ($targetIdx === null) {
                return;
            }
            $coef = 0.80 + ($rand01('e:' . $round . ':' . $targetId) * 0.30);
            $atk = max(1, (int)floor(((int)$enemy['atk']) * $coef));
            $oldHp = (int)$fighters[$targetIdx]['hp'];
            $newHp = max(0, $oldHp - $atk);
            $fighters[$targetIdx]['hp'] = $newHp;
            $processes[] = [
                'skills' => [],
                'exclusiveSkills' => [],
                'talentSkills' => [],
                'defenders' => [[
                    'is_fear' => '-1',
                    'attack' => (string)$atk,
                    'boutCount' => (string)$round,
                    'hp' => (string)$newHp,
                    'old_hp' => (string)$oldHp,
                    'id' => (string)$targetId,
                    'is_dodge' => '0',
                    'normal_attack' => (string)$atk,
                ]],
                'assailant' => ['id' => (string)$enemy['id'], 'type' => 'defender'],
                'spec_buffs' => [],
            ];
        };

        if ($enemyActsFirst) {
            $appendEnemyHit();
            $alive = array_values(array_filter($fighters, static fn(array $f): bool => (int)$f['hp'] > 0));
            if ($alive === []) {
                break;
            }
            usort($alive, static function (array $a, array $b): int {
                $cmp = ((int)$b['speed']) <=> ((int)$a['speed']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ((int)$a['id']) <=> ((int)$b['id']);
            });
        }

        foreach ($alive as $act) {
            if ($enemy['hp'] <= 0) {
                break;
            }
            $aid = (int)$act['id'];
            $coef = 0.85 + ($rand01('a:' . $round . ':' . $aid) * 0.35);
            $atk = max(1, (int)floor(((int)$act['atk']) * $coef));
            $oldHp = (int)$enemy['hp'];
            $newHp = max(0, $oldHp - $atk);
            $enemy['hp'] = $newHp;
            $processes[] = [
                'skills' => [],
                'exclusiveSkills' => [],
                'talentSkills' => [],
                'defenders' => [[
                    'is_fear' => '-1',
                    'attack' => (string)$atk,
                    'boutCount' => (string)$round,
                    'hp' => (string)$newHp,
                    'old_hp' => (string)$oldHp,
                    'id' => (string)$enemy['id'],
                    'is_dodge' => '0',
                    'normal_attack' => (string)$atk,
                ]],
                'assailant' => ['id' => (string)$aid, 'type' => 'assailant'],
                'spec_buffs' => [],
            ];
        }

        if (!$enemyActsFirst && $enemy['hp'] > 0) {
            $appendEnemyHit();
        }
    }

    $hpAfter = [];
    foreach ($fighters as $f) {
        $hpAfter[(int)$f['id']] = max(0, (int)$f['hp']);
    }

    $respAssailants = [];
    foreach ($fighters as $f) {
        $respAssailants[] = [
            'hp_max' => (string)$f['hp_max'],
            'grade' => (string)$f['grade'],
            'quality_id' => (float)$f['quality_id'],
            'hp' => (string)max(0, (int)$f['hp']),
            'orid' => (string)$f['orid'],
            'id' => (string)$f['id'],
        ];
    }

    return [
        'defenders' => [[
            'hp_max' => (string)$enemy['hp_max'],
            'grade' => (string)$enemy['grade'],
            'quality_id' => (float)$enemy['quality_id'],
            'hp' => (string)max(0, (int)$enemy['hp']),
            'orid' => (string)$enemy['orid'],
            'id' => (string)$enemy['id'],
            'attack' => (string)$enemy['atk'],
            'miss' => '0',
            'precision' => '0',
            'new_miss' => '0',
            'new_precision' => '0',
            'speed' => (string)$enemy['speed'],
        ]],
        'proceses' => $processes,
        'is_winning' => (int)$enemy['hp'] <= 0,
        'die_status' => ((int)$enemy['hp'] <= 0 ? 1 : 0),
        'assailants' => $respAssailants,
        'hp_after' => $hpAfter,
    ];
}

function amf_replay_guard_get(PDO $pdo, string $replayKey, string $apiName): mixed
{
    $gc = $pdo->prepare('DELETE FROM replay_guard WHERE expires_at <= NOW()');
    $gc->execute();

    $stmt = $pdo->prepare(
        'SELECT response_json
         FROM replay_guard
         WHERE replay_key = :k AND api_name = :a AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':k' => $replayKey, ':a' => $apiName]);
    $row = $stmt->fetch();
    if (!is_array($row) || !is_string($row['response_json'] ?? null) || $row['response_json'] === '') {
        return null;
    }
    return json_decode($row['response_json'], true);
}

function amf_replay_guard_put(PDO $pdo, string $replayKey, int $userId, string $apiName, mixed $response, int $ttlSeconds = 5): void
{
    $ttlSeconds = max(2, min(5, $ttlSeconds));
    $sql = <<<SQL
INSERT INTO replay_guard (replay_key, user_id, api_name, response_json, created_at, expires_at)
VALUES (:k, :uid, :api, :resp, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND))
ON DUPLICATE KEY UPDATE
  response_json = VALUES(response_json),
  expires_at = VALUES(expires_at)
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':k' => $replayKey,
        ':uid' => $userId,
        ':api' => $apiName,
        ':resp' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ttl' => $ttlSeconds,
    ]);
}

/**
 * Resolve sig in priority:
 * 1) GET/POST sig
 * 2) cookie sig
 * 3) empty string fallback
 */
function amf_resolve_sig(): string
{
    $sig = (string)($_GET['sig'] ?? '');
    if ($sig === '') {
        $sig = (string)($_POST['sig'] ?? '');
    }
    if ($sig === '') {
        $sig = (string)($_COOKIE['sig'] ?? '');
    }
    // Relaxed whitelist for future sig formats (e.g. base64url-like tokens).
    if ($sig !== '' && preg_match('/^[A-Za-z0-9_-]{6,128}$/', $sig)) {
        return strtolower($sig);
    }
    return '';
}

function amf_json_decode_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if (!is_string($json) || $json === '') {
        return null;
    }
    // Handle UTF-8 BOM (json_decode treats BOM as syntax error).
    if (str_starts_with($json, "\xEF\xBB\xBF")) {
        $json = substr($json, 3);
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

function amf_missing_config_log(string $apiName, array $payload): void
{
    $ctx = RequestContext::get();
    $line = sprintf(
        '[%s] api=%s user_id=%d sig=%s payload=%s',
        date('Y-m-d H:i:s'),
        $apiName,
        (int)($ctx['user_id'] ?? 0),
        (string)($ctx['sig'] ?? ''),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    amf_append_log_line(__DIR__ . '/../../runtime/logs/missing_config.log', $line);
}

function amf_load_cfg_json(string $relativePath): mixed
{
    $path = realpath(__DIR__ . '/../../runtime/config/' . $relativePath) ?: (__DIR__ . '/../../runtime/config/' . $relativePath);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    return json_decode($raw, true);
}

function amf_lang_load(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    $primaryRoot = __DIR__ . '/config/lang';
    $fallbackRoot = dirname(__DIR__, 4) . '/cache/youkia/config/lang';
    $files = [
        $primaryRoot . '/server_cn.xml',
        $primaryRoot . '/language_cn.xml',
        $primaryRoot . '/genius_cn.xml',
        $primaryRoot . '/copy_cn.xml',
        $fallbackRoot . '/server_cn.xml',
        $fallbackRoot . '/language_cn.xml',
        $fallbackRoot . '/genius_cn.xml',
        $fallbackRoot . '/copy_cn.xml',
    ];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        if (preg_match_all('/<item\\s+name=\"([^\"]+)\"\\s*>(.*?)<\\/item>/su', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = trim((string)$row[1]);
                $v = trim(html_entity_decode((string)$row[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($k !== '' && $v !== '') {
                    $map[$k] = $v;
                }
            }
        }
    }
    return $map;
}

function amf_lang_get(string $key, string $fallback = ''): string
{
    $map = amf_lang_load();
    if (isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '') {
        return $map[$key];
    }
    return $fallback !== '' ? $fallback : $key;
}

function amf_lang_fmt(string $key, array $args = [], string $fallback = ''): string
{
    $s = amf_lang_get($key, $fallback);
    foreach ($args as $i => $v) {
        $s = str_replace('%' . ((int)$i + 1), (string)$v, $s);
    }
    return $s;
}

function amf_capture_with_sig(string $sig, string $filename): string|false
{
    if ($sig !== '' && preg_match('/^[A-Za-z0-9_-]{6,128}$/', $sig)) {
        $p = realpath(__DIR__ . '/../../../../file/real_amf/pure/' . strtolower($sig) . '/' . $filename);
        if ($p !== false) {
            return $p;
        }
    }
    return false;
}

function amf_trace_id(): string
{
    return strtoupper(bin2hex(random_bytes(8)));
}

function amf_log_error(string $apiName, string $sig, int $userId, string $traceId, Throwable $e): void
{
    $line = sprintf(
        '[%s] trace=%s api=%s sig=%s uid=%d err=%s msg=%s',
        date('Y-m-d H:i:s'),
        $traceId,
        $apiName,
        $sig,
        $userId,
        get_class($e),
        $e->getMessage()
    );
    amf_append_log_line(__DIR__ . '/../../runtime/logs/amf_error.log', $line);
}

function amf_runtime_error_payload(string $traceId, string $desc = 'runtime error'): array
{
    return [
        'code' => 'AMFPHP_RUNTIME_ERROR',
        'description' => $desc,
        'trace_id' => $traceId,
        'state' => 0,
    ];
}

function amf_txn_log(
    int $userId,
    string $api,
    string $orderKey,
    array $delta,
    array $items,
    float $startTs,
    bool $idempotent
): void {
    $costMs = (int)round((microtime(true) - $startTs) * 1000);
    $line = sprintf(
        '[%s] uid=%d api=%s key=%s idem=%d ms=%d delta=%s items=%s',
        date('Y-m-d H:i:s'),
        $userId,
        $api,
        $orderKey,
        $idempotent ? 1 : 0,
        $costMs,
        json_encode($delta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    amf_append_log_line(__DIR__ . '/../../runtime/logs/txn.log', $line);
}

function amf_compose_log(string $api, int $uid, int $orgId, array $input, array $consume, array $result, float $startTs): void
{
    $costMs = (int)round((microtime(true) - $startTs) * 1000);
    $line = sprintf(
        '[%s] api=%s uid=%d org=%d ms=%d input=%s consume=%s result=%s',
        date('Y-m-d H:i:s'),
        $api,
        $uid,
        $orgId,
        $costMs,
        json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($consume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    amf_append_log_line(__DIR__ . '/../../runtime/logs/compose_txn.log', $line);
}

function amf_inventory_delta_log(int $uid, string $itemId, int $delta, string $sourceApi): void
{
    $line = sprintf('[%s] uid=%d item=%s delta=%d api=%s', date('Y-m-d H:i:s'), $uid, $itemId, $delta, $sourceApi);
    amf_append_log_line(__DIR__ . '/../../runtime/logs/inventory_delta.log', $line);
}

function amf_org_delta_log(int $uid, int $orgId, array $before, array $after, string $sourceApi): void
{
    $patch = [];
    foreach ($after as $k => $v) {
        if (($before[$k] ?? null) !== $v) {
            $patch[$k] = ['before' => $before[$k] ?? null, 'after' => $v];
        }
    }
    if ($patch === []) {
        return;
    }
    $line = sprintf(
        '[%s] uid=%d org=%d api=%s patch=%s',
        date('Y-m-d H:i:s'),
        $uid,
        $orgId,
        $sourceApi,
        json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    amf_append_log_line(__DIR__ . '/../../runtime/logs/org_delta.log', $line);
}

function amf_load_evolution_cost(): array
{
    $p = __DIR__ . '/../../runtime/config/evolution_cost.json';
    $data = amf_json_decode_file($p);
    return is_array($data) ? $data : [];
}

/** @return array{gold:int,diamond:int,materials:array<int,array{id:int,qty:int}>,success:int,state:int} */
function amf_evolution_cost_for_org(array $org): array
{
    $cfg = amf_load_evolution_cost();
    $tpl = (string)((int)($org['tpl_id'] ?? 0));
    $q = (int)($org['quality'] ?? 1);
    $lv = (int)($org['level'] ?? 1);
    $node = is_array($cfg[$tpl] ?? null) ? $cfg[$tpl] : [];
    $qk = (string)$q;
    if (is_array($node[$qk] ?? null)) {
        $node = $node[$qk];
    }
    $materials = [];
    if (isset($node['materials']) && is_array($node['materials'])) {
        foreach ($node['materials'] as $m) {
            if (!is_array($m)) continue;
            $mid = max(1, (int)($m['id'] ?? 0));
            $qty = max(1, (int)($m['qty'] ?? 1));
            $materials[] = ['id' => $mid, 'qty' => $qty];
        }
    } else {
        $materials[] = ['id' => 79, 'qty' => max(1, $q)];
    }
    return [
        'gold' => max(0, (int)($node['gold'] ?? ($q * 1000 + $lv * 10))),
        'diamond' => max(0, (int)($node['diamond'] ?? 0)),
        'materials' => $materials,
        'success' => 1,
        'state' => 1,
    ];
}

/** @return array<int,array<int,int>> tpl_id => [grade => target_tpl] */
function amf_load_evolution_target_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    $path = realpath(__DIR__ . '/php_xml/organism.xml') ?: (__DIR__ . '/php_xml/organism.xml');
    if (!is_file($path)) {
        return $map;
    }
    $xml = file_get_contents($path);
    if (!is_string($xml) || $xml === '') {
        return $map;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        return $map;
    }
    foreach (($sx->xpath('//item') ?: []) as $item) {
        $tpl = (int)($item['id'] ?? 0);
        if ($tpl <= 0 || !isset($item->evolutions->item)) {
            continue;
        }
        foreach ($item->evolutions->item as $ev) {
            $grade = (int)($ev['grade'] ?? 0);
            $target = (int)($ev['target'] ?? 0);
            if ($grade <= 0 || $target <= 0) {
                continue;
            }
            if (!isset($map[$tpl])) {
                $map[$tpl] = [];
            }
            $map[$tpl][$grade] = $target;
        }
        if (isset($map[$tpl])) {
            ksort($map[$tpl], SORT_NUMERIC);
        }
    }
    return $map;
}

function amf_resolve_evolved_tpl_id(int $tplId, int $grade): int
{
    $tpl = max(1, $tplId);
    $g = max(1, $grade);
    $map = amf_load_evolution_target_map();
    // Follow chain (A->B->C) if multiple grade thresholds are reached.
    for ($i = 0; $i < 16; $i++) {
        if (!isset($map[$tpl]) || !is_array($map[$tpl])) {
            break;
        }
        $next = $tpl;
        foreach ($map[$tpl] as $needGrade => $targetTpl) {
            if ($g >= (int)$needGrade) {
                $next = (int)$targetTpl;
            } else {
                break;
            }
        }
        if ($next <= 0 || $next === $tpl) {
            break;
        }
        $tpl = $next;
    }
    return $tpl;
}

/** @return array<int,array{id:int,qty:int}> */
function amf_extract_materials_from_params(mixed $params, int $fallbackToolId = 79, int $fallbackCount = 1): array
{
    $out = [];
    if (is_array($params) && array_is_list($params)) {
        foreach ($params as $v) {
            if (is_array($v) && isset($v['id'])) {
                $id = (int)($v['id'] ?? 0);
                $qty = max(1, (int)($v['qty'] ?? $v['num'] ?? 1));
                if ($id > 0) {
                    $out[] = ['id' => $id, 'qty' => $qty];
                }
            }
        }
    }
    if ($out === []) {
        $out[] = ['id' => max(1, $fallbackToolId), 'qty' => max(1, $fallbackCount)];
    }
    return $out;
}

function amf_order_find(PDO $pdo, string $orderKey): ?array
{
    $stmt = $pdo->prepare('SELECT response_json FROM shop_orders WHERE order_key = :k LIMIT 1');
    $stmt->execute([':k' => $orderKey]);
    $old = $stmt->fetch();
    if (is_array($old) && is_string($old['response_json'] ?? null)) {
        $cached = json_decode($old['response_json'], true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    return null;
}

function amf_order_save(PDO $pdo, string $orderKey, int $uid, string $api, array $request, array $response): void
{
    $insert = $pdo->prepare(
        'INSERT INTO shop_orders (order_key,user_id,api_name,request_json,response_json) VALUES (:k,:u,:a,:rq,:rs)'
    );
    $insert->execute([
        ':k' => $orderKey,
        ':u' => $uid,
        ':a' => $api,
        ':rq' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':rs' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function amf_inventory_get_qty(PDO $pdo, int $userId, string $itemId): int
{
    $stmt = $pdo->prepare('SELECT qty FROM inventory WHERE user_id = :uid AND item_id = :iid LIMIT 1');
    $stmt->execute([':uid' => $userId, ':iid' => $itemId]);
    $row = $stmt->fetch();
    return is_array($row) ? (int)($row['qty'] ?? 0) : 0;
}

function amf_inventory_add(PDO $pdo, int $userId, string $itemId, int $qty): void
{
    if ($qty <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO inventory (user_id,item_id,qty) VALUES (:uid,:iid,:qty)
         ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':uid' => $userId, ':iid' => $itemId, ':qty' => $qty]);
}

function amf_inventory_remove(PDO $pdo, int $userId, string $itemId, int $qty): bool
{
    if ($qty <= 0) {
        return true;
    }
    $current = amf_inventory_get_qty($pdo, $userId, $itemId);
    if ($current < $qty) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE inventory SET qty = qty - :qty_sub, updated_at = CURRENT_TIMESTAMP
         WHERE user_id = :uid AND item_id = :iid AND qty >= :qty_chk'
    );
    $stmt->execute([
        ':qty_sub' => $qty,
        ':qty_chk' => $qty,
        ':uid' => $userId,
        ':iid' => $itemId,
    ]);
    return $stmt->rowCount() > 0;
}

function amf_wallet_get(PDO $pdo, int $userId, string $currency): int
{
    return amf_inventory_get_qty($pdo, $userId, $currency);
}

function amf_wallet_deduct(PDO $pdo, int $userId, string $currency, int $amount): bool
{
    $ok = amf_inventory_remove($pdo, $userId, $currency, $amount);
    if ($ok && $userId > 0 && $amount > 0 && $currency === 'money') {
        try {
            amf_currency_spend_add($pdo, $userId, 'money', $amount);
            amf_active_daily_spend_add($userId, $amount);
        } catch (Throwable $e) {
            // non-fatal: spend succeeded even if stat tracking failed
        }
    }
    return $ok;
}

function amf_ensure_currency_spend_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    // Never run DDL inside an active business transaction.
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS currency_spend_stat (
            user_id BIGINT NOT NULL,
            currency VARCHAR(32) NOT NULL,
            total_spent BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, currency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensured = true;
}

function amf_currency_spend_add(PDO $pdo, int $userId, string $currency, int $delta): void
{
    if ($userId <= 0 || $delta <= 0) {
        return;
    }
    amf_ensure_currency_spend_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO currency_spend_stat (user_id, currency, total_spent)
         VALUES (:uid, :cur, :d)
         ON DUPLICATE KEY UPDATE total_spent = total_spent + VALUES(total_spent), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':cur' => $currency,
        ':d' => $delta,
    ]);
}

function amf_currency_spend_get(PDO $pdo, int $userId, string $currency): int
{
    if ($userId <= 0) {
        return 0;
    }
    amf_ensure_currency_spend_table($pdo);
    $stmt = $pdo->prepare('SELECT total_spent FROM currency_spend_stat WHERE user_id = :uid AND currency = :cur LIMIT 1');
    $stmt->execute([
        ':uid' => $userId,
        ':cur' => $currency,
    ]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return max(0, (int)($row['total_spent'] ?? 0));
    }

    // Backward compatibility: migrate old counter from player_state on first read.
    if ($currency === 'money') {
        $state = amf_state_normalize(amf_shop_state_load($userId));
        $legacy = max(0, (int)($state['counters']['money_spent_total'] ?? 0));
        if ($legacy > 0) {
            amf_currency_spend_add($pdo, $userId, $currency, $legacy);
            return $legacy;
        }
    }
    return 0;
}

function amf_active_daily_spend_add(int $userId, int $amount): void
{
    if ($userId <= 0 || $amount <= 0) {
        return;
    }
    $state = amf_state_normalize(amf_shop_state_load($userId));
    $active = amf_state_prepare_active_daily($state);
    $state['active']['spent_today'] = max(0, (int)($active['spent_today'] ?? 0)) + $amount;
    amf_shop_state_save($userId, $state);
}

function amf_active_effective_spent_today(int $userId, array &$state): int
{
    if ($userId <= 0) {
        return 0;
    }
    $state = amf_state_normalize($state);
    $active = amf_state_prepare_active_daily($state);
    $pdo = DB::pdo();
    $total = max(0, (int)amf_currency_spend_get($pdo, $userId, 'money'));
    $base = max(0, (int)($active['spent_base_total'] ?? 0));
    $date = (string)($active['date'] ?? '');
    $today = date('Y-m-d');
    if ($date !== $today || $base > $total) {
        $base = $total;
        $state['active']['spent_base_total'] = $base;
        $state['active']['date'] = $today;
    }
    $spent = max(0, $total - $base);
    $state['active']['spent_today'] = $spent;
    return $spent;
}

function amf_item_sell_price(int $itemId): int
{
    if ($itemId <= 0) {
        return 1;
    }
    // Explicit fix requested: item 30 should sell for 500000 gold.
    if ($itemId === 30) {
        return 500000;
    }
    $path = realpath(__DIR__ . '/../../runtime/config/items.json')
        ?: (__DIR__ . '/../../runtime/config/items.json');
    if (!is_file($path)) {
        return 1;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return 1;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return 1;
    }
    $node = $data[(string)$itemId] ?? null;
    if (!is_array($node)) {
        return 1;
    }
    return max(1, (int)($node['sell_price'] ?? 1));
}

function amf_currency_normalize(string $currency): string
{
    $c = strtolower(trim($currency));
    if ($c === '2' || $c === 'money' || $c === 'rmb' || $c === 'rmb_money' || $c === 'diamond') {
        return 'money';
    }
    if ($c === '1' || $c === 'gold' || $c === 'coin') {
        return 'gold';
    }
    return $c === '' ? 'gold' : $c;
}

function amf_exchange_item_id_from_raw(string $raw): string
{
    $v = trim($raw);
    if ($v === '') {
        return 'gold';
    }
    if (str_starts_with($v, 'tool:')) {
        return $v;
    }
    if (preg_match('/^\d+$/', $v)) {
        return 'tool:' . ((int)$v);
    }
    return amf_currency_normalize($v);
}

function amf_exchange_balance_get(PDO $pdo, int $userId, string $exchangeRaw): int
{
    $key = amf_exchange_item_id_from_raw($exchangeRaw);
    if ($key === 'gold' || $key === 'money') {
        return amf_wallet_get($pdo, $userId, $key);
    }
    $qty = amf_inventory_get_qty($pdo, $userId, $key);
    if ($qty > 0) {
        return $qty;
    }
    // Backward-compat if some old rows were stored without "tool:" prefix.
    if (str_starts_with($key, 'tool:')) {
        return amf_inventory_get_qty($pdo, $userId, substr($key, 5));
    }
    return 0;
}

function amf_exchange_deduct(PDO $pdo, int $userId, string $exchangeRaw, int $amount): bool
{
    if ($amount <= 0) {
        return true;
    }
    $key = amf_exchange_item_id_from_raw($exchangeRaw);
    if ($key === 'gold' || $key === 'money') {
        return amf_wallet_deduct($pdo, $userId, $key, $amount);
    }
    if (amf_inventory_remove($pdo, $userId, $key, $amount)) {
        return true;
    }
    if (str_starts_with($key, 'tool:')) {
        return amf_inventory_remove($pdo, $userId, substr($key, 5), $amount);
    }
    return false;
}

function amf_organism_ensure_table(PDO $pdo): void
{
    (new OrgDao($pdo))->ensureTable();
}

function amf_organism_seed_default(PDO $pdo, int $userId): void
{
    if ($userId <= 0) return;
    $dao = new OrgDao($pdo);
    $dao->seedFromWarehouseTemplate($userId);
}

/** @return array<int,array<string,mixed>> */
function amf_organism_list(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return [];
    $dao = new OrgDao($pdo);
    $dao->seedFromWarehouseTemplate($userId);
    $rows = $dao->getByUser($userId);
    foreach ($rows as $i => $row) {
        $rows[$i] = amf_sync_skill_xml_from_json($pdo, $userId, $row);
    }
    return $rows;
}

function amf_organism_get(PDO $pdo, int $userId, int $orgId): ?array
{
    if ($userId <= 0 || $orgId <= 0) return null;
    $dao = new OrgDao($pdo);
    $dao->seedFromWarehouseTemplate($userId);
    $row = $dao->getOne($userId, $orgId);
    if (!is_array($row)) return null;
    return amf_sync_skill_xml_from_json($pdo, $userId, $row);
}

function amf_organism_max_hp(array $org): int
{
    $hm = (int)($org['hp_max'] ?? 0);
    if ($hm > 0) return $hm;
    $lv = max(1, (int)($org['level'] ?? 1));
    $quality = max(1, (int)($org['quality'] ?? 1));
    return max(100, $lv * 100 + $quality * 50);
}

/** @return array{hp:int,attack:int,speed:int,miss:int,precision:int} */
function amf_parse_add_xml(string $xml): array
{
    $out = ['hp' => 0, 'attack' => 0, 'speed' => 0, 'miss' => 0, 'precision' => 0];
    $xml = trim($xml);
    if ($xml === '') {
        return $out;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        return $out;
    }
    $out['hp'] = (int)($sx['hp'] ?? 0);
    $out['attack'] = (int)($sx['attack'] ?? 0);
    $out['speed'] = (int)($sx['speed'] ?? 0);
    $out['miss'] = (int)($sx['miss'] ?? 0);
    $out['precision'] = (int)($sx['precision'] ?? 0);
    return $out;
}

/**
 * Recompute organism core stats from growth/level/quality.
 *
 * Rule (server simplified): base + growth * level * qualityCoef
 * - qualityCoef: 1 + (quality-1)*0.05
 * - HP keeps same magnitude as other base stats (no x100 explosion)
 * - fight follows client-side formula in entity/Organism.as:
 *   ceil(attack/12 + hp_max/48 + precision/16 + miss/16 + skillsE())
 *
 * @return array<string,int>
 */
function amf_recompute_org_stats(array $org): array
{
    $level = max(1, (int)($org['level'] ?? 1));
    $quality = max(1, (int)($org['quality'] ?? 1));
    $growth = max(1, (int)($org['mature'] ?? $org['pullulation'] ?? 1));
    // Source-aligned quality coefficient:
    // manager/QualityManager.as::getQualityPullulateQue
    if ($quality >= 1 && $quality <= 12) {
        $coef = 1.0 + ($quality - 1) * 0.05;
    } elseif ($quality === 13) {
        $coef = 1.65;
    } elseif ($quality > 17) {
        $coef = 2.6;
    } elseif ($quality > 16) {
        $coef = 2.35;
    } elseif ($quality > 15) {
        $coef = 2.2;
    } elseif ($quality > 13) {
        $coef = 1.5 + 0.15 * (2 ** ($quality - 13));
    } else {
        $coef = 1.0;
    }

    $baseAtk = 100;
    $baseMiss = 100;
    $basePrecision = 100;
    $baseSpeed = 100;
    $baseHp = 100;

    // Source behavior: final display values add compose/genius/soul contributions.
    // Keep morph channel disabled until we persist a dedicated morph field, to avoid
    // duplicating tal_add as a second additive source.
    $talAdd = amf_parse_add_xml((string)($org['tal_add_xml'] ?? ''));
    $soulAdd = amf_parse_add_xml((string)($org['soul_add_xml'] ?? ''));
    $morphAdd = ['hp' => 0, 'attack' => 0, 'speed' => 0, 'miss' => 0, 'precision' => 0];

    $core = (int)floor($growth * $level * $coef);
    $atk = max(1, $baseAtk + $core + $talAdd['attack'] + $soulAdd['attack'] + $morphAdd['attack']);
    $miss = max(1, $baseMiss + $core + $talAdd['miss'] + $soulAdd['miss'] + $morphAdd['miss']);
    $precision = max(1, $basePrecision + $core + $talAdd['precision'] + $soulAdd['precision'] + $morphAdd['precision']);
    $hpMax = max(1, $baseHp + $core + $talAdd['hp'] + $soulAdd['hp'] + $morphAdd['hp']);
    $speed = max(1, $baseSpeed + $talAdd['speed'] + $soulAdd['speed'] + $morphAdd['speed']);

    // Keep current HP ratio when recomputing cap.
    $oldHpMax = max(1, (int)($org['hp_max'] ?? $hpMax));
    $oldHp = max(0, (int)($org['hp'] ?? $hpMax));
    $hpRatio = min(1.0, max(0.0, $oldHp / $oldHpMax));
    $hp = max(1, (int)floor($hpMax * $hpRatio));

    $newMiss = max(1, (int)floor($miss));
    $newPrecision = max(1, (int)floor($precision));
    // Source-aligned fight extra term:
    // entity/Organism.as::skillsE => sum(pow(skill.grade,2.3) * 2)
    $skillsE = 0.0;
    $skillsRaw = $org['skills_json'] ?? '[]';
    $skills = is_array($skillsRaw) ? $skillsRaw : json_decode((string)$skillsRaw, true);
    if (is_array($skills)) {
        foreach ($skills as $v) {
            $lv = 0;
            if (is_array($v)) {
                $lv = (int)($v['grade'] ?? $v['level'] ?? 0);
            } else {
                $lv = (int)$v;
            }
            if ($lv > 0) {
                $skillsE += (pow($lv, 2.3) * 2.0);
            }
        }
    }
    $fight = (int)ceil($atk / 12 + $hpMax / 48 + $precision / 16 + $miss / 16 + $skillsE);

    return [
        'attack' => $atk,
        'miss' => $miss,
        'precision_val' => $precision,
        'new_miss' => $newMiss,
        'new_precision' => $newPrecision,
        'hp_max' => $hpMax,
        'hp' => $hp,
        'speed' => $speed,
        'fight' => max(1, $fight),
    ];
}

function amf_org_fix_legacy_stat_explosion(PDO $pdo, int $userId, array $org): array
{
    $orgId = (int)($org['org_id'] ?? 0);
    if ($userId <= 0 || $orgId <= 0) {
        return $org;
    }
    $atk = max(1, (int)($org['attack'] ?? 1));
    $hpMax = max(1, (int)($org['hp_max'] ?? 1));
    $fight = max(1, (int)($org['fight'] ?? 1));
    $looksExploded = ($hpMax > ($atk * 30)) || ($fight > ($atk * 40)) || ($hpMax >= 100000);
    if (!$looksExploded) {
        return $org;
    }
    $patch = amf_recompute_org_stats($org);
    $dao = new OrgDao($pdo);
    $dao->updateFields($userId, $orgId, $patch);
    $fresh = $dao->getOne($userId, $orgId);
    return is_array($fresh) ? $fresh : ($org + $patch);
}

function amf_org_to_response(array $org): array
{
    $skillsRows = [];
    $specRows = [];
    $skillsRaw = $org['skills_json'] ?? '{}';
    $skills = json_decode((string)$skillsRaw, true);
    if (is_array($skills)) {
        $skills = amf_skills_assoc($skills);
        $skills = amf_normalize_normal_skills($skills) + amf_normalize_spec_skills($skills);
        $allMap = amf_skill_all_map();
        $specMap = amf_skill_spec_map();
        foreach ($skills as $k => $v) {
            $key = (string)$k;
            $lv = is_array($v) ? (int)($v['level'] ?? $v['grade'] ?? 1) : (int)$v;
            if ($lv <= 0) {
                $lv = 1;
            }
            if (str_starts_with($key, 'spec:')) {
                $sid = (int)substr($key, 5);
                if ($sid <= 0) {
                    continue;
                }
                $def = $specMap[$sid] ?? null;
                $specRows[] = [
                    'id' => $sid,
                    'name' => amf_skill_display_name($sid, true, is_array($def) ? (string)($def['name'] ?? '') : ''),
                    'grade' => $lv,
                    'type' => is_array($def) ? (string)($def['type'] ?? '0') : '0',
                ];
                continue;
            }
            $sid = (int)$key;
            if ($sid <= 0) {
                continue;
            }
            $def = $allMap[$sid] ?? null;
            $skillsRows[] = [
                'id' => $sid,
                'name' => amf_skill_display_name($sid, false, is_array($def) ? (string)($def['name'] ?? '') : ''),
                'grade' => $lv,
            ];
        }
    }
    $resp = [
        'id' => (int)($org['org_id'] ?? 0),
        'pid' => (int)($org['tpl_id'] ?? 151),
        'gr' => (int)($org['level'] ?? 1),
        'im' => (int)($org['quality'] ?? 1),
        'ex' => (int)($org['exp'] ?? 0),
        'hp' => (string)((int)($org['hp'] ?? 0)),
        'hm' => (string)amf_organism_max_hp($org),
        'at' => (string)((int)($org['attack'] ?? 100)),
        'mi' => (string)((int)($org['miss'] ?? 100)),
        'sp' => (string)((int)($org['speed'] ?? 100)),
        'pr' => (string)((int)($org['precision_val'] ?? $org['precision'] ?? 100)),
        'new_miss' => (string)((int)($org['new_miss'] ?? 100)),
        'new_precision' => (string)((int)($org['new_precision'] ?? 100)),
        'qu' => amf_quality_name_by_level((int)($org['quality'] ?? 1)),
        'dq' => (int)($org['dq'] ?? 0),
        'gi' => (int)($org['gi'] ?? 0),
        'ma' => (int)($org['mature'] ?? 1),
        'pullulation' => (int)($org['mature'] ?? 1),
        'ss' => (string)((int)($org['ss'] ?? 0)),
        'sh' => (string)((int)($org['sh'] ?? 0)),
        'sa' => (string)((int)($org['sa'] ?? 0)),
        'spr' => (string)((int)($org['spr'] ?? 0)),
        'sm' => (string)((int)($org['sm'] ?? 0)),
        'new_syn_precision' => (string)((int)($org['new_syn_precision'] ?? 0)),
        'new_syn_miss' => (string)((int)($org['new_syn_miss'] ?? 0)),
        'fight' => (string)((int)($org['fight'] ?? 0)),
        'skills' => $skillsRows,
        'ssk' => $specRows,
        // Compatibility alias for callers that still read exskill.
        'exskill' => $specRows,
    ];
    $required = ['id','pid','gr','im','ex','hp','hm','at','mi','sp','pr','new_miss','new_precision','qu','ma','fight'];
    $miss = [];
    foreach ($required as $k) {
        if (!array_key_exists($k, $resp)) {
            $miss[] = $k;
        }
    }
    if ($miss !== []) {
        amf_append_log_line(__DIR__ . '/../../runtime/logs/org_mismatch.log', '[' . date('Y-m-d H:i:s') . '] missing=' . implode(',', $miss));
    }
    return $resp;
}

/** @return array<int,array<string,mixed>> */
function amf_skill_defs_all(): array
{
    $cfg = amf_load_cfg_json('skills/all.json');
    if (is_array($cfg) && $cfg !== []) {
        return $cfg;
    }
    $fallback = amf_load_runtime_json('api.apiskill.getAllSkills');
    return is_array($fallback) ? $fallback : [];
}

/** @return array<int,array<string,mixed>> */
function amf_skill_defs_spec(): array
{
    $cfg = amf_load_cfg_json('skills/spec.json');
    if (!is_array($cfg) || $cfg === []) {
        $fallback = amf_load_runtime_json('api.apiskill.getSpecSkillAll');
        $cfg = is_array($fallback) ? $fallback : [];
    }
    if (!is_array($cfg) || $cfg === []) {
        return [];
    }

    // Remove client-side EX learn whitelist lock:
    // SkillManager checks orgImgIdArr before sending skillLearn.
    // Keep all grade-1 ex skills available to all plant pic ids.
    $allPics = range(0, 10000);
    foreach ($cfg as &$row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int)($row['grade'] ?? 0) !== 1) {
            continue;
        }
        $row['orgImgIdArr'] = $allPics;
    }
    unset($row);
    return $cfg;
}

/**
 * Base seed used when shop creates a brand-new organism instance.
 * Priority:
 * 1) runtime/config/organism_shop_templates.json (custom override)
 * 2) built-in fallback map (from verified baseline screenshots)
 *
 * @return array<string,int|string>
 */
function amf_org_shop_seed_template(int $tplId): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $path = realpath(__DIR__ . '/../../runtime/config/organism_shop_templates.json')
            ?: (__DIR__ . '/../../runtime/config/organism_shop_templates.json');
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && trim($raw) !== '') {
                $arr = json_decode($raw, true);
                if (is_array($arr)) {
                    $cfg = $arr;
                }
            }
        }
    }

    $k = (string)$tplId;
    if (isset($cfg[$k]) && is_array($cfg[$k])) {
        $r = $cfg[$k];
        return [
            'level' => max(1, (int)($r['level'] ?? 1)),
            'quality' => max(1, (int)($r['quality'] ?? 1)),
            'exp' => max(0, (int)($r['exp'] ?? 0)),
            'mature' => max(1, (int)($r['mature'] ?? 3)),
            'hp' => max(1, (int)($r['hp'] ?? 100)),
            'hp_max' => max(1, (int)($r['hp_max'] ?? ($r['hp'] ?? 100))),
            'attack' => max(1, (int)($r['attack'] ?? 100)),
            'miss' => max(1, (int)($r['miss'] ?? 100)),
            'precision_val' => max(1, (int)($r['precision_val'] ?? 100)),
            'new_miss' => max(1, (int)($r['new_miss'] ?? 100)),
            'new_precision' => max(1, (int)($r['new_precision'] ?? 100)),
            'speed' => max(1, (int)($r['speed'] ?? 2)),
            'fight' => max(1, (int)($r['fight'] ?? 1)),
            'quality_name' => (string)($r['quality_name'] ?? amf_quality_name_by_level((int)($r['quality'] ?? 1))),
        ];
    }

    // Built-in baseline pool (Lv1, 劣质) from your verified references.
    $fallback = [
        // tpl_id => [hp, atk, armor, pen, dodge, hit, speed, mature, fight]
        1 => [54, 9, 9, 9, 9, 9, 2, 3, 1],
        19 => [72, 12, 12, 12, 12, 12, 2, 4, 2],
        37 => [48, 12, 6, 12, 6, 12, 2, 3, 2],
        55 => [96, 24, 12, 24, 12, 24, 3, 6, 6],
        73 => [57, 9, 12, 6, 12, 6, 2, 3, 1],
        109 => [30, 15, 6, 12, 6, 12, 2, 3, 1],
        127 => [50, 25, 10, 20, 10, 20, 3, 5, 5],
        145 => [54, 9, 12, 6, 12, 6, 2, 3, 1],
        163 => [72, 12, 16, 8, 16, 8, 2, 4, 4],
        181 => [48, 20, 16, 16, 8, 8, 2, 4, 4],
    ];
    $row = $fallback[$tplId] ?? [100, 20, 10, 10, 10, 10, 2, 3, 1];
    return [
        'level' => 1,
        'quality' => 1,
        'quality_name' => amf_quality_name_by_level(1),
        'exp' => 0,
        'mature' => (int)$row[7],
        'hp' => (int)$row[0],
        'hp_max' => (int)$row[0],
        'attack' => (int)$row[1],
        'miss' => (int)$row[2],
        'precision_val' => (int)$row[3],
        'new_miss' => (int)$row[4],
        'new_precision' => (int)$row[5],
        'speed' => (int)$row[6],
        'fight' => (int)$row[8],
    ];
}

/** @return array<int,string> */
function amf_item_name_map(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $path = realpath(__DIR__ . '/../../runtime/config/items.json') ?: (__DIR__ . '/../../runtime/config/items.json');
    if (!is_file($path)) {
        return $cache;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $cache;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $cache;
    }
    foreach ($data as $k => $v) {
        $id = (int)$k;
        if ($id <= 0 || !is_array($v)) {
            continue;
        }
        $name = trim((string)($v['name'] ?? ''));
        if ($name !== '') {
            $cache[$id] = $name;
        }
    }
    return $cache;
}

function amf_text_looks_mojibake(string $text): bool
{
    $s = trim($text);
    if ($s === '') {
        return true;
    }
    if (str_contains($s, '�')) {
        return true;
    }
    // Common mojibake fragments seen in legacy GBK/UTF-8 mixed skill tables.
    foreach (['鍏', '鐨', '鎴', '鏂', '澶', '锟', '鈥', '涓', '姹', '娲', '妫', '閺'] as $frag) {
        if (str_contains($s, $frag)) {
            return true;
        }
    }
    return false;
}

/** Resolve stable display name for skill/ex-skill. */
function amf_skill_display_name(int $skillId, bool $isSpec, string $rawName = ''): string
{
    static $aliasMap = null;
    if (!is_array($aliasMap)) {
        $aliasMap = [];
        $cfg = amf_load_cfg_json('skills/skill_name_alias.json');
        if (is_array($cfg)) {
            foreach ($cfg as $k => $v) {
                $sid = (int)$k;
                $nm = trim((string)$v);
                if ($sid > 0 && $nm !== '') {
                    $aliasMap[$sid] = $nm;
                }
            }
        }
    }
    if (isset($aliasMap[$skillId])) {
        return (string)$aliasMap[$skillId];
    }

    $rawName = trim($rawName);
    if ($rawName !== '' && !amf_text_looks_mojibake($rawName)) {
        return $rawName;
    }

    static $toolMapNormal = null;
    static $toolMapSpec = null;
    if (!is_array($toolMapNormal)) {
        $toolMapNormal = [];
        foreach (amf_skill_defs_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (int)($row['id'] ?? 0);
            $tid = (int)($row['learn_tool'] ?? 0);
            if ($sid > 0 && $tid > 0) {
                $toolMapNormal[$sid] = $tid;
            }
        }
    }
    if (!is_array($toolMapSpec)) {
        $toolMapSpec = [];
        foreach (amf_skill_defs_spec() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (int)($row['id'] ?? 0);
            $tid = (int)($row['learn_tool'] ?? 0);
            if ($sid > 0 && $tid > 0) {
                $toolMapSpec[$sid] = $tid;
            }
        }
    }

    $toolId = $isSpec ? (int)($toolMapSpec[$skillId] ?? 0) : (int)($toolMapNormal[$skillId] ?? 0);
    $itemNames = amf_item_name_map();
    if ($toolId > 0 && isset($itemNames[$toolId])) {
        return (string)$itemNames[$toolId];
    }
    return ($isSpec ? 'ex_skill_' : 'skill_') . $skillId;
}

/** @return array<int,array<string,mixed>> */
function amf_skill_all_map(): array
{
    static $map = null;
    if (is_array($map)) return $map;
    $map = [];
    foreach (amf_skill_defs_all() as $d) {
        if (!is_array($d)) continue;
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) $map[$id] = $d;
    }
    return $map;
}

/** @return array<int,array<string,mixed>> */
function amf_skill_spec_map(): array
{
    static $map = null;
    if (is_array($map)) return $map;
    $map = [];
    foreach (amf_skill_defs_spec() as $d) {
        if (!is_array($d)) continue;
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) $map[$id] = $d;
    }
    return $map;
}

/** @return array<int,array<string,mixed>> learn_tool => pick(skill row) */
function amf_skill_learn_tool_index(bool $spec): array
{
    static $normalIdx = null;
    static $specIdx = null;
    static $normalConfLogged = false;
    static $specConfLogged = false;
    if ($spec && is_array($specIdx)) {
        return $specIdx;
    }
    if (!$spec && is_array($normalIdx)) {
        return $normalIdx;
    }
    $defs = $spec ? amf_skill_defs_spec() : amf_skill_defs_all();
    $groups = [];
    foreach ($defs as $d) {
        if (!is_array($d)) {
            continue;
        }
        $sid = (int)($d['id'] ?? 0);
        $tool = (int)($d['learn_tool'] ?? 0);
        if ($sid <= 0 || $tool <= 0) {
            continue;
        }
        // Learn should start from level-1/grade-1 skill.
        $grade = (int)($d['grade'] ?? 0);
        if ($grade !== 1) {
            continue;
        }
        $groups[$tool][] = $d;
    }

    $idx = [];
    foreach ($groups as $tool => $rows) {
        // Strict mode: only accept 1:1 mapping tool->level1 skill.
        // If ambiguous, do not auto-pick to avoid wrong learning.
        if (count($rows) > 1) {
            usort($rows, static fn($a, $b) => ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)));
            $ids = array_map(static fn($r) => (int)($r['id'] ?? 0), $rows);
            $line = ($spec ? 'spec' : 'normal') . ' learn_tool=' . (int)$tool . ' ambiguous ids=' . implode(',', $ids) . ' picked=none';
            if ($spec) {
                if (!$specConfLogged) {
                    amf_missing_config_log('api.apiorganism.skillLearn', ['warning' => 'spec learn_tool ambiguous, no auto-pick']);
                    $specConfLogged = true;
                }
            } else {
                if (!$normalConfLogged) {
                    amf_missing_config_log('api.apiorganism.skillLearn', ['warning' => 'normal learn_tool ambiguous, no auto-pick']);
                    $normalConfLogged = true;
                }
            }
            amf_append_log_line(__DIR__ . '/../../runtime/logs/missing_config.log', '[' . date('Y-m-d H:i:s') . '] ' . $line);
            continue;
        }
        $idx[(int)$tool] = $rows[0];
    }

    if ($spec) {
        $specIdx = $idx;
        return $specIdx;
    }
    $normalIdx = $idx;
    return $normalIdx;
}

/** @return array<int,bool> */
function amf_skill_learn_tool_set(): array
{
    static $set = null;
    if (is_array($set)) {
        return $set;
    }
    $set = [];
    foreach (amf_skill_defs_all() as $d) {
        if (!is_array($d)) continue;
        $tool = (int)($d['learn_tool'] ?? 0);
        if ($tool > 0) $set[$tool] = true;
    }
    foreach (amf_skill_defs_spec() as $d) {
        if (!is_array($d)) continue;
        $tool = (int)($d['learn_tool'] ?? 0);
        if ($tool > 0) $set[$tool] = true;
    }
    return $set;
}

/**
 * Canonicalize skills_json to associative form:
 * - normal: ["123" => ["level"=>1]]
 * - spec:   ["spec:456" => ["level"=>1]]
 * Supports legacy/list forms to avoid silently losing learned skills.
 * @return array<string,mixed>
 */
function amf_skills_assoc(array $skills): array
{
    $out = [];
    foreach ($skills as $k => $v) {
        $key = (string)$k;
        // Already canonical key.
        if ($key !== '' && (str_starts_with($key, 'spec:') || preg_match('/^\d+$/', $key))) {
            $out[$key] = $v;
            continue;
        }

        // Legacy list item: {id, level, spec}
        if (is_array($v)) {
            $sid = (int)($v['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $isSpec = !empty($v['spec']) || !empty($v['is_spec']) || ((string)($v['type'] ?? '') === 'spec');
            $lk = $isSpec ? ('spec:' . $sid) : (string)$sid;
            $level = max(1, (int)($v['level'] ?? $v['grade'] ?? 1));
            $out[$lk] = ['level' => $level];
            continue;
        }

        // Legacy scalar list: [123, 456, ...]
        if (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v))) {
            $sid = (int)$v;
            if ($sid > 0) {
                $out[(string)$sid] = ['level' => 1];
            }
        }
    }
    return $out;
}

/**
 * Normalize normal skills:
 * - max 4 total
 * - max 1 active(touch_off=2)
 * - max 3 passive(touch_off=1)
 * - unique by group (keep highest grade-id)
 * - drop unknown ids
 * @return array<string,mixed>
 */
function amf_normalize_normal_skills(array $skills): array
{
    $skills = amf_skills_assoc($skills);
    $allMap = amf_skill_all_map();
    $byGroup = [];
    foreach ($skills as $k => $v) {
        $key = (string)$k;
        if (str_starts_with($key, 'spec:')) {
            continue;
        }
        $sid = (int)$key;
        if ($sid <= 0 || !isset($allMap[$sid])) {
            continue;
        }
        $def = $allMap[$sid];
        $group = (string)($def['group'] ?? ('sid:' . $sid));
        $g = (int)($def['grade'] ?? 1);
        $old = $byGroup[$group] ?? null;
        if (!is_array($old) || $g > (int)($old['grade'] ?? 0)) {
            $byGroup[$group] = ['sid' => $sid, 'grade' => $g, 'value' => $v, 'touch_off' => (int)($def['touch_off'] ?? 1)];
        }
    }
    $active = [];
    $passive = [];
    foreach ($byGroup as $x) {
        if ((int)($x['touch_off'] ?? 1) === 2) $active[] = $x;
        else $passive[] = $x;
    }
    usort($active, static fn($a,$b) => (int)$b['grade'] <=> (int)$a['grade']);
    usort($passive, static fn($a,$b) => (int)$b['grade'] <=> (int)$a['grade']);
    $out = [];
    foreach (array_slice($active, 0, 1) as $x) {
        $out[(string)$x['sid']] = $x['value'];
    }
    foreach (array_slice($passive, 0, 3) as $x) {
        if (count($out) >= 4) break;
        $out[(string)$x['sid']] = $x['value'];
    }
    return $out;
}

/**
 * Normalize spec skills: keep only one valid spec skill.
 * @return array<string,mixed>
 */
function amf_normalize_spec_skills(array $skills): array
{
    $skills = amf_skills_assoc($skills);
    $specMap = amf_skill_spec_map();
    $cands = [];
    foreach ($skills as $k => $v) {
        $key = (string)$k;
        if (!str_starts_with($key, 'spec:')) continue;
        $sid = (int)substr($key, 5);
        if ($sid <= 0 || !isset($specMap[$sid])) continue;
        $g = (int)($specMap[$sid]['grade'] ?? 1);
        $cands[] = ['key' => $key, 'grade' => $g, 'value' => $v];
    }
    usort($cands, static fn($a,$b) => (int)$b['grade'] <=> (int)$a['grade']);
    if ($cands === []) return [];
    return [$cands[0]['key'] => $cands[0]['value']];
}

/**
 * Resolve skillLearn second param to real skill id.
 * Returns ['skill_id'=>int,'is_spec'=>bool] or null.
 */
function amf_resolve_learn_target(int $toolOrSkillId, bool $preferTool = false, bool $allowDirectIdFallback = true): ?array
{
    if ($toolOrSkillId <= 0) {
        return null;
    }
    $allDefs = amf_skill_defs_all();
    $specDefs = amf_skill_defs_spec();
    $allById = [];
    foreach ($allDefs as $d) {
        if (!is_array($d)) continue;
        $sid = (int)($d['id'] ?? 0);
        if ($sid > 0) $allById[$sid] = $d;
    }
    $specById = [];
    foreach ($specDefs as $d) {
        if (!is_array($d)) continue;
        $sid = (int)($d['id'] ?? 0);
        if ($sid > 0) $specById[$sid] = $d;
    }
    if (!$preferTool) {
        // Already a real skill id.
        if (isset($allById[$toolOrSkillId])) {
            return ['skill_id' => $toolOrSkillId, 'is_spec' => false];
        }
        if (isset($specById[$toolOrSkillId])) {
            return ['skill_id' => $toolOrSkillId, 'is_spec' => true];
        }
    }

    // Dedicated EX-book fallback mapping (tool id -> spec skill id).
    // Keep this as a safety net when config map is missing/invalid.
    $specBookFallback = [
        1113 => 100,
        1114 => 180,
        1115 => 200,
        1116 => 280,
        1117 => 300,
        1118 => 380,
        1119 => 400,
        1120 => 480,
    ];
    if ($preferTool && isset($specBookFallback[$toolOrSkillId])) {
        return ['skill_id' => (int)$specBookFallback[$toolOrSkillId], 'is_spec' => true];
    }

    // Preferred explicit map from captures/custom server.
    $map = amf_load_cfg_json('skills/learn_tool_map.json');
    if (is_array($map) && isset($map[(string)$toolOrSkillId])) {
        $m = $map[(string)$toolOrSkillId];
        if (is_array($m)) {
            $sid = (int)($m['skill_id'] ?? 0);
            $spec = !empty($m['spec']);
            if ($sid > 0) {
                // Guard invalid stale mapping.
                if ((!$spec && isset($allById[$sid])) || ($spec && isset($specById[$sid]))) {
                    return ['skill_id' => $sid, 'is_spec' => $spec];
                }
            }
        } elseif (is_int($m) || (is_string($m) && preg_match('/^\d+$/', $m))) {
            $sid = (int)$m;
            if ($sid > 0) return ['skill_id' => $sid, 'is_spec' => isset($specById[$sid])];
        }
    }

    // Fallback by strict learn_tool index (only 1:1 mapping entries).
    $n = amf_skill_learn_tool_index(false);
    if (isset($n[$toolOrSkillId]) && is_array($n[$toolOrSkillId])) {
        $sid = (int)($n[$toolOrSkillId]['id'] ?? 0);
        if ($sid > 0) {
            return ['skill_id' => $sid, 'is_spec' => false];
        }
    }
    $s = amf_skill_learn_tool_index(true);
    if (isset($s[$toolOrSkillId]) && is_array($s[$toolOrSkillId])) {
        $sid = (int)($s[$toolOrSkillId]['id'] ?? 0);
        if ($sid > 0) {
            return ['skill_id' => $sid, 'is_spec' => true];
        }
    }

    // Fallback for rare direct-skill-id requests when no tool mapping found.
    if ($preferTool && $allowDirectIdFallback) {
        // Important: when input id is a known learn_tool, never treat it as skill id.
        // Otherwise EX books (e.g. 1122/1123/1124) may be mis-routed to normal skills.
        $learnToolSet = amf_skill_learn_tool_set();
        if (isset($learnToolSet[$toolOrSkillId])) {
            return null;
        }
        if (isset($allById[$toolOrSkillId])) {
            return ['skill_id' => $toolOrSkillId, 'is_spec' => false];
        }
        if (isset($specById[$toolOrSkillId])) {
            return ['skill_id' => $toolOrSkillId, 'is_spec' => true];
        }
    }
    return null;
}

/**
 * Build client-facing skill XML fragments from skills_json.
 * Returns ['skill'=> '<sk>...</sk>', 'exskill'=>'<ssk>...</ssk>']
 */
function amf_build_skill_xml_from_skills_json(array $skills): array
{
    $skills = amf_skills_assoc($skills);
    $allDefs = amf_skill_defs_all();
    $specDefs = amf_skill_defs_spec();
    $allMap = [];
    foreach ($allDefs as $d) {
        if (is_array($d)) {
            $sid = (int)($d['id'] ?? 0);
            if ($sid > 0) $allMap[$sid] = $d;
        }
    }
    $specMap = [];
    foreach ($specDefs as $d) {
        if (is_array($d)) {
            $sid = (int)($d['id'] ?? 0);
            if ($sid > 0) $specMap[$sid] = $d;
        }
    }

    $skItems = [];
    $sskItems = [];
    $skills = amf_normalize_normal_skills($skills) + amf_normalize_spec_skills($skills);
    $normalRows = [];
    $specRows = [];
    foreach ($skills as $k => $v) {
        $lv = 0;
        if (is_array($v)) {
            $lv = max(0, (int)($v['level'] ?? $v['grade'] ?? 0));
        } else {
            $lv = max(0, (int)$v);
        }
        if ($lv <= 0) continue;

        $key = (string)$k;
        if (str_starts_with($key, 'spec:')) {
            $sid = (int)substr($key, 5);
            if ($sid <= 0) continue;
            $def = $specMap[$sid] ?? null;
            if (!is_array($def)) {
                $def = ['name' => ('Spec' . $sid), 'type' => '0', 'grade' => 1];
            }
            $specRows[] = [
                'sid' => $sid,
                'lv' => $lv,
                'name' => amf_skill_display_name($sid, true, (string)($def['name'] ?? '')),
                'type' => (string)($def['type'] ?? '0'),
                'grade' => (int)($def['grade'] ?? 1),
            ];
        } else {
            $sid = (int)$key;
            if ($sid <= 0) continue;
            $def = $allMap[$sid] ?? null;
            if (!is_array($def)) {
                $def = ['name' => ('Skill' . $sid), 'organism_attr' => '0', 'touch_off' => 1, 'grade' => 1];
            }
            $normalRows[] = [
                'sid' => $sid,
                'lv' => $lv,
                'name' => amf_skill_display_name($sid, false, (string)($def['name'] ?? '')),
                'oa' => (string)($def['organism_attr'] ?? '0'),
                'touch_off' => (int)($def['touch_off'] ?? 1), // 2=active,1=passive
                'grade' => (int)($def['grade'] ?? 1),
            ];
        }
    }

    // Do not bind slot 1 to active. Keep only logical constraints (max counts),
    // and render a stable order by grade/id regardless of active/passive type.
    $orderedNormal = $normalRows;
    usort($orderedNormal, static fn($a, $b) => ((int)$b['grade'] <=> (int)$a['grade']) ?: ((int)$a['sid'] <=> (int)$b['sid']));
    $orderedNormal = array_slice($orderedNormal, 0, 4);
    foreach ($orderedNormal as $r) {
        $skItems[] = sprintf(
            '<item na="%s" gr="%d" id="%d" oa="%s"/>',
            htmlspecialchars((string)$r['name'], ENT_QUOTES),
            (int)$r['lv'],
            (int)$r['sid'],
            htmlspecialchars((string)$r['oa'], ENT_QUOTES)
        );
    }

    usort($specRows, static fn($a, $b) => ((int)$b['grade'] <=> (int)$a['grade']) ?: ((int)$a['sid'] <=> (int)$b['sid']));
    if ($specRows !== []) {
        $r = $specRows[0];
        $sskItems[] = sprintf(
            '<item name="%s" grade="%d" id="%d" type="%s"/>',
            htmlspecialchars((string)$r['name'], ENT_QUOTES),
            (int)$r['lv'],
            (int)$r['sid'],
            htmlspecialchars((string)$r['type'], ENT_QUOTES)
        );
    }

    $skXml = implode('', $skItems);
    $sskXml = implode('', $sskItems);
    return ['skill' => $skXml, 'exskill' => $sskXml];
}

function amf_sync_skill_xml_from_json(PDO $pdo, int $userId, array $org): array
{
    $orgId = (int)($org['org_id'] ?? 0);
    if ($userId <= 0 || $orgId <= 0) {
        return $org;
    }
    $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
    if (!is_array($skills)) $skills = [];
    $skills = amf_skills_assoc($skills);
    $skills = amf_normalize_normal_skills($skills) + amf_normalize_spec_skills($skills);
    $patch = amf_build_skill_xml_from_skills_json($skills);
    $skillXml = (string)($patch['skill'] ?? '<sk></sk>');
    $exskillXml = (string)($patch['exskill'] ?? '<ssk></ssk>');
    $oldSkill = (string)($org['skill'] ?? '');
    $oldExSkill = (string)($org['exskill'] ?? '');
    if ($oldSkill !== $skillXml || $oldExSkill !== $exskillXml) {
        $dao = new OrgDao($pdo);
        $dao->updateFields($userId, $orgId, [
            'skill' => $skillXml,
            'exskill' => $exskillXml,
            'skills_json' => json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $org['skill'] = $skillXml;
        $org['exskill'] = $exskillXml;
        $org['skills_json'] = json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $org;
}

function amf_quality_name_by_level(int $q): string
{
    $qq = max(1, min(18, $q));
    static $map = null;
    if (!is_array($map)) {
        $map = [];
        $path = realpath(__DIR__ . '/../../runtime/config/quality_names.json')
            ?: (__DIR__ . '/../../runtime/config/quality_names.json');
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                    $raw = substr($raw, 3);
                }
                $arr = json_decode($raw, true);
                if (is_array($arr)) {
                    $map = $arr;
                }
            }
        }
    }
    $k = (string)$qq;
    return (string)($map[$k] ?? ('Q' . $qq));
}

function amf_org_level_max(): int
{
    return 999;
}

function amf_org_level_exp_min(int $level): int
{
    $lv = max(1, min(amf_org_level_max(), $level));
    return max(0, ($lv - 1) * 1000);
}

function amf_org_level_exp_max(int $level): int
{
    $lv = max(1, min(amf_org_level_max(), $level));
    return $lv * 1000;
}

function amf_org_level_by_exp(int $exp): int
{
    $e = max(0, $exp);
    return max(1, min(amf_org_level_max(), (int)floor($e / 1000) + 1));
}

/**
 * qualityUp expects a full organism object payload (not wrapped in {state,org}).
 * Returning this shape avoids UI overlay lock in the evolution panel.
 */
function amf_org_qualityup_response(array $org, int $orderId = 199): array
{
    $quality = max(1, (int)($org['quality'] ?? 1));
    $level = max(1, (int)($org['level'] ?? 1));
    $exp = (int)($org['exp'] ?? 0);
    $expMin = (int)($org['exp_min'] ?? amf_org_level_exp_min($level));
    $expMax = (int)($org['exp_max'] ?? amf_org_level_exp_max($level));
    $atk = (int)($org['attack'] ?? 100);
    $hp = (int)($org['hp'] ?? 100);
    $hpMax = (int)($org['hp_max'] ?? max($hp, $level * 100 + $quality * 50));
    $miss = (int)($org['miss'] ?? 100);
    $precision = (int)($org['precision_val'] ?? $org['precision'] ?? 100);
    $speed = (int)($org['speed'] ?? 100);
    $fighting = (int)($org['fight'] ?? ($atk + intdiv($hpMax, 10)));

    $skillsRows = [];
    $specRows = [];
    $skillsRaw = $org['skills_json'] ?? '{}';
    $skills = json_decode((string)$skillsRaw, true);
    if (is_array($skills)) {
        $skills = amf_skills_assoc($skills);
        $allMap = amf_skill_all_map();
        $specMap = amf_skill_spec_map();
        foreach ($skills as $k => $v) {
            $key = (string)$k;
            $lv = is_array($v) ? (int)($v['level'] ?? $v['grade'] ?? 1) : (int)$v;
            if ($lv <= 0) {
                $lv = 1;
            }
            if (str_starts_with($key, 'spec:')) {
                $sid = (int)substr($key, 5);
                if ($sid <= 0) {
                    continue;
                }
                $def = $specMap[$sid] ?? null;
                $specRows[] = [
                    'id' => $sid,
                    'name' => amf_skill_display_name($sid, true, is_array($def) ? (string)($def['name'] ?? '') : ''),
                    'grade' => $lv,
                    'type' => is_array($def) ? (string)($def['type'] ?? 0) : '0',
                ];
                continue;
            }
            $sid = (int)$key;
            if ($sid <= 0) {
                continue;
            }
            $def = $allMap[$sid] ?? null;
            $skillsRows[] = [
                'id' => $sid,
                'name' => amf_skill_display_name($sid, false, is_array($def) ? (string)($def['name'] ?? '') : ''),
                'grade' => $lv,
            ];
        }
    }

    return [
        'new_syn_precision' => (string)((int)($org['new_syn_precision'] ?? 0)),
        'exp_min' => (string)$expMin,
        'orderId' => (string)$orderId,
        'soul' => (int)($org['soul'] ?? 0),
        'precision' => (string)$precision,
        'hp' => (string)$hp,
        'ssk' => $specRows,
        // Compatibility alias for callers that still read exskill.
        'exskill' => $specRows,
        'fighting' => (string)$fighting,
        'sa' => (string)((int)($org['sa'] ?? 0)),
        'speed' => (string)$speed,
        'miss' => (string)$miss,
        'skills' => $skillsRows,
        'sh' => (string)((int)($org['sh'] ?? 0)),
        'soul_add' => [
            'attack' => (string)((int)($org['soul_add_attack'] ?? 0)),
            'precision' => (string)((int)($org['soul_add_precision'] ?? 0)),
            'hp' => (string)((int)($org['soul_add_hp'] ?? 0)),
            'speed' => (string)((int)($org['soul_add_speed'] ?? 0)),
            'miss' => (string)((int)($org['soul_add_miss'] ?? 0)),
        ],
        'attack' => (string)$atk,
        'pullulation' => (string)((int)($org['pullulation'] ?? $org['mature'] ?? 1)),
        'ma' => (string)((int)($org['mature'] ?? $org['pullulation'] ?? 1)),
        'sm' => (string)((int)($org['sm'] ?? 0)),
        'id' => (string)((int)($org['org_id'] ?? 0)),
        'exp' => (string)$exp,
        'ss' => (string)((int)($org['ss'] ?? 0)),
        'gi' => (string)((int)($org['gi'] ?? 0)),
        'hp_max' => (string)$hpMax,
        'new_syn_miss' => (string)((int)($org['new_syn_miss'] ?? 0)),
        'new_miss' => (string)((int)($org['new_miss'] ?? 0)),
        'exp_max' => (string)$expMax,
        'quality_name' => amf_quality_name_by_level($quality),
        'new_precision' => (string)((int)($org['new_precision'] ?? 0)),
        'spr' => (string)((int)($org['spr'] ?? 0)),
        'tal_add' => [
            'attack' => (string)((int)($org['tal_add_attack'] ?? 0)),
            'precision' => (string)((int)($org['tal_add_precision'] ?? 0)),
            'hp' => (string)((int)($org['tal_add_hp'] ?? 0)),
            'speed' => (string)((int)($org['tal_add_speed'] ?? 0)),
            'miss' => (string)((int)($org['tal_add_miss'] ?? 0)),
        ],
        'grade' => (string)$level,
    ];
}

function amf_quality_required_tool_id(int $quality): int
{
    // Source: code/src/manager/QualityManager.as::getQualityIdByLevel
    if ($quality > 0 && $quality < 12) {
        return 16;   // TOOL_COMP_QUALITY
    }
    if ($quality === 12) return 834;  // YAOSI book
    if ($quality === 13) return 835;  // BUXIU book
    if ($quality === 14) return 836;  // YONGHENG book
    if ($quality === 15) return 1061; // TAISHANG book
    if ($quality === 16) return 1063; // HUNDUN book
    if ($quality === 17) return 1065; // WUJI book
    return 1065;
}

function amf_quality_required_books_before_moshen(int $quality): int
{
    // Deterministic requirement for q<12: inverse of legacy success chance.
    $chanceByQ = [
        1 => 85, 2 => 80, 3 => 74, 4 => 68, 5 => 60, 6 => 52,
        7 => 44, 8 => 36, 9 => 30, 10 => 24, 11 => 18,
    ];
    $chance = (int)($chanceByQ[$quality] ?? 20);
    if ($chance <= 0) {
        $chance = 20;
    }
    return max(1, (int)ceil(100 / $chance));
}

/** @return array<int,array{min:int,max:int}> */
function amf_growth_ranges_by_tpl(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $path = realpath(__DIR__ . '/php_xml/organism.xml') ?: (__DIR__ . '/php_xml/organism.xml');
    if (!is_file($path)) {
        return $cache;
    }
    $xml = file_get_contents($path);
    if (!is_string($xml) || $xml === '') {
        return $cache;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        return $cache;
    }
    foreach ($sx->xpath('//item') ?: [] as $item) {
        $tpl = (int)($item['id'] ?? 0);
        if ($tpl <= 0 || !isset($item->info)) {
            continue;
        }
        $expl = (string)($item->info['expl'] ?? '');
        if ($expl === '') {
            continue;
        }
        // Prefer numbers after last ":" / "閿? so "X閺? won't pollute range.
        $tail = $expl;
        $p1 = strrpos($tail, ':');
        $p2 = strrpos($tail, ':');
        $cut = max($p1 === false ? -1 : $p1, $p2 === false ? -1 : $p2);
        if ($cut >= 0) {
            $tail = substr($tail, $cut + 1);
        }
        if (preg_match_all('/\d+/', (string)$tail, $mm) === 1 && !empty($mm[0])) {
            $nums = array_map('intval', $mm[0]);
            if (count($nums) === 1) {
                $v = max(1, $nums[0]);
                $cache[$tpl] = ['min' => $v, 'max' => $v];
                continue;
            }
            $cache[$tpl] = [
                'min' => max(1, min($nums[0], $nums[1])),
                'max' => max(1, max($nums[0], $nums[1])),
            ];
            continue;
        }
        // Fallback numeric heuristic from full string.
        if (preg_match_all('/\d+/', $expl, $mm2) !== 1 || empty($mm2[0])) {
            continue;
        }
        $nums2 = array_map('intval', $mm2[0]);
        $n2 = count($nums2);
        if ($n2 === 1) {
            $v2 = max(1, $nums2[0]);
            $cache[$tpl] = ['min' => $v2, 'max' => $v2];
            continue;
        }
        if ($n2 === 2) {
            $v3 = max(1, $nums2[1]);
            $cache[$tpl] = ['min' => $v3, 'max' => $v3];
            continue;
        }
        $a2 = $nums2[$n2 - 2];
        $b2 = $nums2[$n2 - 1];
        $cache[$tpl] = ['min' => max(1, min($a2, $b2)), 'max' => max(1, max($a2, $b2))];
    }

    // Optional manual overrides for garbled lines in organism.xml.
    $ovPath = realpath(__DIR__ . '/../../runtime/config/org_growth_overrides.json')
        ?: (__DIR__ . '/../../runtime/config/org_growth_overrides.json');
    if (is_file($ovPath)) {
        $raw = file_get_contents($ovPath);
        if (is_string($raw) && $raw !== '') {
            $ov = json_decode($raw, true);
            if (is_array($ov)) {
                foreach ($ov as $tpl => $range) {
                    $id = (int)$tpl;
                    if ($id <= 0 || !is_array($range)) {
                        continue;
                    }
                    $min = max(1, (int)($range['min'] ?? 1));
                    $max = max($min, (int)($range['max'] ?? $min));
                    $cache[$id] = ['min' => $min, 'max' => $max];
                }
            }
        }
    }
    return $cache;
}

/** @return array<int,array{min:int,max:int}> */
function amf_growth_ranges_by_star(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $tplRanges = amf_growth_ranges_by_tpl();
    $path = realpath(__DIR__ . '/php_xml/organism.xml') ?: (__DIR__ . '/php_xml/organism.xml');
    if (!is_file($path)) {
        return $cache;
    }
    $xml = file_get_contents($path);
    if (!is_string($xml) || $xml === '') {
        return $cache;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        return $cache;
    }
    foreach ($sx->xpath('//item') ?: [] as $item) {
        $tpl = (int)($item['id'] ?? 0);
        if ($tpl <= 0 || !isset($tplRanges[$tpl])) {
            continue;
        }
        $star = 0;
        $useCondition = (string)($item['use_condition'] ?? '');
        if ($useCondition !== '' && preg_match('/(\d+)/', $useCondition, $m1)) {
            $star = (int)$m1[1];
        }
        if ($star <= 0) {
            $expl = (string)($item->info['expl'] ?? '');
            if ($expl !== '' && preg_match('/(\d+)/', $expl, $m2)) {
                $star = (int)$m2[1];
            }
        }
        if ($star <= 0) {
            continue;
        }
        $r = $tplRanges[$tpl];
        if (!isset($cache[$star])) {
            $cache[$star] = $r;
            continue;
        }
        $cache[$star]['min'] = min($cache[$star]['min'], $r['min']);
        $cache[$star]['max'] = max($cache[$star]['max'], $r['max']);
    }
    return $cache;
}

function amf_growth_star_for_tpl(int $tplId): int
{
    static $cache = null;
    if (is_array($cache) && isset($cache[$tplId])) {
        return (int)$cache[$tplId];
    }
    if (!is_array($cache)) {
        $cache = [];
        $path = realpath(__DIR__ . '/php_xml/organism.xml') ?: (__DIR__ . '/php_xml/organism.xml');
        if (is_file($path)) {
            $xml = file_get_contents($path);
            if (is_string($xml) && $xml !== '') {
                $sx = @simplexml_load_string($xml);
                if ($sx !== false) {
                    foreach ($sx->xpath('//item') ?: [] as $item) {
                        $tpl = (int)($item['id'] ?? 0);
                        if ($tpl <= 0) {
                            continue;
                        }
                        $star = 0;
                        $useCondition = (string)($item['use_condition'] ?? '');
                        if ($useCondition !== '' && preg_match('/(\d+)/', $useCondition, $m1)) {
                            $star = (int)$m1[1];
                        }
                        if ($star <= 0) {
                            $expl = (string)($item->info['expl'] ?? '');
                            if ($expl !== '' && preg_match('/(\d+)/', $expl, $m2)) {
                                $star = (int)$m2[1];
                            }
                        }
                        $cache[$tpl] = $star;
                    }
                }
            }
        }
    }
    return (int)($cache[$tplId] ?? 0);
}

function amf_roll_growth_value_for_tpl(int $tplId): int
{
    // DB-only mode: do not fallback to xml/json for growth ranges.
    $min = 3;
    $max = 6;
    try {
        return random_int($min, $max);
    } catch (Throwable $e) {
        return $min;
    }
}

function amf_shop_state_load(int $userId): array
{
    $store = new StateStore();
    $loaded = $store->load($userId);
    return is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
}

function amf_shop_state_save(int $userId, array $state): void
{
    $store = new StateStore();
    $loaded = $store->load($userId);
    $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
    $store->save($userId, $phase, $state);
}

function amf_state_normalize(array $state): array
{
    if (!isset($state['vip']) || !is_array($state['vip'])) {
        $state['vip'] = [];
    }
    if (!isset($state['counters']) || !is_array($state['counters'])) {
        $state['counters'] = [];
    }
    $state['vip']['grade'] = (int)($state['vip']['grade'] ?? $state['vip_grade'] ?? 0);
    $state['vip']['etime'] = (int)($state['vip']['etime'] ?? $state['vip_etime'] ?? 0);
    $state['vip']['exp'] = (int)($state['vip']['exp'] ?? $state['vip_exp'] ?? 0);
    $state['vip']['restore_hp'] = (int)($state['vip']['restore_hp'] ?? $state['vip_restore_hp'] ?? 0);
    $state['counters']['stone_cha_count'] = (int)($state['counters']['stone_cha_count'] ?? $state['stone_cha_count'] ?? 0);
    $state['counters']['fuben_lcc'] = (int)($state['counters']['fuben_lcc'] ?? $state['fuben_lcc'] ?? 0);
    $state['counters']['cave_times'] = (int)($state['counters']['cave_times'] ?? 0);
    $state['counters']['cave_refresh'] = (int)($state['counters']['cave_refresh'] ?? 0);
    $state['counters']['money_spent_total'] = (int)($state['counters']['money_spent_total'] ?? 0);
    if (!isset($state['user']) || !is_array($state['user'])) {
        $state['user'] = [];
    }
    $state['user']['grade'] = (int)($state['user']['grade'] ?? 20);
    $state['user']['exp'] = (int)($state['user']['exp'] ?? 0);
    $state['user']['today_exp'] = (int)($state['user']['today_exp'] ?? 0);
    $state['user']['today_exp_max'] = (int)($state['user']['today_exp_max'] ?? 2200);
    // Prevent negative progress bar in client when grade and exp drift out of sync.
    $g = max(1, (int)$state['user']['grade']);
    $min = amf_user_grade_exp_min($g);
    if ((int)$state['user']['exp'] < $min) {
        $state['user']['exp'] = $min;
    }
    return $state;
}

function amf_state_prepare_gameplay_counters(array &$state): array
{
    $state = amf_state_normalize($state);
    $today = date('Y-m-d');
    if (!isset($state['gameplay']) || !is_array($state['gameplay'])) {
        $state['gameplay'] = [];
    }
    $day = (string)($state['gameplay']['date'] ?? '');
    if ($day !== $today) {
        $state['gameplay']['date'] = $today;
        // daily counters reset
        $state['counters']['cave_times'] = 10;
        $state['counters']['cave_refresh'] = 3;
        $state['counters']['fuben_lcc'] = 10;
    }
    // Non-daily counters keep historical value.
    $state['counters']['stone_cha_count'] = max(0, (int)($state['counters']['stone_cha_count'] ?? 0));
    $state['counters']['cave_times'] = max(0, (int)($state['counters']['cave_times'] ?? 0));
    $state['counters']['cave_refresh'] = max(0, (int)($state['counters']['cave_refresh'] ?? 0));
    $state['counters']['fuben_lcc'] = max(0, (int)($state['counters']['fuben_lcc'] ?? 0));
    return $state;
}

function amf_user_grade_exp_min(int $grade): int
{
    $g = max(1, $grade);
    $cfg = amf_load_user_level_exp_cfg();
    if (($cfg['mode'] ?? '') === 'curve') {
        return amf_user_curve_cumulative_exp($g - 1, $cfg);
    }
    $levels = is_array($cfg['levels'] ?? null) ? $cfg['levels'] : [];
    $k = (string)$g;
    if (isset($levels[$k]) && is_array($levels[$k]) && isset($levels[$k]['exp_min'])) {
        return max(0, (int)$levels[$k]['exp_min']);
    }
    $step = max(1, (int)($cfg['default_step'] ?? 100));
    return ($g - 1) * $step;
}

function amf_user_grade_exp_max(int $grade): int
{
    $g = max(1, $grade);
    $cfg = amf_load_user_level_exp_cfg();
    if (($cfg['mode'] ?? '') === 'curve') {
        return max(1, amf_user_curve_cumulative_exp($g, $cfg));
    }
    $levels = is_array($cfg['levels'] ?? null) ? $cfg['levels'] : [];
    $k = (string)$g;
    if (isset($levels[$k]) && is_array($levels[$k]) && isset($levels[$k]['exp_max'])) {
        return max(1, (int)$levels[$k]['exp_max']);
    }
    $step = max(1, (int)($cfg['default_step'] ?? 100));
    return $g * $step;
}

function amf_load_user_level_exp_cfg(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $path = realpath(__DIR__ . '/../../runtime/config/user_level_exp.json')
        ?: (__DIR__ . '/../../runtime/config/user_level_exp.json');
    if (!is_file($path)) {
        $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
        return $cfg;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
        return $cfg;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
        return $cfg;
    }
    $cfg = $decoded + ['default_step' => 100, 'levels' => [], 'max_level' => 999];
    return $cfg;
}

function amf_user_curve_cumulative_exp(int $level, array $cfg): int
{
    $lv = max(0, $level);
    $curve = is_array($cfg['curve'] ?? null) ? $cfg['curve'] : [];
    $base = (float)($curve['base'] ?? 32120.0);
    $rate = (float)($curve['rate'] ?? 1.00679);
    if ($base <= 0 || $rate <= 1.0) {
        return $lv * max(1, (int)($cfg['default_step'] ?? 100));
    }
    $v = $base * (pow($rate, $lv) - 1.0);
    return max(0, (int)round($v));
}

function amf_user_max_level(): int
{
    $cfg = amf_load_user_level_exp_cfg();
    return max(1, (int)($cfg['max_level'] ?? 999));
}

/**
 * One-step quality bonus rule (source-like behavior observed in client):
 * new = old + round(old * 5%), speed excluded.
 *
 * @return array<string,int>
 */
function amf_quality_step_bonus_patch(array $org): array
{
    $step = static function (int $v): int {
        return max(0, $v + (int)round($v * 0.05));
    };

    $oldAtk = max(0, (int)($org['attack'] ?? 0));
    $oldHpMax = max(0, (int)($org['hp_max'] ?? 0));
    $oldHp = max(0, (int)($org['hp'] ?? 0));
    $oldMiss = max(0, (int)($org['miss'] ?? 0));
    $oldPrecision = max(0, (int)($org['precision_val'] ?? $org['precision'] ?? 0));
    $oldNewMiss = max(0, (int)($org['new_miss'] ?? $oldMiss));
    $oldNewPrecision = max(0, (int)($org['new_precision'] ?? $oldPrecision));
    $oldFight = max(0, (int)($org['fight'] ?? 0));

    $newHpMax = $step($oldHpMax);
    $hpRatio = $oldHpMax > 0 ? ($oldHp / $oldHpMax) : 1.0;
    $newHp = (int)floor($newHpMax * max(0.0, min(1.0, $hpRatio)));

    return [
        'attack' => $step($oldAtk),
        'hp_max' => $newHpMax,
        'hp' => max(0, $newHp),
        'miss' => $step($oldMiss),
        'precision_val' => $step($oldPrecision),
        'new_miss' => $step($oldNewMiss),
        'new_precision' => $step($oldNewPrecision),
        // Keep in-step visual consistency for battle power panel.
        'fight' => $step($oldFight),
    ];
}

/**
 * @return array{id:int,max_exp:int,min_exp:int,exp:int,money:int,today_exp:int,today_exp_max:int,garden_organism_amount:int,garden_amount:int,max_cave:int,cave_amount:int,ter_ch_count:int,ter_ch_co_max:int,fuben_lcc:int,tools:array}
 */
function amf_user_levelup_row(int $grade, int $exp): array
{
    return [
        'id' => $grade,
        'max_exp' => amf_user_grade_exp_max($grade),
        'min_exp' => amf_user_grade_exp_min($grade),
        'exp' => $exp,
        'money' => 0,
        'today_exp' => 0,
        'today_exp_max' => 2200,
        'garden_organism_amount' => 0,
        'garden_amount' => 0,
        'max_cave' => 0,
        'cave_amount' => 0,
        'ter_ch_count' => 0,
        'ter_ch_co_max' => 0,
        'fuben_lcc' => 0,
        'tools' => [],
    ];
}

/**
 * @return array{grade:int,exp:int,today_exp:int,up_grade:array<int,array>}
 */
function amf_user_add_exp(array &$state, int $expDelta): array
{
    $state = amf_state_normalize($state);
    $u = &$state['user'];
    $grade = max(1, (int)($u['grade'] ?? 20));
    $maxLevel = amf_user_max_level();
    $exp = max(0, (int)($u['exp'] ?? 0)) + max(0, $expDelta);
    $todayExp = max(0, (int)($u['today_exp'] ?? 0)) + max(0, $expDelta);
    $up = [];
    $lastUp = null;
    while ($grade < $maxLevel && $exp >= amf_user_grade_exp_max($grade)) {
        $grade++;
        $lastUp = amf_user_levelup_row($grade, $exp);
    }
    if (is_array($lastUp)) {
        // Client popup should show only the final level reached in one reward action.
        $up[] = $lastUp;
    }
    $u['grade'] = $grade;
    $u['exp'] = $exp;
    $u['today_exp'] = $todayExp;
    if (!isset($u['today_exp_max'])) {
        $u['today_exp_max'] = 2200;
    }
    return [
        'grade' => $grade,
        'exp' => $exp,
        'today_exp' => $todayExp,
        'up_grade' => $up,
    ];
}

function amf_duty_type_key(int $taskType): string
{
    return match ($taskType) {
        1 => 'mainTask',
        2 => 'sideTask',
        3 => 'dailyTask',
        4 => 'activeTask',
        default => '',
    };
}

function amf_is_level_task_row(array $row): bool
{
    $id = (int)($row['id'] ?? 0);
    // Main level-chain tasks are treated as 101xxx.
    return $id >= 101000 && $id < 102000;
}

function amf_duty_ensure_level_task_chain(array $payload): array
{
    if (!isset($payload['mainTask']) || !is_array($payload['mainTask'])) {
        return $payload;
    }
    $main = $payload['mainTask'];
    $levelRows = [];
    $otherRows = [];
    foreach ($main as $row) {
        if (is_array($row) && amf_is_level_task_row($row)) {
            $levelRows[] = $row;
        } else {
            $otherRows[] = $row;
        }
    }
    if (count($levelRows) > 1) {
        return $payload;
    }

    $base = is_array($levelRows[0] ?? null) ? $levelRows[0] : [
        'reward' => ['money' => '', 'honor' => '', 'exp' => '', 'tools' => []],
        'icon' => '12',
        'state' => '0',
        'gotoId' => '0',
        'title' => 'level task',
        'maxCount' => '1',
    ];

    $generated = [];
    $idx = 0;
    for ($lv = 5; $lv <= 999; $lv += 5) {
        $idx++;
        $row = $base;
        $row['id'] = (string)(101000 + $idx);
        $row['curCount'] = '0';
        $row['state'] = '0';
        $row['maxCount'] = '1';
        $row['title'] = amf_lang_fmt('server.task.level.title', [$lv], 'Lv.%1');
        $row['dis'] = amf_lang_fmt('server.task.level.dis', [$lv], 'reach level %1');
        $generated[] = $row;
    }

    $payload['mainTask'] = array_merge($generated, $otherRows);
    return $payload;
}

/**
 * Project level-task progress into mainTask:
 *  - nth level-task requires level n*5
 *  - reached level enables claim
 *  - claimed still shown as finished
 */
function amf_duty_apply_level_task_progress(array $payload, int $userId): array
{
    $payload = amf_duty_ensure_level_task_chain($payload);
    if ($userId <= 0 || !isset($payload['mainTask']) || !is_array($payload['mainTask'])) {
        return $payload;
    }
    $state = amf_state_normalize(amf_shop_state_load($userId));
    $grade = max(1, (int)($state['user']['grade'] ?? 1));
    $today = date('Y-m-d');
    $claimed = (array)($state['duty_claimed'][$today] ?? []);

    $idx = 0;
    foreach ($payload['mainTask'] as &$row) {
        if (!is_array($row) || !amf_is_level_task_row($row)) {
            continue;
        }
        $idx++;
        $needLv = $idx * 5;
        $id = (string)($row['id'] ?? '');
        $done = ($id !== '' && isset($claimed[$id]));
        $ok = ($grade >= $needLv);

        $row['maxCount'] = 1;
        $row['curCount'] = $ok ? 1 : 0;
        $row['state'] = ($done || !$ok) ? 0 : 1;
        $row['needLevel'] = $needLv;
        if (($needLv % 50) === 0) {
            $row['reward'] = [
                'money' => '',
                'honor' => '',
                'exp' => '',
                'tools' => [
                    ['id' => 103, 'num' => 1000],
                    ['id' => 93, 'num' => 1000],
                    ['id' => 500, 'num' => 1],
                ],
            ];
        }
    }
    unset($row);

    // Compact display: keep only one level-task row in UI.
    $payload['mainTask'] = amf_duty_compact_level_task_rows($payload['mainTask']);

    return $payload;
}

/**
 * Keep only one visible level task:
 * 1) first claimable
 * 2) else first locked
 * 3) else last one (all done)
 *
 * Non-level tasks are kept as-is.
 *
 * @param array<int,mixed> $rows
 * @return array<int,mixed>
 */
function amf_duty_compact_level_task_rows(array $rows): array
{
    $levelRows = [];
    $otherRows = [];
    foreach ($rows as $r) {
        if (is_array($r) && amf_is_level_task_row($r)) {
            $levelRows[] = $r;
        } else {
            $otherRows[] = $r;
        }
    }
    if (count($levelRows) <= 1) {
        return $rows;
    }

    $pick = null;
    foreach ($levelRows as $r) {
        if ((int)($r['state'] ?? 0) === 1) {
            $pick = $r;
            break;
        }
    }
    if (!is_array($pick)) {
        foreach ($levelRows as $r) {
            $cur = (int)($r['curCount'] ?? 0);
            $max = max(1, (int)($r['maxCount'] ?? 1));
            if ($cur < $max) {
                $pick = $r;
                break;
            }
        }
    }
    if (!is_array($pick)) {
        $pick = $levelRows[count($levelRows) - 1];
    }

    array_unshift($otherRows, $pick);
    return $otherRows;
}

function amf_duty_level_task_need_level(array $allData, int $taskId): ?int
{
    $allData = amf_duty_ensure_level_task_chain($allData);
    $rows = is_array($allData['mainTask'] ?? null) ? $allData['mainTask'] : [];
    $idx = 0;
    foreach ($rows as $row) {
        if (!is_array($row) || !amf_is_level_task_row($row)) {
            continue;
        }
        $idx++;
        if ((int)($row['id'] ?? 0) === $taskId) {
            return $idx * 5;
        }
    }
    return null;
}

/**
 * @return array{task:array,id:int,need:int}|null
 */
function amf_duty_pick_next_claimable_level_task(array $allData, array $claimedToday, int $userGrade): ?array
{
    $allData = amf_duty_ensure_level_task_chain($allData);
    $rows = is_array($allData['mainTask'] ?? null) ? $allData['mainTask'] : [];
    usort($rows, static function ($a, $b): int {
        $ia = is_array($a) ? (int)($a['id'] ?? 0) : 0;
        $ib = is_array($b) ? (int)($b['id'] ?? 0) : 0;
        return $ia <=> $ib;
    });
    foreach ($rows as $row) {
        if (!is_array($row) || !amf_is_level_task_row($row)) {
            continue;
        }
        $rid = (int)($row['id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $rkey = (string)$rid;
        if (isset($claimedToday[$rkey])) {
            continue;
        }
        $seq = $rid - 101000;
        $need = $seq > 0 ? ($seq * 5) : 5;
        if ($userGrade < $need) {
            // first unmet tier reached; nothing claimable beyond this either.
            return null;
        }
        return ['task' => $row, 'id' => $rid, 'need' => $need];
    }
    return null;
}

function amf_duty_apply_claim_state(array $payload, int $userId): array
{
    if ($userId <= 0) {
        return $payload;
    }
    $state = amf_state_normalize(amf_shop_state_load($userId));
    $today = date('Y-m-d');
    $claimed = (array)($state['duty_claimed'][$today] ?? []);
    foreach (['mainTask', 'sideTask', 'dailyTask', 'activeTask'] as $group) {
        if (!isset($payload[$group]) || !is_array($payload[$group])) {
            continue;
        }
        foreach ($payload[$group] as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            if ($id === '' || !isset($claimed[$id])) {
                continue;
            }
            $row['state'] = 0;
            // NewTask UI rule:
            // state==0 && gotoId>0 => show "閸撳秴绶?.
            // Claimed tasks must not keep a jump target, otherwise it looks uncompleted.
            $row['gotoId'] = 0;
            $max = (int)($row['maxCount'] ?? 0);
            if ($max > 0) {
                $row['curCount'] = $max;
            }
        }
        unset($row);
    }
    return amf_duty_apply_level_task_progress($payload, $userId);
}

function amf_duty_filter_to_level_and_exp_only(array $payload): array
{
    $payload = amf_duty_ensure_level_task_chain($payload);

    $main = is_array($payload['mainTask'] ?? null) ? $payload['mainTask'] : [];
    $daily = is_array($payload['dailyTask'] ?? null) ? $payload['dailyTask'] : [];

    $payload['mainTask'] = array_values(array_filter($main, static function ($row): bool {
        return is_array($row) && amf_is_level_task_row($row);
    }));

    $payload['dailyTask'] = array_values(array_filter($daily, static function ($row): bool {
        if (!is_array($row)) {
            return false;
        }
        $id = (int)($row['id'] ?? 0);
        return $id === 301001 || $id === 301002;
    }));

    // Keep one dynamic side task: cumulative coupon spending (every 500).
    $uid = (int)(RequestContext::get()['user_id'] ?? 0);
    $payload['sideTask'] = amf_duty_build_coupon_side_task($uid);
    // Do not inject registration-active list into NewTask activeTask.
    // It creates many invalid/duplicate rows in the task window.
    $payload['activeTask'] = [];

    return $payload;
}

/** @return array<int,array<string,mixed>> */
function amf_duty_build_coupon_side_task(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $pdo = DB::pdo();
    $state = amf_state_normalize(amf_shop_state_load($userId));
    $spent = amf_currency_spend_get($pdo, $userId, 'money');
    $claimedStep = max(0, (int)($state['duty_coupon']['claimed_step'] ?? 0));
    $step = $claimedStep + 1;
    $need = $step * 500;
    $cur = min($spent, $need);
    $ready = $spent >= $need;
    $taskId = 250000 + $step;

    $rewardTools = amf_coupon_task_reward_tools($need);
    return [[
        'id' => (string)$taskId,
        'icon' => '19',
        'title' => amf_lang_get('server.task.coupon.title', 'coupon_spend'),
        'dis' => amf_lang_fmt('server.task.coupon.dis', [$need], 'today spend coupon %1'),
        'reward' => [
            'money' => '',
            'honor' => '',
            'exp' => '',
            'tools' => $rewardTools,
        ],
        'state' => $ready ? 1 : 0,
        'maxCount' => $need,
        'curCount' => $cur,
        'gotoId' => '0',
        'step' => $step,
    ]];
}

/** @return array<int,array{id:int,num:int}> */
function amf_coupon_task_reward_tools(int $need): array
{
    // Base placeholder reward every 500 spend tier.
    $tools = [
        ['id' => 103, 'num' => 1000],
    ];
    // Milestone placeholders at 5000 / 10000 / 15000 / ...
    if ($need > 0 && ($need % 5000) === 0) {
        $tools[] = ['id' => 93, 'num' => 1000];
        $tools[] = ['id' => 79, 'num' => 1000];
        $tools[] = ['id' => 80, 'num' => 1000];
    }
    return $tools;
}

function amf_state_get_vip(array $state): array
{
    $state = amf_state_normalize($state);
    return $state['vip'];
}

function amf_state_get_counter(array $state, string $key, int $default = 0): int
{
    $state = amf_state_normalize($state);
    return (int)($state['counters'][$key] ?? $default);
}

function amf_state_set_counter(array &$state, string $key, int $value): void
{
    $state = amf_state_normalize($state);
    $state['counters'][$key] = $value;
}

function amf_server_time(): int
{
    return time();
}

function amf_state_prepare_vip_daily_claims(array &$state): array
{
    $today = date('Y-m-d');
    $claimDate = (string)($state['vip_awards_claimed_date'] ?? '');
    if ($claimDate !== $today) {
        $state['vip_awards_claimed_date'] = $today;
        $state['vip_awards_claimed'] = [];
    }
    if (!isset($state['vip_awards_claimed']) || !is_array($state['vip_awards_claimed'])) {
        $state['vip_awards_claimed'] = [];
    }
    return $state['vip_awards_claimed'];
}

function amf_state_prepare_active_daily(array &$state): array
{
    $today = date('Y-m-d');
    if (!isset($state['active']) || !is_array($state['active'])) {
        $state['active'] = [];
    }
    $day = (string)($state['active']['date'] ?? '');
    if ($day !== $today) {
        $state['active']['date'] = $today;
        $state['active']['times_claimed'] = [];
        $state['active']['point_claimed'] = [];
        $state['active']['point'] = 120;
        $state['active']['spent_today'] = 0;
    }
    if (!isset($state['active']['times_claimed']) || !is_array($state['active']['times_claimed'])) {
        $state['active']['times_claimed'] = [];
    }
    if (!isset($state['active']['point_claimed']) || !is_array($state['active']['point_claimed'])) {
        $state['active']['point_claimed'] = [];
    }
    $state['active']['spent_today'] = max(0, (int)($state['active']['spent_today'] ?? 0));
    $state['active']['point'] = max(0, (int)($state['active']['point'] ?? 120));
    return $state['active'];
}

/** @return array<int,array{id:int,need:int,point:int}> */
function amf_active_welfare_task_defs(): array
{
    return [
        ['id' => 401050, 'need' => 50, 'point' => 20],
        ['id' => 401150, 'need' => 150, 'point' => 20],
        ['id' => 401300, 'need' => 300, 'point' => 20],
        ['id' => 401500, 'need' => 500, 'point' => 20],
        ['id' => 4011200, 'need' => 1200, 'point' => 20],
    ];
}

/** @return array<int,array<string,mixed>> */
function amf_duty_build_active_welfare_tasks(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $state = amf_state_normalize(amf_shop_state_load($userId));
    $active = amf_state_prepare_active_daily($state);
    $spentToday = amf_active_effective_spent_today($userId, $state);
    $claimed = is_array($active['times_claimed'] ?? null) ? $active['times_claimed'] : [];

    $rows = [];
    foreach (amf_active_welfare_task_defs() as $def) {
        $id = (int)$def['id'];
        $need = (int)$def['need'];
        $point = (int)$def['point'];
        $done = $spentToday >= $need;
        $claimedThis = !empty($claimed[(string)$id]);
        $rows[] = [
            'id' => (string)$id,
            'icon' => '19',
            'title' => amf_lang_get('server.task.daily_spend.title', 'daily_spend'),
            'dis' => amf_lang_fmt('server.task.daily_spend.dis', [$need], 'today spend coupon %1'),
            'reward' => [
                'money' => '',
                'honor' => '',
                'exp' => '',
                'tools' => [],
            ],
            'state' => $done && !$claimedThis ? 1 : 0,
            'maxCount' => $need,
            'curCount' => min($spentToday, $need),
            'gotoId' => 0,
            'active_point' => $point,
        ];
    }
    // explicit deterministic point count from reached tasks
    $point = 0;
    foreach (amf_active_welfare_task_defs() as $def) {
        if ($spentToday >= (int)$def['need']) {
            $point += (int)$def['point'];
        }
    }
    $state['active']['point'] = $point;
    amf_shop_state_save($userId, $state);
    return $rows;
}

function amf_state_add_counter(array &$state, string $key, int $delta): int
{
    $state = amf_state_normalize($state);
    $state['counters'][$key] = (int)($state['counters'][$key] ?? 0) + $delta;
    return (int)$state['counters'][$key];
}

function amf_state_extend_vip(array &$state, int $seconds, int $grade = 0): array
{
    $state = amf_state_normalize($state);
    $now = time();
    $old = (int)$state['vip']['etime'];
    $base = $old > $now ? $old : $now;
    $state['vip']['etime'] = $base + max(0, $seconds);
    if ($grade > 0) {
        $state['vip']['grade'] = max((int)$state['vip']['grade'], $grade);
    }
    return $state['vip'];
}

function amf_inject_state_into_payload(array $payload, int $userId): array
{
    $payload = amf_duty_filter_to_level_and_exp_only($payload);
    if ($userId <= 0) {
        return $payload;
    }
    $loaded = amf_shop_state_load($userId);
    $state = amf_state_normalize($loaded);
    $vip = $state['vip'];
    $user = $state['user'];
    $stone = (int)($state['counters']['stone_cha_count'] ?? 0);
    $replace = static function (&$node) use (&$replace, $vip, $stone): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => &$v) {
            if (is_array($v)) {
                $replace($v);
                continue;
            }
            if ($k === 'vip_grade') {
                $v = (int)$vip['grade'];
            } elseif ($k === 'vip_etime') {
                $v = (int)$vip['etime'];
            } elseif ($k === 'vip_exp') {
                $v = (int)$vip['exp'];
            } elseif ($k === 'vip_restore_hp') {
                $v = (int)$vip['restore_hp'];
            } elseif ($k === 'stone_cha_count') {
                $v = $stone;
            } elseif ($k === 'id' && is_array($node) && isset($node['exp_max']) && isset($node['exp_min']) && isset($node['exp'])) {
                $v = (int)($user['grade'] ?? 20);
            } elseif ($k === 'exp' && is_array($node) && isset($node['exp_max']) && isset($node['exp_min'])) {
                $v = (int)($user['exp'] ?? 0);
            } elseif ($k === 'exp_min' && is_array($node) && isset($node['exp_max']) && isset($node['exp'])) {
                $v = amf_user_grade_exp_min((int)($user['grade'] ?? 20));
            } elseif ($k === 'exp_max' && is_array($node) && isset($node['exp_min']) && isset($node['exp'])) {
                $v = amf_user_grade_exp_max((int)($user['grade'] ?? 20));
            } elseif ($k === 'today_exp') {
                $v = (int)($user['today_exp'] ?? 0);
            } elseif ($k === 'today_exp_max') {
                $v = (int)($user['today_exp_max'] ?? 2200);
            }
        }
    };
    $replace($payload);
    return amf_duty_apply_claim_state($payload, $userId);
}

function amf_mark_inventory_dirty(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $state = amf_shop_state_load($userId);
    $state['inventory_dirty'] = 1;
    $state['inventory_dirty_at'] = date('Y-m-d H:i:s');
    amf_shop_state_save($userId, $state);
}

function amf_shop_find_merchandise(int $merchId): ?array
{
    $all = amf_load_runtime_json('api.shop.getMerchandises');
    if (!is_array($all)) {
        return null;
    }
    foreach ($all as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((int)($item['id'] ?? 0) === $merchId) {
                return $item;
            }
        }
    }
    return null;
}

function amf_append_log_line(string $file, string $line): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $line = amf_log_commentize_if_deprecated($file, $line);
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function amf_log_commentize_if_deprecated(string $file, string $line): string
{
    static $deprecated = null;
    if (!is_array($deprecated)) {
        $deprecated = [];
        $path = __DIR__ . '/../../runtime/config/log_comment_policy.json';
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $arr = json_decode($raw, true);
                if (is_array($arr['deprecated_logs'] ?? null)) {
                    foreach ($arr['deprecated_logs'] as $n) {
                        if (is_string($n) && $n !== '') {
                            $deprecated[strtolower($n)] = true;
                        }
                    }
                }
            }
        }
    }

    $base = strtolower(basename($file));
    if (!empty($deprecated[$base]) && !str_starts_with($line, '// DEPRECATED_LOG')) {
        return '// DEPRECATED_LOG ' . $line;
    }
    return $line;
}

function amf_method_log_enabled(): bool
{
    // Opt-in: create runtime/config/enable_amf_log.txt
    $flag = __DIR__ . '/../../runtime/config/enable_amf_log.txt';
    return is_file($flag);
}

function amf_method_log(string $line): void
{
    amf_append_log_line(__DIR__ . '/../../runtime/logs/amf_method.log', $line);
}

function fuben_minimal_enabled(): bool
{
    // Opt-in: create runtime/config/enable_fuben_minimal.txt
    $flag = __DIR__ . '/../../runtime/config/enable_fuben_minimal.txt';
    return is_file($flag);
}

function fuben_normalize_display(array $data): array
{
    // The Flash client accesses some cave fields without null checks (e.g. boss, challenge_count).
    // When missing, AS3 treats them as undefined, which can flip booleans (boss) and break UI logic.
    if (!isset($data['_caves']) || !is_array($data['_caves'])) {
        return $data;
    }
    foreach ($data['_caves'] as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        // Ensure string fields are present.
        $row += [
            'boss' => '0',
            'challenge_count' => 5,
            'reward' => '',
            'parent' => '',
            'child' => '',
            'open_tools' => '',
            't' => '0',
        ];
        // Keep boss as "0"/"1" string to match client isBoss(param:String).
        if (is_int($row['boss']) || is_float($row['boss'])) {
            $row['boss'] = ((int)$row['boss']) === 0 ? '0' : '1';
        } elseif (!is_string($row['boss'])) {
            $row['boss'] = '0';
        } else {
            $row['boss'] = trim($row['boss']) === '0' ? '0' : '1';
        }
        // challenge_count should be numeric (int-like).
        if (is_string($row['challenge_count']) && preg_match('/^-?\\d+$/', $row['challenge_count'])) {
            $row['challenge_count'] = (int)$row['challenge_count'];
        } elseif (!is_int($row['challenge_count'])) {
            $row['challenge_count'] = 5;
        }

        $data['_caves'][$i] = $row;
    }
    return $data;
}

function amf_log_unknown_method(AmfGatewayRequest $req, string $raw): void
{
    $ts = date('Y-m-d H:i:s');
    $path = __DIR__ . '/../../runtime/logs/amf_unknown.log';
    $param0 = amf_extract_first_param_int($raw);
    $extra = $param0 === null ? '' : (' firstParamInt=' . $param0);
    amf_append_log_line($path, '[' . $ts . '] ' . $req->targetUri . $extra);
}

function amf_send_text(string $text, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $text;
    exit;
}

function amf_short_token(int $bytes = 16): string
{
    return strtoupper(bin2hex(random_bytes($bytes)));
}

function amf_set_short_lived_token_cookie(): void
{
    // Real site sets a 1-second HttpOnly token frequently. Keep compatible.
    setcookie('token', amf_short_token(16), [
        'expires' => time() + 1,
        'path' => '/',
        'httponly' => true,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

function amf_pvzol_seed(): string
{
    $seed = (string)($_COOKIE['pvzol_seed'] ?? '');
    if ($seed !== '') {
        return $seed;
    }

    $seed = (string)($_GET['seed'] ?? '');
    if ($seed === '') {
        // Stable ASCII fallback to avoid encoding corruption in runtime logs/cookies.
        $seed = 'pvzol_seed_default';
    }

    setcookie('pvzol_seed', $seed, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);

    return $seed;
}

amf_set_short_lived_token_cookie();
amf_pvzol_seed();

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!preg_match('#^/pvz/amf/?$#', $path)) {
    amf_send_text('Not Found', 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    amf_send_text('Method Not Allowed', 405);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    amf_send_text('Bad Request: empty body', 400);
}

// Keep response binary-clean.
ini_set('display_errors', '0');
error_reporting(0);

// Ensure we have a PHPSESSID to mirror the real AppendToGatewayUrl header behavior.
$phpSessId = (string)($_COOKIE['PHPSESSID'] ?? '');
if ($phpSessId === '') {
    $phpSessId = strtoupper(bin2hex(random_bytes(16)));
    setcookie('PHPSESSID', $phpSessId, [
        'expires' => time() + 30 * 24 * 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

try {
    $req = AmfGateway::parseRequest($raw);
} catch (Throwable $e) {
    amf_send_text('Bad AMF', 400);
}

// Warm up organisms table metadata outside business transactions.
// OrgDao::ensureTable() performs DDL/ALTER checks; doing this here avoids
// implicit-commit side effects inside API handlers.
try {
    $warmPdo = DB::pdo();
    (new OrgDao($warmPdo))->ensureTable();
    amf_ensure_currency_spend_table($warmPdo);
} catch (Throwable $e) {
    // non-fatal; handlers will still attempt lazy init
}

if (amf_method_log_enabled()) {
    $ts = date('Y-m-d H:i:s');
    $p0 = amf_extract_param_int_at($raw, 0);
    $p1 = amf_extract_param_int_at($raw, 1);
    $hint = ' +';
    if ($p0 !== null) {
        $hint .= ' p0=' . $p0;
    }
    if ($p1 !== null) {
        $hint .= ' p1=' . $p1;
    }
    amf_method_log('[' . $ts . '] IN ' . $req->targetUri . $hint);
}

// Large responses are easiest to keep correct by replaying captured body bytes.
require_once __DIR__ . '/modules/legacy_handlers_module.php';
require_once __DIR__ . '/modules/duty_module.php';
require_once __DIR__ . '/modules/cave_battle_module.php';
require_once __DIR__ . '/modules/active_guide_vip_module.php';
require_once __DIR__ . '/modules/organism_core_module.php';
require_once __DIR__ . '/modules/fuben_module.php';
require_once __DIR__ . '/modules/stone_module.php';
require_once __DIR__ . '/modules/shop_module.php';
require_once __DIR__ . '/modules/inventory_module.php';
require_once __DIR__ . '/modules/synthesis_module.php';
require_once __DIR__ . '/modules/garden_module.php';
$handlers = amf_register_legacy_handlers((string)$raw);
if (function_exists('amf_register_duty_handlers')) {
    $handlers = array_replace($handlers, amf_register_duty_handlers((string)$raw));
}
if (function_exists('amf_register_cave_battle_handlers')) {
    $handlers = array_replace($handlers, amf_register_cave_battle_handlers((string)$raw));
}
if (function_exists('amf_register_active_guide_vip_handlers')) {
    $handlers = array_replace($handlers, amf_register_active_guide_vip_handlers((string)$raw));
}
if (function_exists('amf_register_organism_core_handlers')) {
    $handlers = array_replace($handlers, amf_register_organism_core_handlers((string)$raw));
}
if (function_exists('amf_register_fuben_handlers')) {
    $handlers = array_replace($handlers, amf_register_fuben_handlers((string)$raw));
}
if (function_exists('amf_register_stone_handlers')) {
    $handlers = array_replace($handlers, amf_register_stone_handlers((string)$raw));
}
if (function_exists('amf_register_shop_handlers')) {
    $handlers = array_replace($handlers, amf_register_shop_handlers((string)$raw));
}
if (function_exists('amf_register_inventory_handlers')) {
    $handlers = array_replace($handlers, amf_register_inventory_handlers((string)$raw));
}
if (function_exists('amf_register_synthesis_handlers')) {
    $handlers = array_replace($handlers, amf_register_synthesis_handlers((string)$raw));
}
if (function_exists('amf_register_garden_handlers')) {
    $handlers = array_replace($handlers, amf_register_garden_handlers((string)$raw));
}

$handler = $handlers[$req->targetUri] ?? null;
if ($handler === null) {
    // Keep the game from spinning forever: return a minimal AMF response,
    // and log the missing method so we can implement it precisely next.
    amf_log_unknown_method($req, $raw);
    $respBytes = AmfGateway::buildResponse($req->responseUri, ['state' => 1], $phpSessId);
    header('Content-Type: application/x-amf');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $respBytes;
    exit;
}

$result = null;
$sig = amf_resolve_sig();
$session = SessionResolver::resolveFromRequest($sig);
$userId = (int)($session['user_id'] ?? 0);
$ctx = [
    'sig' => (string)($session['sig'] ?? ''),
    'user_id' => $userId > 0 ? $userId : 0,
    'xml_path' => (string)($session['xml_path'] ?? ''),
];
RequestContext::set($ctx);

$stateStore = new StateStore();
$loaded = ['phase' => 'GUEST', 'state' => []];
try { $loaded = $stateStore->load($ctx['user_id']); } catch (Throwable $e) { /* no-op */ }

try {
    $result = $handler();
} catch (Throwable $e) {
    $ctx = RequestContext::get();
    $traceId = amf_trace_id();
    amf_log_error(
        $req->targetUri,
        (string)($ctx['sig'] ?? ''),
        (int)($ctx['user_id'] ?? 0),
        $traceId,
        $e
    );
    $result = amf_runtime_error_payload($traceId, 'handler exception');
    if (amf_method_log_enabled()) {
        $ts = date('Y-m-d H:i:s');
        amf_method_log('[' . $ts . '] ERR ' . $req->targetUri . ' ' . get_class($e) . ': ' . $e->getMessage());
    }
}

if (amf_method_log_enabled()) {
    $ts = date('Y-m-d H:i:s');
    $out = $result instanceof AmfRawValue ? ('rawBytes=' . strlen($result->raw)) : ('phpType=' . gettype($result));
    amf_method_log('[' . $ts . '] OUT ' . $req->targetUri . ' ' . $out);
}
$respBytes = AmfGateway::buildResponse($req->responseUri, $result, $phpSessId);

header('Content-Type: application/x-amf');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $respBytes;
exit;

