<?php
declare(strict_types=1);

function amf_register_garden_handlers(string $raw): array
{
    $gardenInit = static function (): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0, 'slots' => [], 'challenge_count' => 0];
        }
        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        amf_shop_state_save($uid, $state);
        return [
            'state' => 1,
            'challenge_count' => (int)($state['counters']['garden_cha_count'] ?? 0),
            'slots' => array_values((array)($state['garden']['slots'] ?? [])),
            'server_time' => time(),
        ];
    };

    $gardenAdd = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['status' => 'fail', 'error' => 1];
        }

        $params = amf_decode_first_params($raw);
        $pdo = DB::pdo();
        $orgs = amf_organism_list($pdo, $uid);
        $resolved = amf_garden_resolve_plant_request($params, $orgs);
        $orgId = (int)($resolved['org_id'] ?? 0);
        $x = (int)($resolved['x'] ?? 0);
        $y = (int)($resolved['y'] ?? 0);
        $workType = (int)($resolved['work_type'] ?? 0);
        $found = is_array($resolved['org_row'] ?? null) ? $resolved['org_row'] : null;
        if (!is_array($found)) {
            return ['status' => 'fail', 'error' => 1];
        }

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        // Enforce uniqueness: one organism can only be mounted in one garden slot.
        foreach ((array)$state['garden']['slots'] as $k => $s) {
            if (!is_array($s)) {
                continue;
            }
            if ((int)($s['org_id'] ?? 0) === $orgId) {
                unset($state['garden']['slots'][$k]);
            }
        }
        $slotKey = $x . ',' . $y;
        $ripeTime = time() + 300;
        $state['garden']['slots'][$slotKey] = [
            'org_id' => $orgId,
            'x' => $x,
            'y' => $y,
            'type' => $workType,
            'time' => 300,
            'ripe_time' => $ripeTime,
        ];
        amf_shop_state_save($uid, $state);
        try {
            $dao = new OrgDao($pdo);
            $dao->updateFields($uid, $orgId, ['gi' => $uid]);
        } catch (Throwable $e) {
            // keep garden flow alive even if gi sync fails
        }

        return [
            'status' => 'success',
            'state' => [
                'type' => $workType,
                'time' => 300,
            ],
            'ripe_time' => $ripeTime,
            'user' => amf_garden_user_delta_payload($state, 0, 0),
        ];
    };

    $gardenRemove = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0];
        }
        $params = amf_decode_first_params($raw);
        $ids = amf_garden_extract_ints($params);
        $targetOrgId = max(0, (int)($ids[0] ?? 0));

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);
        $removed = 0;
        foreach ($slots as $k => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($targetOrgId > 0 && $orgId !== $targetOrgId) {
                continue;
            }
            unset($slots[$k]);
            if ($orgId > 0) {
                try {
                    $pdo = DB::pdo();
                    $dao = new OrgDao($pdo);
                    $dao->updateFields($uid, $orgId, ['gi' => 0]);
                } catch (Throwable $e) {
                }
            }
            $removed++;
            if ($targetOrgId > 0) {
                break;
            }
        }
        $state['garden']['slots'] = $slots;
        amf_shop_state_save($uid, $state);
        return ['state' => 1, 'removed' => $removed];
    };

    $gardenRemoveStateAll = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['success' => [], 'failure' => [], 'exp' => 0, 'money' => 0, 'is_up' => false, 'up_grade' => []];
        }
        $params = amf_decode_first_params($raw);
        $ints = amf_garden_extract_ints($params);
        $rpcType = max(0, (int)($ints[1] ?? 0));

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);

        $success = [];
        foreach ($slots as $slotKey => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            $success[] = [
                'id' => $orgId,
                'time' => 300,
                'type' => $rpcType,
                'hp' => 0,
            ];
            $slot['type'] = $rpcType;
            $slot['time'] = 300;
            $slot['ripe_time'] = time() + 300;
            $slots[$slotKey] = $slot;
        }

        $gainExp = count($success);
        $gainMoney = count($success) * 10;
        $up = amf_user_add_exp($state, $gainExp);
        if ($gainMoney > 0) {
            $state['wallet']['money'] = max(0, (int)($state['wallet']['money'] ?? 0) + $gainMoney);
        }
        $state['garden']['slots'] = $slots;
        amf_shop_state_save($uid, $state);

        return [
            'success' => $success,
            'failure' => [],
            'exp' => $gainExp,
            'money' => $gainMoney,
            'is_up' => !empty($up['up_grade']),
            'up_grade' => $up['up_grade'],
            'id' => (int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1)),
            'max_exp' => amf_user_grade_exp_max((int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1))),
            'min_exp' => amf_user_grade_exp_min((int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1))),
            'one_day_max_exp' => (int)($state['user']['today_exp_max'] ?? 2200),
            'garden_organism_amount' => max(0, count((array)($state['garden']['slots'] ?? []))),
            'garden_amount' => 0,
            'max_cave' => 0,
            'tools' => [],
        ];
    };

    $gardenHarvest = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0, 'success' => [], 'failure' => [], 'money' => 0, 'exp' => 0];
        }
        $params = amf_decode_first_params($raw);
        $ids = amf_garden_extract_ints($params);
        $targetOrgId = max(0, (int)($ids[0] ?? 0));
        $now = time();

        $pdo = DB::pdo();
        $orgs = amf_organism_list($pdo, $uid);
        $orgOut = [];
        foreach ($orgs as $r) {
            $oid = (int)($r['org_id'] ?? 0);
            if ($oid > 0) {
                $orgOut[$oid] = max(1, (int)($r['ou'] ?? $r['output'] ?? 25));
            }
        }

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);
        $success = [];
        $failure = [];
        $gainMoney = 0;
        foreach ($slots as $k => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($targetOrgId > 0 && $orgId !== $targetOrgId) {
                continue;
            }
            $ripe = (int)($slot['ripe_time'] ?? 0);
            if ($ripe > $now) {
                $failure[] = ['id' => $orgId, 'error' => 1, 'message' => 'not_ripe'];
                continue;
            }
            $money = (int)($orgOut[$orgId] ?? 25);
            $gainMoney += $money;
            $success[] = ['id' => $orgId, 'money' => $money];
            $slot['ripe_time'] = $now + 300;
            $slot['type'] = 0;
            $slot['time'] = 0;
            $slots[$k] = $slot;
        }

        $gainExp = count($success);
        $up = amf_user_add_exp($state, $gainExp);
        if ($gainMoney > 0) {
            $state['wallet']['money'] = max(0, (int)($state['wallet']['money'] ?? 0) + $gainMoney);
        }
        $state['garden']['slots'] = $slots;
        amf_shop_state_save($uid, $state);

        return [
            'state' => 1,
            'success' => $success,
            'failure' => $failure,
            'money' => $gainMoney,
            'exp' => $gainExp,
            'is_up' => !empty($up['up_grade']),
            'up_grade' => $up['up_grade'],
        ];
    };

    $gardenAccelerate = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0];
        }
        $params = amf_decode_first_params($raw);
        $ids = amf_garden_extract_ints($params);
        $targetOrgId = max(0, (int)($ids[0] ?? 0));
        $mode = max(0, (int)($ids[1] ?? 0)); // 1 watering / 2 fertilizer
        $toolId = $mode === 2 ? '4' : '3';

        $pdo = DB::pdo();
        if (!amf_inventory_remove($pdo, $uid, $toolId, 1)) {
            return ['state' => 0, 'error' => 'not_enough_tool', 'tool_id' => (int)$toolId];
        }

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);
        $changed = 0;
        $now = time();
        foreach ($slots as $k => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($targetOrgId > 0 && $orgId !== $targetOrgId) {
                continue;
            }
            if ($mode === 2) {
                $slot['ripe_time'] = $now; // fertilizer: instant ripe
                $slot['type'] = 2;
            } else {
                $remain = max(0, (int)($slot['ripe_time'] ?? $now) - $now);
                $slot['ripe_time'] = $now + (int)floor($remain * 0.5); // watering: half remaining
                $slot['type'] = 1;
            }
            $slot['time'] = max(0, (int)($slot['ripe_time'] ?? $now) - $now);
            $slots[$k] = $slot;
            $changed++;
        }
        $state['garden']['slots'] = $slots;
        amf_shop_state_save($uid, $state);
        return ['state' => 1, 'changed' => $changed, 'tool_id' => (int)$toolId];
    };

    $gardenOutAndStealAll = static function (): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['out' => ['success' => [], 'failure' => []], 'steal' => ['success' => [], 'failure' => []], 'exp' => 0, 'money' => 0, 'is_up' => false, 'up_grade' => []];
        }

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);
        $now = time();

        $outSuccess = [];
        foreach ($slots as $slotKey => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            $ripe = (int)($slot['ripe_time'] ?? 0);
            if ($ripe > $now) {
                continue;
            }
            $outSuccess[] = [
                'id' => $orgId,
                'money' => 25,
            ];
            $slot['ripe_time'] = $now + 300;
            $slot['type'] = 0;
            $slot['time'] = 0;
            $slots[$slotKey] = $slot;
        }

        $gainExp = count($outSuccess);
        $gainMoney = 0;
        foreach ($outSuccess as $r) {
            $gainMoney += (int)($r['money'] ?? 0);
        }
        $up = amf_user_add_exp($state, $gainExp);
        if ($gainMoney > 0) {
            $state['wallet']['money'] = max(0, (int)($state['wallet']['money'] ?? 0) + $gainMoney);
        }
        $state['garden']['slots'] = $slots;
        amf_shop_state_save($uid, $state);

        return [
            'out' => ['success' => $outSuccess, 'failure' => []],
            'steal' => ['success' => [], 'failure' => []],
            'exp' => $gainExp,
            'money' => $gainMoney,
            'is_up' => !empty($up['up_grade']),
            'up_grade' => $up['up_grade'],
            'id' => (int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1)),
            'max_exp' => amf_user_grade_exp_max((int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1))),
            'min_exp' => amf_user_grade_exp_min((int)($up['grade'] ?? (int)($state['user']['grade'] ?? 1))),
            'one_day_max_exp' => (int)($state['user']['today_exp_max'] ?? 2200),
            'garden_organism_amount' => max(0, count((array)($state['garden']['slots'] ?? []))),
            'garden_amount' => 0,
            'max_cave' => 0,
            'tools' => [],
        ];
    };

    $gardenOrganismReturnHome = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0];
        }
        $params = amf_decode_first_params($raw);
        $ids = amf_garden_extract_ints($params);
        $idMap = [];
        foreach ($ids as $n) {
            $n = (int)$n;
            if ($n > 0) {
                $idMap[$n] = true;
            }
        }

        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state = amf_garden_state_prepare($state);
        $slots = (array)($state['garden']['slots'] ?? []);
        $kept = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($orgId > 0 && !empty($idMap[$orgId])) {
                try {
                    $pdo = DB::pdo();
                    $dao = new OrgDao($pdo);
                    $dao->updateFields($uid, $orgId, ['gi' => 0]);
                } catch (Throwable $e) {
                }
                continue;
            }
            $kept[] = $slot;
        }
        $state['garden']['slots'] = [];
        foreach ($kept as $slot) {
            $k = (int)($slot['x'] ?? 0) . ',' . (int)($slot['y'] ?? 0);
            $state['garden']['slots'][$k] = $slot;
        }
        amf_shop_state_save($uid, $state);
        return ['state' => 1];
    };

    $gardenChallenge = static function () use ($raw): array {
        $ctx = RequestContext::get();
        $uid = (int)($ctx['user_id'] ?? 0);
        if ($uid <= 0) {
            return ['state' => 0, 'error' => 'guest'];
        }
        $params = amf_decode_first_params($raw);
        $ints = amf_garden_extract_ints($params);
        $mx = max(0, (int)($ints[1] ?? 0));
        $my = max(0, (int)($ints[2] ?? 0));

        $pdo = DB::pdo();
        $mine = amf_organism_list($pdo, $uid);
        $selected = amf_cave_extract_selected_org_ids($raw);
        if ($selected === []) {
            foreach ($mine as $row) {
                $oid = (int)($row['org_id'] ?? 0);
                if ($oid > 0) {
                    $selected[] = $oid;
                    break;
                }
            }
        }
        if ($selected === []) {
            return ['state' => 0, 'error' => 'NO_ORG_SELECTED'];
        }

        $monsterLevel = max(1, $mx + $my + 1);
        $meta = [
            'monster_id' => 700000 + ($mx * 100) + $my,
            'monster_orid' => 19,
            'monster_level' => $monsterLevel,
            'hp' => 2000 + ($monsterLevel * 800),
            'atk' => 300 + ($monsterLevel * 120),
        ];
        $sim = amf_cave_simulate_battle_payload($uid, max(1, $mx + $my + 1), 'garden:' . $uid, $mine, $selected, $meta);

        return [
            'state' => 1,
            'defenders' => (array)($sim['defenders'] ?? []),
            'proceses' => (array)($sim['proceses'] ?? []),
            'awards_key' => (string)($uid . '-' . $mx . '-' . $my . '-' . time()),
            'is_winning' => !empty($sim['is_winning']),
            'die_status' => (int)($sim['die_status'] ?? 0),
            'through_name' => '',
            'assailants' => (array)($sim['assailants'] ?? []),
        ];
    };

    return [
        'api.garden.init' => $gardenInit,
        'api.garden.plant' => $gardenAdd,
        'api.garden.remove' => $gardenRemove,
        'api.garden.harvest' => $gardenHarvest,
        'api.garden.accelerate' => $gardenAccelerate,
        'api.garden.add' => $gardenAdd,
        'api.garden.removeStateAll' => $gardenRemoveStateAll,
        'api.garden.outAndStealAll' => $gardenOutAndStealAll,
        'api.garden.organismReturnHome' => $gardenOrganismReturnHome,
        'api.garden.challenge' => $gardenChallenge,
    ];
}

