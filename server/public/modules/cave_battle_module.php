<?php
declare(strict_types=1);

function amf_register_cave_battle_handlers(string $raw): array
{
    $caveInit = static function (): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0, 'cave_times' => 0, 'cave_refresh' => 0, 'seed' => '', 'list' => []];
        }
        $state = amf_shop_state_load($uid);
        $prep = amf_cave_state_prepare($uid, $state);
        $state = $prep['state'];
        if (!empty($prep['changed'])) {
            amf_shop_state_save($uid, $state);
        }
        $all = (array)($state['cave']['list'] ?? []);
        $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
        $open = [];
        foreach ($all as $row) {
            if (!is_array($row)) {
                continue;
            }
            $need = max(1, (int)($row['unlock_level'] ?? 1));
            if ($need <= $userLevel) {
                $open[] = $row;
            }
        }
        if ($open === []) {
            $open = array_slice($all, 0, 1);
        }
        return [
            'state' => 1,
            'cave_times' => (int)($state['counters']['cave_times'] ?? 0),
            'cave_refresh' => (int)($state['counters']['cave_refresh'] ?? 0),
            'seed' => (string)($state['cave']['seed'] ?? ''),
            'list' => $open,
        ];
    };

    $caveGetCaveList = static function (): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0, 'list' => [], 'cave_times' => 0, 'cave_refresh' => 0];
        }
        $state = amf_shop_state_load($uid);
        $prep = amf_cave_state_prepare($uid, $state);
        $state = $prep['state'];
        if (!empty($prep['changed'])) {
            amf_shop_state_save($uid, $state);
        }
        $all = (array)($state['cave']['list'] ?? []);
        $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
        $open = [];
        foreach ($all as $row) {
            if (!is_array($row)) {
                continue;
            }
            $need = max(1, (int)($row['unlock_level'] ?? 1));
            if ($need <= $userLevel) {
                $open[] = $row;
            }
        }
        if ($open === []) {
            $open = array_slice($all, 0, 1);
        }
        return [
            'state' => 1,
            'list' => $open,
            'cave_times' => (int)($state['counters']['cave_times'] ?? 0),
            'cave_refresh' => (int)($state['counters']['cave_refresh'] ?? 0),
            'seed' => (string)($state['cave']['seed'] ?? ''),
        ];
    };

    $caveGetCaveInfo = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $caveId = max(1, (int)(amf_extract_param_int_at($raw, 0) ?? 1));
        if ($uid <= 0) {
            return ['state' => 0, 'cave_id' => $caveId];
        }
        $state = amf_shop_state_load($uid);
        $prep = amf_cave_state_prepare($uid, $state);
        $state = $prep['state'];
        if (!empty($prep['changed'])) {
            amf_shop_state_save($uid, $state);
        }
        $list = (array)($state['cave']['list'] ?? []);
        $picked = null;
        foreach ($list as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $caveId) {
                $picked = $row;
                break;
            }
        }
        if (!is_array($picked)) {
            $picked = [
                'id' => $caveId,
                'name' => 'Cave-Lv1',
                'difficulty' => 1,
                'rewardTier' => 1,
                'unlock_level' => 1,
                'monster_level' => 1,
                'hp' => 5000,
                'atk' => 1000,
            ];
        }
        $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
        $needLevel = max(1, (int)($picked['unlock_level'] ?? 1));
        return [
            'state' => 1,
            'cave' => $picked,
            'cave_id' => $caveId,
            'monster_level' => (int)($picked['monster_level'] ?? 1),
            'monster_hp' => (int)($picked['hp'] ?? 5000),
            'monster_atk' => (int)($picked['atk'] ?? 1000),
            'is_open' => $userLevel >= $needLevel ? 1 : 0,
            'cave_times' => (int)($state['counters']['cave_times'] ?? 0),
            'cave_refresh' => (int)($state['counters']['cave_refresh'] ?? 0),
        ];
    };

    $caveChallenge = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $caveId = max(1, (int)(amf_extract_param_int_at($raw, 0) ?? 1));
        if ($uid <= 0) {
            return ['state' => 0, 'error' => 'guest cannot challenge'];
        }
        $pdo = DB::pdo();
        amf_battle_session_ensure_table($pdo);
        $selectedIds = amf_cave_extract_selected_org_ids($raw);
        $state = amf_shop_state_load($uid);
        $prep = amf_cave_state_prepare($uid, $state);
        $state = $prep['state'];
        $list = (array)($state['cave']['list'] ?? []);
        $picked = null;
        foreach ($list as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $caveId) {
                $picked = $row;
                break;
            }
        }
        if (!is_array($picked)) {
            return ['state' => 0, 'error' => 'invalid cave id'];
        }
        $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
        $needLevel = max(1, (int)($picked['unlock_level'] ?? $picked['monster_level'] ?? 1));
        if ($userLevel < $needLevel) {
            return ['state' => 0, 'error' => 'cave locked', 'need_level' => $needLevel, 'user_level' => $userLevel];
        }
        $times = (int)($state['counters']['cave_times'] ?? 0);
        if ($times <= 0) {
            return ['state' => 0, 'error' => 'not enough cave times', 'cave_times' => 0];
        }
        $pdo->beginTransaction();
        try {
            $state['counters']['cave_times'] = $times - 1;
            amf_shop_state_save($uid, $state);
            $seed = (string)($state['cave']['seed'] ?? '');
            $stmt = $pdo->prepare(
                'INSERT INTO battle_session (user_id, source, cave_id, seed, status) VALUES (:uid, :src, :cid, :seed, :st)'
            );
            $stmt->execute([
                ':uid' => $uid,
                ':src' => 'cave',
                ':cid' => $caveId,
                ':seed' => $seed,
                ':st' => 'created',
            ]);
            $battleId = (int)$pdo->lastInsertId();
            $mine = amf_organism_list($pdo, $uid);
            $mineMap = [];
            foreach ($mine as $mr) {
                $oid = (int)($mr['org_id'] ?? 0);
                if ($oid > 0) {
                    $mineMap[$oid] = true;
                }
            }
            $validSelected = [];
            foreach ($selectedIds as $sid) {
                $sid = (int)$sid;
                if ($sid > 0 && !empty($mineMap[$sid])) {
                    $validSelected[] = $sid;
                }
            }
            if ($validSelected === []) {
                foreach ($mine as $mr) {
                    $oid = (int)($mr['org_id'] ?? 0);
                    $hp = (int)($mr['hp'] ?? 0);
                    $fight = (int)($mr['fight'] ?? 0);
                    if ($oid > 0 && $hp > 0 && $fight > 0) {
                        $validSelected[] = $oid;
                    }
                }
            }
            if ($validSelected === []) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['state' => 0, 'error' => amf_lang_get('server.cave.need_org', 'NO_ORG_SELECTED')];
            }
            $sim = amf_cave_simulate_battle_payload($uid, $caveId, $seed, $mine, $validSelected, $picked);
            $resp = [
                'state' => 1,
                'battle_id' => $battleId,
                'defenders' => (array)($sim['defenders'] ?? []),
                'proceses' => (array)($sim['proceses'] ?? []),
                'awards_key' => (string)$battleId,
                'is_winning' => !empty($sim['is_winning']),
                'die_status' => (int)($sim['die_status'] ?? 0),
                'through_name' => '',
                'assailants' => (array)($sim['assailants'] ?? []),
            ];
            $upd = $pdo->prepare('UPDATE battle_session SET result_json=:res WHERE battle_id=:bid');
            $upd->execute([
                ':res' => json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':bid' => $battleId,
            ]);
            $pdo->commit();
            return $resp;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['state' => 0, 'error' => $e->getMessage()];
        }
    };

    $caveOpenCave = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $caveId = max(1, (int)(amf_extract_param_int_at($raw, 0) ?? 1));
        if ($uid <= 0) {
            return ['state' => 0, 'error' => 'guest cannot open cave'];
        }
        return [
            'id' => $caveId,
            'status' => 2,
            'open_id' => max(0, $caveId - 1),
            'come_time' => 0,
            'lock_time' => 0,
            'open_money' => 100,
            'monsters' => [[
                'id' => 3000 + $caveId,
                'orid' => 19,
                'hp' => amf_cave_power_by_index(max(1, $caveId), 5000),
                'hp_max' => amf_cave_power_by_index(max(1, $caveId), 5000),
                'attack' => amf_cave_power_by_index(max(1, $caveId), 1000),
                'miss' => 31,
                'precision' => 21,
                'new_miss' => 0,
                'new_precision' => 0,
                'grade' => max(1, (int)($caveId * 2 - 1)),
                'quality_id' => 1,
            ]],
        ];
    };

    $battleFight = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $battleId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
        if ($uid <= 0 || $battleId <= 0) {
            return ['state' => 0, 'error' => 'invalid battle'];
        }
        $pdo = DB::pdo();
        amf_battle_session_ensure_table($pdo);
        $stmt = $pdo->prepare('SELECT * FROM battle_session WHERE battle_id=:bid AND user_id=:uid LIMIT 1');
        $stmt->execute([':bid' => $battleId, ':uid' => $uid]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['state' => 0, 'error' => 'battle not found'];
        }
        $saved = $row['result_json'] ?? null;
        $payload = null;
        if (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded) && isset($decoded['proceses'])) {
                $payload = $decoded;
            }
        }
        $pdo->beginTransaction();
        try {
            if (!is_array($payload)) {
                $caveId = (int)($row['cave_id'] ?? 1);
                $seed = (string)($row['seed'] ?? '');
                $orgs = amf_organism_list($pdo, $uid);
                $sim = amf_cave_simulate_battle_payload($uid, $caveId, $seed, $orgs, []);
                $payload = [
                    'state' => 1,
                    'battle_id' => $battleId,
                    'defenders' => (array)($sim['defenders'] ?? []),
                    'proceses' => (array)($sim['proceses'] ?? []),
                    'awards_key' => (string)$battleId,
                    'is_winning' => !empty($sim['is_winning']),
                    'die_status' => (int)($sim['die_status'] ?? 0),
                    'through_name' => '',
                    'assailants' => (array)($sim['assailants'] ?? []),
                ];
                $upRes = $pdo->prepare('UPDATE battle_session SET result_json=:res WHERE battle_id=:bid');
                $upRes->execute([
                    ':res' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':bid' => $battleId,
                ]);
            }
            $hpMap = [];
            foreach ((array)($payload['assailants'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $oid = (int)($a['id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $hpMap[$oid] = max(0, (int)($a['hp'] ?? 0));
            }
            if ($hpMap !== []) {
                $dao = new OrgDao($pdo);
                foreach ($hpMap as $oid => $hp) {
                    $dao->updateFields($uid, $oid, ['hp' => $hp]);
                }
            }
            $upd = $pdo->prepare('UPDATE battle_session SET status=:st WHERE battle_id=:bid');
            $upd->execute([':st' => 'fought', ':bid' => $battleId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['state' => 0, 'error' => $e->getMessage()];
        }
        if (!is_array($payload)) {
            return ['state' => 0, 'error' => 'empty payload'];
        }
        $payload['state'] = 1;
        $payload['battle_id'] = $battleId;
        return $payload;
    };

    $rewardLottery = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $battleId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
        if ($battleId <= 0) {
            $p0s = trim((string)(amf_extract_param_string_at($raw, 0) ?? ''));
            if ($p0s === '' || $p0s === ' ') {
                $battleId = 0;
            } elseif (preg_match('/^\d+$/', $p0s)) {
                $battleId = (int)$p0s;
            }
        }
        return amf_lottery_settle_by_battle($uid, $battleId);
    };

    $caveGetReward = static function () use ($rewardLottery): array {
        return $rewardLottery();
    };

    $caveQuickChallenge = static function () use ($caveChallenge): array {
        $ch = $caveChallenge();
        if (!is_array($ch) || (int)($ch['state'] ?? 0) !== 1) {
            return is_array($ch) ? $ch : ['state' => 0];
        }
        $battleId = (int)($ch['battle_id'] ?? 0);
        if ($battleId <= 0) {
            return ['state' => 0, 'error' => 'missing battle id'];
        }
        $battleFightResp = [
            'state' => 1,
            'battle_id' => $battleId,
            'defenders' => (array)($ch['defenders'] ?? []),
            'proceses' => (array)($ch['proceses'] ?? []),
            'is_winning' => (bool)($ch['is_winning'] ?? false),
            'assailants' => (array)($ch['assailants'] ?? []),
        ];
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        $lot = amf_lottery_settle_by_battle($uid, $battleId);
        if (!is_array($lot)) {
            return ['state' => 0];
        }
        $lot['fight'] = $battleFightResp;
        return $lot;
    };

    $battleQuickFight = static function () use ($rewardLottery): array {
        return $rewardLottery();
    };

    return [
        'api.cave.init' => $caveInit,
        'api.cave.getCaveInfo' => $caveGetCaveInfo,
        'api.cave.getCaveList' => $caveGetCaveList,
        'api.cave.openCave' => $caveOpenCave,
        'api.cave.challenge' => $caveChallenge,
        'api.cave.getReward' => $caveGetReward,
        'api.cave.quickChallenge' => $caveQuickChallenge,
        'api.battle.fight' => $battleFight,
        'api.battle.quickFight' => $battleQuickFight,
        'api.reward.lottery' => $rewardLottery,
    ];
}