function amf_garden_extract_ints(mixed $params): array
{
    $out = [];
    $walk = static function (mixed $v) use (&$walk, &$out): void {
        if (is_int($v)) {
            $out[] = $v;
            return;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v) {
            $out[] = (int)$v;
            return;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $out[] = (int)$v;
            return;
        }
        if (is_array($v)) {
            foreach ($v as $vv) {
                $walk($vv);
            }
        }
    };
    $walk($params);
    return $out;
}

function amf_garden_state_prepare(array $state): array
{
    if (!isset($state['garden']) || !is_array($state['garden'])) {
        $state['garden'] = [];
    }
    if (!isset($state['garden']['slots']) || !is_array($state['garden']['slots'])) {
        $state['garden']['slots'] = [];
    }
    return $state;
}


function amf_garden_user_delta_payload(array $state, int $moneyAdd, int $expAdd): array
{
    $grade = max(1, (int)($state['user']['grade'] ?? 1));
    $expNow = max(0, (int)($state['user']['exp'] ?? 0));
    return [
        'money' => $moneyAdd,
        'exp' => $expAdd,
        'is_up' => false,
        'up_grade' => [],
        'id' => $grade,
        'max_exp' => amf_user_grade_exp_max($grade),
        'min_exp' => amf_user_grade_exp_min($grade),
        'one_day_max_exp' => (int)($state['user']['today_exp_max'] ?? 2200),
        'garden_organism_amount' => max(0, count((array)($state['garden']['slots'] ?? []))),
        'garden_amount' => 0,
        'max_cave' => 0,
        'exp_now' => $expNow,
        'tools' => [],
    ];
}

function amf_garden_resolve_plant_request(mixed $params, array $orgRows): array
{
    $orgMap = [];
    foreach ($orgRows as $row) {
        $oid = (int)($row['org_id'] ?? 0);
        if ($oid > 0) {
            $orgMap[$oid] = $row;
        }
    }
    $ints = amf_garden_extract_ints($params);
    $orgId = 0;
    $orgRow = null;
    $idx = -1;
    foreach ($ints as $i => $n) {
        $n = (int)$n;
        if ($n > 0 && isset($orgMap[$n])) {
            $orgId = $n;
            $orgRow = $orgMap[$n];
            $idx = $i;
            break;
        }
    }
    if ($orgId <= 0) {
        return ['org_id' => 0, 'x' => 0, 'y' => 0, 'work_type' => 0, 'org_row' => null];
    }
    $x = 0;
    $y = 0;
    $workType = 0;
    if ($idx >= 0) {
        $x = max(0, (int)($ints[$idx + 1] ?? 0));
        $y = max(0, (int)($ints[$idx + 2] ?? 0));
        $workType = max(0, (int)($ints[$idx + 3] ?? 0));
    }
    return ['org_id' => $orgId, 'x' => $x, 'y' => $y, 'work_type' => $workType, 'org_row' => $orgRow];
}
