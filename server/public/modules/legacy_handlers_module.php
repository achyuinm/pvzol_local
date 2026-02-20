<?php
declare(strict_types=1);
// DEPRECATED: Transition fallback module.
// Prefer domain modules (duty/shop/inventory/skills/garden/fuben/cave) for new logic.
// Keep this file for compatibility branches only.

function amf_register_legacy_handlers(string $raw): array
{
$dutyGetAll = static function (): mixed {
    // Prefer a human-editable JSON so you can tweak rewards/tasks live.
    // If missing, fall back to replaying a captured body (safe default).
    $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.duty.getAll.json';
    if (is_file($cfgPath)) {
        $json = file_get_contents($cfgPath);
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                // Return decoded PHP array; gateway will AMF0-encode it.
                $ctx = RequestContext::get();
                $uid = (int)($ctx['user_id'] ?? 0);
                $resp = amf_inject_state_into_payload($data, $uid);
                if (is_file(__DIR__ . '/../../../runtime/config/enable_active_debug.txt')) {
                    $activeRows = is_array($resp['activeTask'] ?? null) ? $resp['activeTask'] : [];
                    amf_append_log_line(
                        __DIR__ . '/../../../runtime/logs/active_debug.log',
                        '[' . date('Y-m-d H:i:s') . '] duty.getAll uid=' . $uid . ' activeTask=' . json_encode($activeRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }
                return $resp;
            }
        }
    }

    return [
        'mainTask' => [],
        'sideTask' => [],
        'dailyTask' => [],
        'activeTask' => [],
    ];
};

$dutyReward = static function () use ($raw): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $taskId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    $taskType = max(0, (int)(amf_extract_param_int_at($raw, 1) ?? 0));
    if ($uid <= 0 || $taskId <= 0) {
        return ['state' => 0, 'error' => 'invalid params', 'up_grade' => []];
    }

    $data = amf_load_runtime_json('api.duty.getAll');
    if (!is_array($data)) {
        return ['state' => 0, 'error' => 'missing duty config', 'up_grade' => []];
    }
    $data = amf_duty_ensure_level_task_chain($data);

    $groupKey = amf_duty_type_key($taskType);
    $task = null;
    if ($groupKey !== '' && isset($data[$groupKey]) && is_array($data[$groupKey])) {
        foreach ($data[$groupKey] as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $taskId) {
                $task = $row;
                break;
            }
        }
    }
    if (!is_array($task)) {
        foreach (['mainTask', 'sideTask', 'dailyTask', 'activeTask'] as $k) {
            if (!isset($data[$k]) || !is_array($data[$k])) {
                continue;
            }
            foreach ($data[$k] as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) === $taskId) {
                    $task = $row;
                    break 2;
                }
            }
        }
    }
    $state = amf_state_normalize(amf_shop_state_load($uid));
    $today = date('Y-m-d');
    $claimed = (array)($state['duty_claimed'][$today] ?? []);

    // Dynamic side task: cumulative coupon spending (every 500 tier, infinite).
    if ($taskType === 2) {
        $pdo = DB::pdo();
        $spent = amf_currency_spend_get($pdo, $uid, 'money');
        $claimedStep = max(0, (int)($state['duty_coupon']['claimed_step'] ?? 0));
        $step = $claimedStep + 1;
        $need = $step * 500;
        if ($spent < $need) {
            return ['state' => 0, 'error' => 'not enough spend', 'need' => $need, 'current' => $spent, 'up_grade' => []];
        }
        $pdo->beginTransaction();
        try {
            $rewardTools = amf_coupon_task_reward_tools($need);
            foreach ($rewardTools as $rw) {
                $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
                $qty = max(1, (int)($rw['num'] ?? 1));
                if ($iid === 'tool:0') {
                    continue;
                }
                amf_inventory_add($pdo, $uid, $iid, $qty);
                amf_inventory_delta_log($uid, $iid, $qty, 'api.duty.reward');
            }

            if (!isset($state['duty_coupon']) || !is_array($state['duty_coupon'])) {
                $state['duty_coupon'] = [];
            }
            $state['duty_coupon']['claimed_step'] = $step;
            amf_shop_state_save($uid, $state);
            $pdo->commit();
            return [
                'state' => 1,
                'already' => 0,
                'task_id' => 250000 + $step,
                'coupon_step' => $step,
                'tools' => $rewardTools,
                'up_grade' => [],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['state' => 0, 'error' => $e->getMessage(), 'up_grade' => []];
        }
    }

    // Dynamic active welfare tasks (type=4): daily spend thresholds -> active points.
    if ($taskType === 4) {
        $defs = amf_active_welfare_task_defs();
        $picked = null;
        foreach ($defs as $d) {
            if ((int)($d['id'] ?? 0) === $taskId) {
                $picked = $d;
                break;
            }
        }
        if (!is_array($picked)) {
            return ['state' => 0, 'error' => 'active task not found', 'up_grade' => []];
        }
        $need = max(1, (int)($picked['need'] ?? 1));
        $point = max(0, (int)($picked['point'] ?? 20));
        $state = amf_state_normalize(amf_shop_state_load($uid));
        $active = amf_state_prepare_active_daily($state);
        $spentToday = amf_active_effective_spent_today($uid, $state);
        if ($spentToday < $need) {
            if (is_file(__DIR__ . '/../../../runtime/config/enable_active_debug.txt')) {
                amf_append_log_line(
                    __DIR__ . '/../../../runtime/logs/active_debug.log',
                    sprintf('[%s] duty.reward active uid=%d taskId=%d need=%d spent=%d result=not_enough', date('Y-m-d H:i:s'), $uid, $taskId, $need, $spentToday)
                );
            }
            return ['state' => 0, 'error' => 'not enough spend', 'need' => $need, 'current' => $spentToday, 'up_grade' => []];
        }
        if (!isset($state['active']['times_claimed']) || !is_array($state['active']['times_claimed'])) {
            $state['active']['times_claimed'] = [];
        }
        if (!empty($state['active']['times_claimed'][(string)$taskId])) {
            if (is_file(__DIR__ . '/../../../runtime/config/enable_active_debug.txt')) {
                amf_append_log_line(
                    __DIR__ . '/../../../runtime/logs/active_debug.log',
                    sprintf('[%s] duty.reward active uid=%d taskId=%d need=%d spent=%d result=already', date('Y-m-d H:i:s'), $uid, $taskId, $need, $spentToday)
                );
            }
            return ['state' => 1, 'already' => 1, 'task_id' => $taskId, 'active_point_gain' => 0, 'up_grade' => []];
        }
        $state['active']['times_claimed'][(string)$taskId] = 1;
        // Recompute point from all reached thresholds, independent from claim order.
        $totalPoint = 0;
        foreach ($defs as $d) {
            if ($spentToday >= (int)($d['need'] ?? 0)) {
                $totalPoint += max(0, (int)($d['point'] ?? 20));
            }
        }
        $state['active']['point'] = $totalPoint;
        amf_shop_state_save($uid, $state);
        if (is_file(__DIR__ . '/../../../runtime/config/enable_active_debug.txt')) {
            amf_append_log_line(
                __DIR__ . '/../../../runtime/logs/active_debug.log',
                sprintf('[%s] duty.reward active uid=%d taskId=%d need=%d spent=%d gain=%d total=%d result=ok', date('Y-m-d H:i:s'), $uid, $taskId, $need, $spentToday, $point, $totalPoint)
            );
        }
        return [
            'state' => 1,
            'already' => 0,
            'task_id' => $taskId,
            'active_point_gain' => $point,
            'active_point_total' => $totalPoint,
            'server_time' => amf_server_time(),
            'up_grade' => [],
        ];
    }

    if (!is_array($task)) {
        return ['state' => 0, 'error' => 'task not found', 'up_grade' => []];
    }

    // Level-task robust path: ignore incoming id drift/replay and claim the next real claimable tier.
    $isLevelReq = ($taskType === 1) || amf_is_level_task_row($task) || ($taskId >= 101000 && $taskId < 102000);
    if ($isLevelReq) {
        $userGrade = max(1, (int)($state['user']['grade'] ?? 1));
        $next = amf_duty_pick_next_claimable_level_task($data, $claimed, $userGrade);
        if ($next === null) {
            return ['state' => 1, 'already' => 1, 'up_grade' => []];
        }
        $task = $next['task'];
        $taskId = (int)$next['id'];
    }
    $needLevel = null;
    if (amf_is_level_task_row($task)) {
        // Stable mapping: level-task id 101001..101199 => 5..995
        // Avoid deriving from claimed count (historical mixed IDs can skew milestone rewards).
        $seq = (int)$taskId - 101000;
        if ($seq > 0) {
            $needLevel = $seq * 5;
        } else {
            $needLevel = 5;
        }
        $userGrade = max(1, (int)($state['user']['grade'] ?? 1));
        if ($userGrade < $needLevel) {
            return ['state' => 0, 'error' => 'level not reached', 'need_level' => $needLevel, 'up_grade' => []];
        }
    } else {
        // non-level tasks keep old gate behavior (if any)
        $needLevelById = amf_duty_level_task_need_level($data, $taskId);
        if ($needLevelById !== null) {
            $userGrade = max(1, (int)($state['user']['grade'] ?? 1));
            if ($userGrade < $needLevelById) {
                return ['state' => 0, 'error' => 'level not reached', 'need_level' => $needLevelById, 'up_grade' => []];
            }
        }
    }

    $taskKey = (string)$taskId;
    if (isset($claimed[$taskKey])) {
        // Level-task sequential claim fallback:
        // if client keeps sending an already-claimed id, auto-advance to next
        // unclaimed level task that user level has reached.
        if (amf_is_level_task_row($task)) {
            $userGrade = max(1, (int)($state['user']['grade'] ?? 1));
            $rows = is_array($data['mainTask'] ?? null) ? $data['mainTask'] : [];
            usort($rows, static function ($a, $b): int {
                $ia = is_array($a) ? (int)($a['id'] ?? 0) : 0;
                $ib = is_array($b) ? (int)($b['id'] ?? 0) : 0;
                return $ia <=> $ib;
            });
            $found = false;
            foreach ($rows as $row) {
                if (!is_array($row) || !amf_is_level_task_row($row)) {
                    continue;
                }
                $rid = (int)($row['id'] ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                $rkey = (string)$rid;
                if (isset($claimed[$rkey])) {
                    continue;
                }
                $seq = $rid - 101000;
                $need = $seq > 0 ? ($seq * 5) : 5;
                if ($userGrade < $need) {
                    continue;
                }
                $task = $row;
                $taskId = $rid;
                $taskKey = $rkey;
                $needLevel = $need;
                $found = true;
                break;
            }
            if (!$found) {
                return ['state' => 1, 'already' => 1, 'up_grade' => []];
            }
        } else {
            return ['state' => 1, 'already' => 1, 'up_grade' => []];
        }
    }

    $reward = is_array($task['reward'] ?? null) ? $task['reward'] : [];
    $expGain = max(0, (int)($reward['exp'] ?? 0));
    $goldGain = max(0, (int)($reward['money'] ?? 0));
    $tools = is_array($reward['tools'] ?? null) ? $reward['tools'] : [];
    $milestoneVipCard = 0;
    if ($needLevel !== null && $needLevel > 0 && ($needLevel % 50) === 0) {
        // Milestone reward set (fixed): two materials + 90-day VIP season card.
        $milestoneVipCard = 1;
        $tools = [
            ['id' => 103, 'num' => 1000],
            ['id' => 93, 'num' => 1000],
            ['id' => 500, 'num' => 1],
        ];
    }

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        if ($goldGain > 0) {
            amf_wallet_add($pdo, $uid, 'gold', $goldGain);
            amf_inventory_delta_log($uid, 'gold', $goldGain, 'api.duty.reward');
        }
        foreach ($tools as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = max(0, (int)($it['id'] ?? 0));
            $num = max(0, (int)($it['num'] ?? 0));
            if ($id <= 0 || $num <= 0) {
                continue;
            }
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            amf_inventory_add($pdo, $uid, $iid, $num);
            amf_inventory_delta_log($uid, $iid, $num, 'api.duty.reward');
        }

        $levelInfo = amf_user_add_exp($state, $expGain);
        if (!isset($state['duty_claimed']) || !is_array($state['duty_claimed'])) {
            $state['duty_claimed'] = [];
        }
        if (!isset($state['duty_claimed'][$today]) || !is_array($state['duty_claimed'][$today])) {
            $state['duty_claimed'][$today] = [];
        }
        $state['duty_claimed'][$today][$taskKey] = 1;
        if ($needLevel !== null && $needLevel > 0 && amf_is_level_task_row($task)) {
            // Collapse lower level tasks: claiming current tier auto-finishes lower tiers.
            $rows = is_array($data['mainTask'] ?? null) ? $data['mainTask'] : [];
            foreach ($rows as $r) {
                if (!is_array($r) || !amf_is_level_task_row($r)) {
                    continue;
                }
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) {
                    continue;
                }
                $seq = $rid - 101000;
                $need = $seq > 0 ? ($seq * 5) : 5;
                if ($need <= $needLevel) {
                    $state['duty_claimed'][$today][(string)$rid] = 1;
                }
            }
        }
        amf_shop_state_save($uid, $state);
        $pdo->commit();

        return [
            'state' => 1,
            'already' => 0,
            'task_id' => $taskId,
            'exp_gain' => $expGain,
            'gold_gain' => $goldGain,
            'vip_card_90d_gain' => $milestoneVipCard,
            'up_grade' => (array)($levelInfo['up_grade'] ?? []),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['state' => 0, 'error' => $e->getMessage(), 'up_grade' => []];
    }
};

// Shop: keep data editable via runtime/config/amf/*.json
$shopInit = static function (): mixed {
    $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.shop.init.json';
    if (is_file($cfgPath)) {
        $json = file_get_contents($cfgPath);
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $ctx = RequestContext::get();
                $uid = (int)($ctx['user_id'] ?? 0);
                if ($uid > 0) {
                    $pdo = DB::pdo();
                    $wallet = amf_wallet_get($pdo, $uid, 'gold');
                    $data['money'] = $wallet;
                    $st = amf_shop_state_load($uid);
                    $today = date('Y-m-d');
                    $data['limit_state'] = (array)($st['shop_limits'][$today] ?? []);
                }
                return $data;
            }
        }
    }

    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $money = 0;
    $cusMoney = 0;
    $limitState = [];
    if ($uid > 0) {
        $pdo = DB::pdo();
        $money = (int)amf_wallet_get($pdo, $uid, 'gold');
        $cusMoney = (int)amf_wallet_get($pdo, $uid, 'money');
        $st = amf_shop_state_load($uid);
        $today = date('Y-m-d');
        $limitState = (array)($st['shop_limits'][$today] ?? []);
    }
    return [
        'money' => $money,
        'cus_money' => $cusMoney,
        'limit_state' => $limitState,
        'goods' => [],
    ];
};

$shopGetMerchandises = static function () use ($raw): mixed {
    // Request params are like: [shopTypeId]
    $shopTypeId = null;
    try {
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($raw);
        $r = new AmfByteReader($reqBody);
        $params = Amf0::readValueDecode($r);
        if (is_array($params) && array_is_list($params) && count($params) > 0) {
            $shopTypeId = (int)$params[0];
        }
    } catch (Throwable $e) {
        // ignore; will fall back
    }

    if ($shopTypeId !== null && $shopTypeId > 0) {
        // Preferred: single merged table.
        $mergedPath = __DIR__ . '/../../../runtime/config/amf/api.shop.getMerchandises.json';
        if (is_file($mergedPath)) {
            $json = file_get_contents($mergedPath);
            if (is_string($json) && $json !== '') {
                $all = json_decode($json, true);
                $k = (string)$shopTypeId;
                if (is_array($all) && isset($all[$k]) && is_array($all[$k])) {
                    $rows = $all[$k];
                    $ctx = RequestContext::get();
                    $uid = (int)($ctx['user_id'] ?? 0);
                    if ($uid > 0 && is_array($rows)) {
                        $pdo = DB::pdo();
                        $state = amf_shop_state_load($uid);
                        $today = date('Y-m-d');
                        $limits = (array)($state['shop_limits'][$today] ?? []);
                        foreach ($rows as &$row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $price = (int)($row['price'] ?? 0);
                            $exchangeRaw = (string)($row['exchange_tool_id'] ?? 'gold');
                            $wallet = amf_exchange_balance_get($pdo, $uid, $exchangeRaw);
                            $id = (string)($row['id'] ?? '0');
                            $bought = (int)($limits[$id] ?? 0);
                            $dailyCap = isset($row['num']) ? max(0, (int)$row['num']) : 9999;
                            $left = max(0, $dailyCap - $bought);
                            $row['leftCount'] = $left;
                            $row['canBuy'] = ($wallet >= $price && $left > 0) ? 1 : 0;
                        }
                        unset($row);
                    }
                    return $rows;
                }
            }
        }

        // Back-compat: per-type files.
        $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.shop.getMerchandises/' . $shopTypeId . '.json';
        if (is_file($cfgPath)) {
            $json = file_get_contents($cfgPath);
            if (is_string($json) && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
    }

    // Special-case: type=3 (rmb) often uses init.goods; we didn't capture a dedicated getMerchandises response.
    if ($shopTypeId === 3) {
        $initCfg = __DIR__ . '/../../../runtime/config/amf/api.shop.init.json';
        if (is_file($initCfg)) {
            $json = file_get_contents($initCfg);
            if (is_string($json) && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data) && isset($data['goods']) && is_array($data['goods'])) {
                    return $data['goods'];
                }
            }
        }
    }

    return [];
};

$shopBuy = static function () use ($raw): mixed {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['status' => 'failed', 'exception' => ['message' => 'guest cannot buy', 'money' => 0, 'call' => null]];
    }
    $params = amf_decode_first_params($raw);
    $arr = is_array($params) && array_is_list($params) ? $params : [$params];

    // Prefer explicit positional ints (matches current amf_method.log p0/p1).
    $p0 = amf_extract_param_int_at($raw, 0);
    $p1 = amf_extract_param_int_at($raw, 1);
    $p2 = amf_extract_param_int_at($raw, 2);

    $merchId = 0;
    $qty = 1;
    // Some calls are [cmd, merchId, qty], others are [merchId, qty].
    // Determine merchandise id by checking which int actually exists in merchandise table.
    $ints = [];
    foreach ([$p0, $p1, $p2] as $v) {
        if (is_int($v) && $v > 0) {
            $ints[] = $v;
        }
    }
    foreach ($arr as $v) {
        if (is_int($v) && $v > 0) {
            $ints[] = $v;
        } elseif (is_string($v) && preg_match('/^\d+$/', $v)) {
            $iv = (int)$v;
            if ($iv > 0) {
                $ints[] = $iv;
            }
        }
    }
    $ints = array_values(array_unique($ints));
    foreach ($ints as $cand) {
        if (is_array(amf_shop_find_merchandise((int)$cand))) {
            $merchId = (int)$cand;
            break;
        }
    }
    if ($p2 !== null && $p2 > 0) {
        $qty = $p2;
    } elseif ($p1 !== null && $p1 > 0 && $p1 !== $merchId) {
        $qty = $p1;
    }

    // Fallback: derive from decoded payload variants.
    if ($merchId <= 0) {
        foreach ($arr as $v) {
            if ((is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v))) && (int)$v > 0) {
                $merchId = (int)$v;
                break;
            }
            if (is_array($v)) {
                foreach (['id', 'goods_id', 'shop_id', 'item_id', 'tool_id'] as $k) {
                    if (isset($v[$k]) && preg_match('/^\d+$/', (string)$v[$k])) {
                        $merchId = (int)$v[$k];
                        break 2;
                    }
                }
            }
        }
    }
    if ($qty <= 0) {
        $qty = $p2 !== null && $p2 > 0 ? $p2 : 1;
    }
    if ($merchId <= 0) {
        return ['status' => 'failed', 'exception' => ['message' => 'invalid merchandise id', 'money' => 0, 'call' => null]];
    }
    $item = amf_shop_find_merchandise($merchId);
    if (!is_array($item)) {
        return ['status' => 'failed', 'exception' => ['message' => 'merchandise not found', 'money' => 0, 'call' => null]];
    }

    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayToken = amf_extract_replay_token($raw);
    $replayKey = $uid . ':' . $replayToken;
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.shop.buy');
    if (is_array($cached)) {
        amf_txn_log($uid, 'api.shop.buy', $replayKey, ['currency' => 0], [], $startTs, true);
        return $cached;
    }

    $exchangeRaw = (string)($item['exchange_tool_id'] ?? 'gold');
    $currency = amf_exchange_item_id_from_raw($exchangeRaw);
    $price = max(0, (int)($item['price'] ?? 0));
    $totalPrice = $price * max(1, $qty);
    $itemType = (string)($item['type'] ?? 'tool');
    $isOrganismType = ($itemType === 'organisms' || $itemType === 'organism');
    $rewardItemId = (string)($item['p_id'] ?? '');
    $rewardQty = max(1, $qty);

    $today = date('Y-m-d');
    $state = amf_shop_state_load($uid);
    $limits = (array)($state['shop_limits'][$today] ?? []);
    $idKey = (string)$merchId;
    $bought = (int)($limits[$idKey] ?? 0);
    $dailyCap = isset($item['num']) ? max(0, (int)$item['num']) : 9999;
    if ($bought + $qty > $dailyCap) {
        return ['status' => 'failed', 'exception' => ['message' => 'limit reached', 'money' => 0, 'call' => null]];
    }

    $orgDao = new OrgDao($pdo);
    $orgDao->ensureTable();
    $pdo->beginTransaction();
    try {
        if (!amf_exchange_deduct($pdo, $uid, $exchangeRaw, $totalPrice)) {
            throw new RuntimeException('insufficient currency');
        }
        $invItem = $isOrganismType ? ('org:' . $rewardItemId) : ('tool:' . $rewardItemId);
        amf_inventory_add($pdo, $uid, $invItem, $rewardQty);
        $newAmount = amf_inventory_get_qty($pdo, $uid, $invItem);
        if ($isOrganismType) {
            // Plant merchandise must create real organism rows; warehouse list reads from organisms table.
            $tplId = max(1, (int)$rewardItemId);
            $seed = amf_org_shop_seed_template($tplId);
            for ($i = 0; $i < $rewardQty; $i++) {
                $orgDao->upsertOrg($uid, [
                    'tpl_id' => $tplId,
                    'level' => (int)$seed['level'],
                    'quality' => (int)$seed['quality'],
                    'quality_name' => (string)$seed['quality_name'],
                    'exp' => (int)$seed['exp'],
                    'mature' => (int)$seed['mature'],
                    'hp' => (int)$seed['hp'],
                    'hp_max' => (int)$seed['hp_max'],
                    'attack' => (int)$seed['attack'],
                    'miss' => (int)$seed['miss'],
                    'precision_val' => (int)$seed['precision_val'],
                    'new_miss' => (int)$seed['new_miss'],
                    'new_precision' => (int)$seed['new_precision'],
                    'speed' => (int)$seed['speed'],
                    'fight' => (int)$seed['fight'],
                ]);
            }
        }

        $limits[$idKey] = $bought + $qty;
        $state['shop_limits'][$today] = $limits;
        $state['inventory_dirty'] = 1;
        $state['inventory_dirty_at'] = date('Y-m-d H:i:s');
        amf_shop_state_save($uid, $state);

        // Match client expectation (BuyGoodsWindow):
        // status == "success", and either tool{id,amount} or organisms.
        $resp = ['status' => 'success'];
        if ($isOrganismType) {
            $resp['organisms'] = [$rewardItemId];
        } else {
            $resp['tool'] = [
                'id' => (int)$rewardItemId,
                'amount' => $newAmount,
            ];
        }
        $orderKey = hash('sha256', $uid . '|api.shop.buy|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
        $resp['order_key'] = $orderKey;
        if ($currency === 'gold' || $currency === 'money') {
            $resp['wallet'] = amf_wallet_get($pdo, $uid, $currency);
        } else {
            $resp['wallet'] = amf_exchange_balance_get($pdo, $uid, $exchangeRaw);
        }
        amf_order_save($pdo, $orderKey, $uid, 'api.shop.buy', $arr, $resp);
        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.shop.buy', $resp, 3);
        $pdo->commit();
        amf_txn_log(
            $uid,
            'api.shop.buy',
            $replayKey,
            ['currency' => -$totalPrice, 'currency_type' => $currency],
            [['item_id' => $invItem, 'qty' => $rewardQty]],
            $startTs,
            false
        );
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        amf_txn_log(
            $uid,
            'api.shop.buy',
            $replayKey,
            ['currency' => 0, 'error' => $e->getMessage()],
            [],
            $startTs,
            false
        );
        return [
            'status' => 'failed',
            'exception' => [
                'message' => $e->getMessage(),
                'money' => 0,
                'call' => null,
            ],
        ];
    }
};

$shopSell = static function () use ($raw): mixed {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return 0;
    }
    $params = amf_decode_first_params($raw);
    $arr = is_array($params) && array_is_list($params) ? $params : [$params];
    $p0 = amf_extract_param_int_at($raw, 0);
    $p1 = amf_extract_param_int_at($raw, 1);
    $p2 = amf_extract_param_int_at($raw, 2);

    // Usually: [cmd, itemId, qty] or [itemId, qty]
    $itemIdNum = 0;
    $qty = 1;
    if ($p0 !== null && $p0 >= 0 && $p0 <= 20 && ($p1 ?? 0) > 0) {
        $itemIdNum = (int)$p1;
        $qty = max(1, (int)($p2 ?? 1));
    } else {
        $itemIdNum = max(0, (int)($p0 ?? 0));
        $qty = max(1, (int)($p1 ?? 1));
    }
    if ($itemIdNum <= 0) {
        foreach ($arr as $v) {
            if (is_int($v) && $v > 0) {
                $itemIdNum = $v;
                break;
            }
            if (is_string($v) && preg_match('/^\d+$/', $v)) {
                $itemIdNum = (int)$v;
                break;
            }
        }
    }
    if ($itemIdNum <= 0) {
        return 0;
    }
    $sellPrice = amf_item_sell_price($itemIdNum);
    $invItem = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayKey = $uid . ':' . amf_extract_replay_token($raw);
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.shop.sell');
    if ($cached !== null) {
        amf_txn_log($uid, 'api.shop.sell', $replayKey, ['gold' => 0], [], $startTs, true);
        return $cached;
    }
    $pdo->beginTransaction();
    try {
        if (!amf_inventory_remove($pdo, $uid, $invItem, $qty)) {
            throw new RuntimeException('not enough inventory');
        }
        $gain = max(0, $sellPrice * $qty);
        amf_inventory_add($pdo, $uid, 'gold', $gain);
        amf_mark_inventory_dirty($uid);
        $resp = $gain;
        $orderKey = hash('sha256', $uid . '|api.shop.sell|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
        amf_order_save($pdo, $orderKey, $uid, 'api.shop.sell', $arr, ['money' => $gain, 'item' => $invItem, 'qty' => $qty]);
        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.shop.sell', $resp, 3);
        $pdo->commit();
        amf_txn_log(
            $uid,
            'api.shop.sell',
            $replayKey,
            ['gold' => $gain],
            [['item_id' => $invItem, 'qty' => -$qty]],
            $startTs,
            false
        );
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        amf_txn_log(
            $uid,
            'api.shop.sell',
            $replayKey,
            ['gold' => 0, 'error' => $e->getMessage()],
            [],
            $startTs,
            false
        );
        return 0;
    }
};

$toolUseOf = static function () use ($raw): mixed {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return amf_runtime_error_payload(amf_trace_id(), 'guest cannot use tool');
    }

    $input = amf_parse_tool_use_input($raw);
    $toolId = (int)$input['itemId'];
    $count = max(1, (int)$input['count']);
    $targetId = (int)$input['targetId'];
    $extra = $input['extra'];
    if ($toolId <= 0) {
        $p0 = amf_extract_param_int_at($raw, 0);
        $p1 = amf_extract_param_int_at($raw, 1);
        $p2 = amf_extract_param_int_at($raw, 2);
        if (is_int($p0) && $p0 > 0) {
            $toolId = $p0;
            $count = is_int($p1) && $p1 > 0 ? $p1 : 1;
            if ($targetId <= 0 && is_int($p2) && $p2 > 0) {
                $targetId = $p2;
            }
        } elseif (is_int($p1) && $p1 > 0) {
            $toolId = $p1;
            $count = is_int($p2) && $p2 > 0 ? $p2 : 1;
        }
    }
    if ($toolId <= 0) {
        return amf_runtime_error_payload(amf_trace_id(), 'invalid tool id');
    }

    if (amf_tool_use_debug_enabled()) {
        $line = sprintf(
            '[%s] uid=%d itemId=%d count=%d targetId=%d params=%s extra=%s',
            date('Y-m-d H:i:s'),
            $uid,
            $toolId,
            $count,
            $targetId,
            json_encode($input['rawParams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        amf_append_log_line(__DIR__ . '/../../../runtime/logs/tool_use_req.log', $line);
    }

    $pdo = DB::pdo();
    $invItem = 'tool:' . $toolId;
    $token = amf_extract_replay_token($raw);
    $replayKey = $uid . ':' . $token;
    amf_replay_guard_ensure_table($pdo);
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.tool.useOf');
    if (is_array($cached)) {
        amf_txn_log($uid, 'api.tool.useOf', $replayKey, ['currency' => 0], [], $startTs, true);
        return $cached;
    }

    $effectsCfg = amf_load_items_effects();
    $effect = $effectsCfg[(string)$toolId] ?? ['type' => 'none'];

    $pdo->beginTransaction();
    try {
        if (!amf_inventory_remove($pdo, $uid, $invItem, $count)) {
            throw new RuntimeException('not enough tool amount');
        }

        $effectType = (string)($effect['type'] ?? 'none');
        $tip = amf_lang_get('server.tool.use_success', 'USE_SUCCESS');
        $effectCode = 1;
        $nameCode = 0;
        $itemsDelta = [];
        $delta = ['gold' => 0, 'money' => 0, 'exp' => 0];

        if ($effectType === 'currency') {
            $goldDelta = max(0, (int)($effect['gold'] ?? 0)) * $count;
            $diamondDelta = max(0, (int)($effect['diamond'] ?? 0)) * $count;
            if ($goldDelta > 0) {
                amf_inventory_add($pdo, $uid, 'gold', $goldDelta);
                $delta['gold'] += $goldDelta;
            }
            if ($diamondDelta > 0) {
                amf_inventory_add($pdo, $uid, 'money', $diamondDelta);
                $delta['money'] += $diamondDelta;
            }
            if ($goldDelta > 0 && $diamondDelta > 0) {
                $tip = amf_lang_fmt('server.tool.tip.currency_both', [$goldDelta, $diamondDelta], 'GOLD+%1 MONEY+%2');
            } elseif ($goldDelta > 0) {
                $tip = amf_lang_fmt('server.tool.tip.currency_gold', [$goldDelta], 'GOLD+%1');
            } elseif ($diamondDelta > 0) {
                $tip = amf_lang_fmt('server.tool.tip.currency_money', [$diamondDelta], 'MONEY+%1');
            }
            $nameCode = 4;
        } elseif ($effectType === 'give_item') {
            $giveId = (int)($effect['itemId'] ?? 0);
            $giveCount = max(1, (int)($effect['count'] ?? 1)) * $count;
            if ($giveId > 0 && $giveCount > 0) {
                $iid = 'tool:' . $giveId;
                amf_inventory_add($pdo, $uid, $iid, $giveCount);
                $itemsDelta[] = ['item_id' => $iid, 'qty' => $giveCount];
                $tip = amf_lang_fmt('server.tool.tip.get_item', [$giveId, $giveCount], 'GET_ITEM %1 x%2');
            }
            $nameCode = 4;
        } elseif ($effectType === 'open_box') {
            $boxId = (int)($effect['boxId'] ?? 0);
            $boxCount = max(1, (int)($effect['count'] ?? 1)) * $count;
            if ($boxId > 0) {
                $ob = amf_apply_openbox_effect($pdo, $uid, $boxId, $boxCount);
                foreach ((array)($ob['tools'] ?? []) as $it) {
                    if (is_array($it)) {
                        $itemsDelta[] = ['item_id' => 'tool:' . (int)($it['id'] ?? 0), 'qty' => (int)($it['amount'] ?? 0)];
                    }
                }
                $delta['gold'] += (int)($ob['gold_delta'] ?? 0);
                $delta['money'] += (int)($ob['diamond_delta'] ?? 0);
                $delta['exp'] += (int)($ob['exp_delta'] ?? 0);
            }
            $effectCode = 3;
            $nameCode = 3;
            $tip = amf_lang_get('server.tool.tip.open_box', 'OPEN_BOX_OK');
        } elseif ($effectType === 'times_delta' || $effectType === 'counter_add') {
            $key = (string)($effect['key'] ?? '');
            $d = (int)($effect['delta'] ?? 0) * $count;
            if ($key !== '' && $d !== 0) {
                $state = amf_shop_state_load($uid);
                amf_state_add_counter($state, $key, $d);
                amf_shop_state_save($uid, $state);
                $effectCode = max(1, abs($d));
                $nameCode = 4;
                $tip = ($d > 0)
                    ? amf_lang_fmt('server.tool.tip.times_add', [$d], 'ADD %1 TIMES')
                    : amf_lang_fmt('server.tool.tip.times_sub', [abs($d)], 'SUB %1 TIMES');
            }
        } elseif ($effectType === 'vip_days' || $effectType === 'vip_extend') {
            $seconds = 0;
            $daysAdded = 0;
            if (isset($effect['seconds'])) {
                $seconds = max(1, (int)$effect['seconds']) * $count;
                $daysAdded = (int)max(1, floor($seconds / 86400));
            } else {
                $daysAdded = max(1, (int)($effect['days'] ?? 1)) * $count;
                $seconds = $daysAdded * 86400;
            }
            $grade = max(1, (int)($effect['grade'] ?? 1));
            $state = amf_shop_state_load($uid);
            amf_state_extend_vip($state, $seconds, $grade);
            amf_shop_state_save($uid, $state);
            $effectCode = 30;
            $nameCode = 3;
            $tip = amf_lang_fmt('server.tool.tip.vip_add_days', [$daysAdded], 'VIP +%1 DAYS');
        } elseif ($effectType === 'state_flag') {
            $key = (string)($effect['key'] ?? '');
            if ($key !== '') {
                $state = amf_state_normalize(amf_shop_state_load($uid));
                $state[$key] = $effect['value'] ?? 1;
                amf_shop_state_save($uid, $state);
            }
            $nameCode = 4;
        }

        amf_mark_inventory_dirty($uid);
        $stateAfter = amf_state_normalize(amf_shop_state_load($uid));
        $resp = [
            'effect' => $effectCode,
            'name' => $nameCode,
            'tip' => $tip,
            'gold' => amf_wallet_get($pdo, $uid, 'gold'),
            'diamond' => amf_wallet_get($pdo, $uid, 'money'),
            'vip_grade' => (int)($stateAfter['vip']['grade'] ?? 0),
            'vip_etime' => (int)($stateAfter['vip']['etime'] ?? 0),
            'vip_exp' => (int)($stateAfter['vip']['exp'] ?? 0),
            'stone_cha_count' => (int)($stateAfter['counters']['stone_cha_count'] ?? 0),
        ];
        if ($itemsDelta !== []) {
            $resp['items'] = $itemsDelta;
        }

        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.tool.useOf', $resp, 3);
        $pdo->commit();
        $itemsDelta[] = ['item_id' => $invItem, 'qty' => -$count];
        amf_txn_log($uid, 'api.tool.useOf', $replayKey, $delta, $itemsDelta, $startTs, false);
        amf_append_log_line(
            __DIR__ . '/../../../runtime/logs/tool_use.log',
            sprintf('[%s] uid=%d tool_id=%d count=%d tip=%s', date('Y-m-d H:i:s'), $uid, $toolId, $count, $tip)
        );
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getMessage() === 'not enough tool amount') {
            return ['effect' => 0, 'name' => 4, 'error' => 'not enough tool amount'];
        }
        amf_txn_log(
            $uid,
            'api.tool.useOf',
            $replayKey,
            ['gold' => 0, 'money' => 0, 'exp' => 0, 'error' => $e->getMessage()],
            [['item_id' => $invItem, 'qty' => 0]],
            $startTs,
            false
        );
        return amf_runtime_error_payload(amf_trace_id(), $e->getMessage());
    }
};
$shopGemExchange = static function () use ($raw): mixed {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return 0;
    }
    // callOOO sends 3 params: (targetId, costId, composeNum).
    // Keep compatibility with older 4-param probes: (cmd, targetId, costId, composeNum).
    $p0 = amf_extract_param_int_at($raw, 0);
    $p1 = amf_extract_param_int_at($raw, 1);
    $p2 = amf_extract_param_int_at($raw, 2);
    $p3 = amf_extract_param_int_at($raw, 3);
    $cmd = 1;
    $targetId = 0;
    $costId = 0;
    $num = 1;
    if ($p3 !== null) {
        $cmd = max(0, (int)($p0 ?? 0));
        $targetId = max(0, (int)($p1 ?? 0));
        $costId = max(0, (int)($p2 ?? 0));
        $num = max(1, (int)$p3);
    } else {
        $targetId = max(0, (int)($p0 ?? 0));
        $costId = max(0, (int)($p1 ?? 0));
        $num = max(1, (int)($p2 ?? 1));
    }
    if ($num > 9999) {
        $num = 9999;
    }
    if ($targetId <= 0 || $costId <= 0 || $num <= 0) {
        amf_txn_log($uid, 'api.shop.gemExchange', $uid . ':invalid', ['error' => 'invalid params'], [], $startTs, false);
        return 0;
    }

    $cfg = amf_load_cfg_json('recipes/gem_exchange.json');
    if (!is_array($cfg) || !isset($cfg[(string)$targetId]) || !is_array($cfg[(string)$targetId])) {
        amf_missing_config_log('api.shop.gemExchange', ['cmd' => $cmd, 'targetId' => $targetId, 'costId' => $costId, 'num' => $num]);
        amf_txn_log($uid, 'api.shop.gemExchange', $uid . ':missing_cfg', ['error' => 'missing gem exchange config'], [], $startTs, false);
        return 0;
    }
    $rule = $cfg[(string)$targetId];
    $inputs = is_array($rule['inputs'] ?? null) ? $rule['inputs'] : [];
    $outputs = is_array($rule['outputs'] ?? null) ? $rule['outputs'] : [];
    if ($costId > 0 && isset($inputs[0]) && is_array($inputs[0])) {
        $inputs[0]['itemId'] = $costId;
    } elseif ($costId > 0 && $inputs === []) {
        $inputs = [['itemId' => $costId, 'qty' => 1]];
    }
    if ($outputs === []) {
        $outputs = [['itemId' => $targetId, 'qty' => 1]];
    }
    $goldCost = max(0, (int)($rule['cost']['gold'] ?? 0)) * $num;
    $diamondCost = max(0, (int)($rule['cost']['diamond'] ?? 0)) * $num;
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $itemsDelta = [];
        foreach ($inputs as $it) {
            if (!is_array($it)) continue;
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = max(1, (int)($it['qty'] ?? 1)) * $num;
            if ($iid === 'tool:0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) {
                throw new RuntimeException('not enough input');
            }
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.shop.gemExchange');
            $itemsDelta[] = ['item_id' => substr($iid, 5), 'qty' => -$qty];
        }
        if ($goldCost > 0 && !amf_wallet_deduct($pdo, $uid, 'gold', $goldCost)) throw new RuntimeException('not enough gold');
        if ($diamondCost > 0 && !amf_wallet_deduct($pdo, $uid, 'money', $diamondCost)) throw new RuntimeException('not enough diamond');
        if ($goldCost > 0) amf_inventory_delta_log($uid, 'gold', -$goldCost, 'api.shop.gemExchange');
        if ($diamondCost > 0) amf_inventory_delta_log($uid, 'money', -$diamondCost, 'api.shop.gemExchange');

        foreach ($outputs as $ot) {
            if (!is_array($ot)) continue;
            $oid = max(0, (int)($ot['itemId'] ?? 0));
            $oq = max(1, (int)($ot['qty'] ?? 1)) * $num;
            if ($oid <= 0) continue;
            $inv = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            amf_inventory_add($pdo, $uid, $inv, $oq);
            amf_inventory_delta_log($uid, $inv, $oq, 'api.shop.gemExchange');
            $itemsDelta[] = ['item_id' => $oid, 'qty' => $oq];
        }
        $newTargetAmount = amf_inventory_get_qty($pdo, $uid, 'tool:' . $targetId);
        $pdo->commit();
        amf_mark_inventory_dirty($uid);
        amf_txn_log(
            $uid,
            'api.shop.gemExchange',
            $uid . ':gem:' . $targetId . ':' . $costId . ':' . $num . ':' . substr(sha1($raw), 0, 8),
            ['gold' => -$goldCost, 'money' => -$diamondCost],
            $itemsDelta,
            $startTs,
            false
        );
        return $newTargetAmount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        amf_txn_log(
            $uid,
            'api.shop.gemExchange',
            $uid . ':gem:' . $targetId . ':' . $costId . ':' . $num . ':err',
            ['error' => $e->getMessage()],
            [],
            $startTs,
            false
        );
        return 0;
    }
};

// Skill configs (used by OrganismWindow and other UI). Keep editable via runtime/config/amf/*.json.
$skillGetAll = static function (): mixed {
    $cfg = amf_load_cfg_json('skills/all.json');
    if (is_array($cfg)) {
        return $cfg;
    }
    amf_missing_config_log('api.apiskill.getAllSkills', ['path' => 'runtime/config/skills/all.json']);
    $fallback = amf_load_runtime_json('api.apiskill.getAllSkills');
    return is_array($fallback) ? $fallback : [];
};

$specSkillGetAll = static function (): mixed {
    $cfg = amf_skill_defs_spec();
    if (is_array($cfg) && $cfg !== []) {
        return $cfg;
    }
    amf_missing_config_log('api.apiskill.getSpecSkillAll', ['path' => 'runtime/config/skills/spec.json']);
    $fallback = amf_load_runtime_json('api.apiskill.getSpecSkillAll');
    return is_array($fallback) ? $fallback : [];
};

// Registration / sign-in / active reward system ("api.active.*") uses AMF responses.
$activeGetSignInfo = static function (): mixed {
    $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.active.getSignInfo.json';
    $base = null;
    if (is_file($cfgPath)) {
        $json = file_get_contents($cfgPath);
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $base = $data;
            }
        }
    }

    $ctx = RequestContext::get();
    $userId = (int)($ctx['user_id'] ?? 0);
    if ($userId > 0 && is_array($base)) {
        $store = new StateStore();
        $loaded = $store->load($userId);
        $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
        $state = amf_state_normalize($state);
        $active = amf_state_prepare_active_daily($state);
        $spentToday = amf_active_effective_spent_today($userId, $state);
        $activePoint = 0;
        foreach (amf_active_welfare_task_defs() as $def) {
            if ($spentToday >= (int)($def['need'] ?? 0)) {
                $activePoint += max(0, (int)($def['point'] ?? 20));
            }
        }
        $state['active']['point'] = $activePoint;
        amf_shop_state_save($userId, $state);
        $sign = is_array($state['sign'] ?? null) ? $state['sign'] : [];

        $lastDate = (string)($sign['last_sign_date'] ?? '');
        $signedDates = is_array($sign['signed_dates'] ?? null) ? $sign['signed_dates'] : [];
        $totalCount = count($signedDates) > 0
            ? count(array_filter($signedDates, static fn($v): bool => (int)$v > 0))
            : (int)($sign['total_sign_count'] ?? 0);
        $today = date('Y-m-d');
        $alreadySigned = ($lastDate === $today);
        $todayDay = (int)date('j');
        $monthPrefix = date('Y-m-');

        // Inject state projection while preserving original structure.
        $base['last_sign_date'] = $lastDate;
        $base['total_sign_count'] = $totalCount;
        $base['signcount'] = $totalCount;
        $base['sign_count'] = $totalCount;
        $base['already_signed_today'] = $alreadySigned ? 1 : 0;
        $base['signed_dates'] = $signedDates;
        $base['time'] = amf_server_time();
        $base['active'] = $activePoint;
        $base['activemax'] = 100;

        if (isset($base['missions']) && is_array($base['missions'])) {
            $defs = amf_active_welfare_task_defs();
            $needByOrdinal = [];
            foreach ($defs as $idx => $def0) {
                $needByOrdinal[(string)($idx + 1)] = max(1, (int)($def0['need'] ?? 1));
            }
            foreach ($base['missions'] as $i => &$m) {
                if (!is_array($m)) {
                    continue;
                }
                $need = 0;
                $missionId = (string)($m['id'] ?? '');
                if ($missionId !== '' && isset($needByOrdinal[$missionId])) {
                    $need = (int)$needByOrdinal[$missionId];
                }
                if ($need <= 0) {
                    $dis = (string)($m['dis'] ?? '');
                    if ($dis !== '' && preg_match('/(\d+)/u', $dis, $mm)) {
                        $need = max(1, (int)$mm[1]);
                    }
                }
                if ($need <= 0) {
                    $def = $defs[$i] ?? null;
                    if (is_array($def)) {
                        $need = max(1, (int)($def['need'] ?? 1));
                    }
                }
                $done = $spentToday >= $need;
                // Registration panel is binary-style for these daily missions.
                $m['countmax'] = 1;
                $m['count'] = $done ? 1 : 0;
                $m['active'] = 20;
                if (isset($m['dis']) && is_string($m['dis']) && $m['dis'] !== '') {
                    // Keep client text source stable, only patch threshold number.
                    $m['dis'] = preg_replace('/\\d+/', (string)$need, $m['dis'], 1) ?? $m['dis'];
                }
            }
            unset($m);
        }

        if (isset($base['activereward']) && is_array($base['activereward'])) {
            $claimedPoint = is_array($state['active']['point_claimed'] ?? null) ? $state['active']['point_claimed'] : [];
            foreach ($base['activereward'] as &$rw) {
                if (!is_array($rw)) {
                    continue;
                }
                $need = max(0, (int)($rw['count'] ?? 0));
                $rid = max(0, (int)($rw['id'] ?? 0));
                $k1 = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
                $k2 = (string)$rid;
                $k3 = (string)$need; // backward compatibility
                if (!empty($claimedPoint[$k1]) || !empty($claimedPoint[$k2]) || !empty($claimedPoint[$k3])) {
                    $rw['state'] = 2;
                } elseif ($activePoint >= $need) {
                    $rw['state'] = 1;
                } else {
                    $rw['state'] = 0;
                }
            }
            unset($rw);
        }

        if (isset($base['signreward']) && is_array($base['signreward'])) {
            $claimedSign = is_array($state['sign']['claimed_rewards'] ?? null) ? $state['sign']['claimed_rewards'] : [];
            foreach ($base['signreward'] as &$rw) {
                if (!is_array($rw)) {
                    continue;
                }
                $need = max(0, (int)($rw['count'] ?? 0));
                $rid = (int)($rw['id'] ?? 0);
                $k1 = (string)$rid;
                $k2 = (string)$need;
                $done = !empty($claimedSign[$k1]) || !empty($claimedSign[$k2]);
                if ($done) {
                    $rw['state'] = 2;
                } elseif ($totalCount >= $need) {
                    $rw['state'] = 1;
                } else {
                    $rw['state'] = 0;
                }
            }
            unset($rw);
        }

        if (isset($base['signs']) && is_array($base['signs'])) {
            foreach ($base['signs'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int)($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $dayKey = $monthPrefix . str_pad((string)$id, 2, '0', STR_PAD_LEFT);
                $signed = !empty($signedDates[$dayKey]);
                if ($signed) {
                    // Signed day.
                    $item['state'] = 2;
                    $item['count'] = max(0, (int)($item['count'] ?? 0));
                    // Sync known "claim status" fields only when they already exist in source JSON.
                    $ifExists = static function (array &$row, string $key, mixed $value): void {
                        if (array_key_exists($key, $row)) {
                            $row[$key] = $value;
                        }
                    };
                    $ifExists($item, 'canGet', 0);
                    $ifExists($item, 'can_get', 0);
                    $ifExists($item, 'isCanGet', 0);
                    $ifExists($item, 'is_can_get', 0);
                    $ifExists($item, 'got', 1);
                    $ifExists($item, 'isGot', 1);
                    $ifExists($item, 'is_got', 1);
                } elseif ($id === $todayDay) {
                    // Today unsigned -> claimable.
                    $item['state'] = 1;
                }
            }
            unset($item);
        }

        return $base;
    }

    $sig = (string)($ctx['sig'] ?? '');
    $capture = amf_capture_with_sig($sig, 'api.active.getSignInfo.rsp.latest.amf');
    if ($capture === false) {
        $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/api.active.getSignInfo.rsp.latest.amf');
    }
    if ($capture === false) {
        throw new RuntimeException('Missing capture: api.active.getSignInfo.rsp');
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        throw new RuntimeException('Failed to read capture: api.active.getSignInfo.rsp');
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

$activeSign = static function (): mixed {
    $ctx = RequestContext::get();
    $userId = (int)($ctx['user_id'] ?? 0);

    if ($userId <= 0) {
        // Rule A: guest/no-user requests are stateless.
        // Keep pure static response and do not read/write StateStore.
        $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.active.sign.json';
        if (is_file($cfgPath)) {
            $json = file_get_contents($cfgPath);
            if (is_string($json) && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data) || is_bool($data) || is_string($data) || is_int($data) || is_float($data)) {
                    return $data;
                }
            }
        }
        return 1;
    }

    $store = new StateStore();
    $loaded = $store->load($userId);
    $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
    $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];

    $today = date('Y-m-d');
    if (!isset($state['sign']) || !is_array($state['sign'])) {
        $state['sign'] = [];
    }
    $lastDate = (string)($state['sign']['last_sign_date'] ?? '');
    $alreadySigned = ($lastDate === $today);

    if (!$alreadySigned) {
        $state['sign']['last_sign_date'] = $today;
        $state['sign']['signed_dates'] = is_array($state['sign']['signed_dates'] ?? null) ? $state['sign']['signed_dates'] : [];
        $state['sign']['signed_dates'][$today] = 1;
        $state['sign']['total_sign_count'] = (int)($state['sign']['total_sign_count'] ?? 0) + 1;
        // First sign of the day: apply one reward event.
        EventApplier::apply($userId, [
            ['type' => 'InventoryAdded', 'item_id' => 'gold', 'qty' => 100],
        ]);
        $store->save($userId, $phase, $state);
    }

    // Keep response structure compatible with existing runtime config:
    // if current config is scalar(int), return int; if object, patch state-like fields.
    $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.active.sign.json';
    if (is_file($cfgPath)) {
        $json = file_get_contents($cfgPath);
        if (is_string($json) && $json !== '') {
            $base = json_decode($json, true);
            if (is_int($base) || is_float($base) || is_string($base) || is_bool($base)) {
                // scalar compatibility: 1=success, 0=already signed
                return $alreadySigned ? 0 : 1;
            }
            if (is_array($base)) {
                if (array_key_exists('state', $base)) {
                    $base['state'] = $alreadySigned ? 0 : 1;
                }
                if (array_key_exists('status', $base)) {
                    $base['status'] = $alreadySigned ? 0 : 1;
                }
                if (array_key_exists('result', $base)) {
                    $base['result'] = $alreadySigned ? 0 : 1;
                }
                if (array_key_exists('is_signed', $base)) {
                    $base['is_signed'] = 1;
                }
                if (array_key_exists('already_signed', $base)) {
                    $base['already_signed'] = $alreadySigned ? 1 : 0;
                }
                // If config has no status-like fields, still keep explicit sign flags.
                if (!array_key_exists('is_signed', $base)) {
                    $base['is_signed'] = 1;
                }
                if (!array_key_exists('already_signed', $base)) {
                    $base['already_signed'] = $alreadySigned ? 1 : 0;
                }
                return $base;
            }
        }
    }

    // Last fallback with stable fields.
    return [
        'state' => $alreadySigned ? 0 : 1,
        'is_signed' => 1,
        'already_signed' => $alreadySigned ? 1 : 0,
    ];
};

$activeGetState = static function (): mixed {
    // Activity-copy state used to decide whether to show flashing tips.
    // The client expects a number (int), not an object.
    $cfg = amf_load_runtime_json('api.active.getState');
    if (is_int($cfg)) {
        return $cfg;
    }
    if (is_float($cfg) && is_finite($cfg) && floor($cfg) === $cfg) {
        return (int)$cfg;
    }
    if (is_string($cfg) && preg_match('/^-?\\d+$/', $cfg)) {
        return (int)$cfg;
    }
    // Default: 1 means "entry exists but not open yet" (black icon, no tip).
    // Client logic (CopyWindow):
    // - 1: disabled (black)
    // - 2/3: enabled with effect/tip
    return 1;
};

$messageGets = static function (): mixed {
    // Player dynamic messages on firstpage. Keep minimal to avoid spinners.
    $data = amf_load_runtime_json('api.message.gets');
    if (is_array($data)) {
        return $data;
    }
    return [];
};

$rewardOpenbox = static function () use ($raw): mixed {
    $startedAt = microtime(true);
    // Called from storage box usage (SellGoodsWindow.USE_BOX): (boxId, openCount)
    $boxId = amf_extract_param_int_at($raw, 0) ?? 0;
    $requestedOpenCount = max(1, amf_extract_param_int_at($raw, 1) ?? 1);
    $openCount = $requestedOpenCount;

    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0 || $boxId <= 0) {
        return ['openAmount' => 0, 'tools' => [], 'organisms' => []];
    }

    $replayOpts = amf_openbox_replay_options();
    $openboxAuditEnabled = is_file(__DIR__ . '/../../../runtime/config/enable_openbox_audit.txt');
    // Short-window replay-guard:
    // same remoting message retry should replay result, but same (boxId,count) later should still roll again.
    $token = amf_extract_replay_token($raw);
    $replayKey = $uid . ':' . $token;
    $pdo = DB::pdo();
    if ($replayOpts['enabled']) {
        amf_replay_guard_ensure_table($pdo);
        $cached = amf_replay_guard_get($pdo, $replayKey, 'api.reward.openbox');
        if (is_array($cached)) {
            $elapsed = (int)round((microtime(true) - $startedAt) * 1000);
            $line = sprintf(
                '[%s] userId=%d boxId=%d count=%d replay_key=%s hit elapsed=%d rewards=%s',
                date('Y-m-d H:i:s'),
                $uid,
                $boxId,
                $openCount,
                $replayKey,
                $elapsed,
                json_encode($cached['tools'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            if ($openboxAuditEnabled) {
                amf_append_log_line(__DIR__ . '/../../../runtime/logs/openbox_audit.log', $line);
            }
            return $cached;
        }
    }

    $seed = crc32($uid . '|' . microtime(true) . '|' . $boxId . '|' . $openCount);
    $rolled = LootRoller::roll($boxId, $openCount, $seed);
    $rewards = is_array($rolled['rewards'] ?? null) ? $rolled['rewards'] : [];
    $goldDelta = (int)($rolled['goldDelta'] ?? 0);
    $diamondDelta = (int)($rolled['diamondDelta'] ?? 0);
    $expDelta = (int)($rolled['expDelta'] ?? 0);

    // consumeItemId defaults to boxId; override by loot config when available.
    $consumeItemId = $boxId;
    $lootCfgPath = realpath(__DIR__ . '/../../../runtime/config/loot/boxes.json')
        ?: (__DIR__ . '/../../../runtime/config/loot/boxes.json');
    if (is_file($lootCfgPath)) {
        $cfgRaw = file_get_contents($lootCfgPath);
        if (is_string($cfgRaw) && $cfgRaw !== '') {
            $cfg = json_decode($cfgRaw, true);
            if (is_array($cfg) && isset($cfg[(string)$boxId]['consumeItemId'])) {
                $consumeItemId = (int)$cfg[(string)$boxId]['consumeItemId'];
            }
        }
    }
    $boxItemId = 'tool:' . $consumeItemId;
    $availableBoxCount = amf_inventory_get_qty($pdo, $uid, $boxItemId);
    if ($availableBoxCount <= 0) {
        return ['openAmount' => 0, 'tools' => [], 'organisms' => []];
    }
    // Client-side open count may be hard-coded (e.g. after SWF constant changes).
    // Clamp to current inventory so low stock boxes can still be opened.
    if ($openCount > $availableBoxCount) {
        $openCount = $availableBoxCount;
    }

    $pdo->beginTransaction();
    try {
        if (!amf_inventory_remove($pdo, $uid, $boxItemId, $openCount)) {
            throw new RuntimeException('not enough box amount');
        }

        $toolRewards = [];
        $orgDao = new OrgDao($pdo);
        $orgDropMap = [];
        $orgDropCfgPath = realpath(__DIR__ . '/../../../runtime/config/org_drop_map.json')
            ?: (__DIR__ . '/../../../runtime/config/org_drop_map.json');
        if (is_file($orgDropCfgPath)) {
            $r = file_get_contents($orgDropCfgPath);
            if (is_string($r) && $r !== '') {
                $d = json_decode($r, true);
                if (is_array($d)) {
                    $orgDropMap = $d;
                }
            }
        }
        foreach ($rewards as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $itemId = (int)($entry['itemId'] ?? 0);
            $cnt = (int)($entry['count'] ?? 0);
            if ($itemId <= 0 || $cnt <= 0) {
                continue;
            }
            $dropRule = $orgDropMap[(string)$itemId] ?? null;
            if (is_array($dropRule) && (($dropRule['action'] ?? '') === 'add_org')) {
                $tpl = max(1, (int)($dropRule['tpl_id'] ?? 151));
                for ($i = 0; $i < $cnt; $i++) {
                    $orgDao->upsertOrg($uid, [
                        'tpl_id' => $tpl,
                        'level' => 1,
                        'quality' => 1,
                        'exp' => 0,
                        'hp' => 100,
                        'hp_max' => 100,
                    ]);
                }
            } else {
                amf_inventory_add($pdo, $uid, 'tool:' . $itemId, $cnt);
            }
            // Keep response structure identical to captured openbox responses: id + amount only.
            $toolRewards[] = ['id' => $itemId, 'amount' => $cnt];
        }

        if ($goldDelta > 0) {
            amf_inventory_add($pdo, $uid, 'gold', $goldDelta);
        }
        if ($diamondDelta > 0) {
            // diamond maps to existing money/rmb_money wallet channel.
            amf_inventory_add($pdo, $uid, 'money', $diamondDelta);
        }
        if ($expDelta > 0) {
            $stateStore = new StateStore();
            $loaded = $stateStore->load($uid);
            $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
            $st = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            $st['exp'] = (int)($st['exp'] ?? 0) + $expDelta;
            $stateStore->save($uid, $phase, $st);
        }

        amf_mark_inventory_dirty($uid);

        $goldNow = amf_wallet_get($pdo, $uid, 'gold');
        $diamondNow = amf_wallet_get($pdo, $uid, 'money');
        $expNow = 0;
        $store = new StateStore();
        $loaded = $store->load($uid);
        if (is_array($loaded['state'] ?? null)) {
            $expNow = (int)($loaded['state']['exp'] ?? 0);
        }

        $resp = [
            'openAmount' => $openCount,
            'tools' => $toolRewards,
            'organisms' => [],
        ];

        if ($replayOpts['enabled']) {
            amf_replay_guard_put($pdo, $replayKey, $uid, 'api.reward.openbox', $resp, $replayOpts['ttl']);
        }
        $pdo->commit();

        $elapsed = (int)round((microtime(true) - $startedAt) * 1000);
        $audit = sprintf(
            '[%s] userId=%d boxId=%d count=%d replay_key=%s miss elapsed=%d rewards=%s',
            date('Y-m-d H:i:s'),
            $uid,
            $boxId,
            $openCount,
            $replayKey,
            $elapsed,
            json_encode($toolRewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        if ($openboxAuditEnabled) {
            amf_append_log_line(__DIR__ . '/../../../runtime/logs/openbox_audit.log', $audit);
        }

        $debugFlag = __DIR__ . '/../../../runtime/config/enable_openbox_debug.txt';
        if (is_file($debugFlag)) {
            $line = sprintf(
                '[%s] uid=%d box=%d cnt=%d seed=%d rewards=%s wallet={gold:%d,diamond:%d,exp:%d}',
                date('Y-m-d H:i:s'),
                $uid,
                $boxId,
                $openCount,
                $seed,
                json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $goldNow,
                $diamondNow,
                $expNow
            );
            amf_append_log_line(__DIR__ . '/../../../runtime/logs/openbox_debug.log', $line);
        }

        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $elapsed = (int)round((microtime(true) - $startedAt) * 1000);
        $line = sprintf(
            '[%s] userId=%d boxId=%d count=%d replay_key=%s miss_error elapsed=%d err=%s',
            date('Y-m-d H:i:s'),
            $uid,
            $boxId,
            $openCount,
            $replayKey,
            $elapsed,
            $e->getMessage()
        );
        // Keep errors always logged for troubleshooting.
        amf_append_log_line(__DIR__ . '/../../../runtime/logs/openbox_audit.log', $line);
        return ['openAmount' => 0, 'tools' => [], 'organisms' => []];
    }
};

$activeRewardTimes = static function () use ($raw): mixed {
    // Cumulative sign reward claim (Registration GETREWARD).
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $reqId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    if ($uid <= 0) {
        return ['state' => 1, 'server_time' => amf_server_time()];
    }

    $cfg = amf_load_runtime_json('api.active.getSignInfo');
    $signReward = is_array($cfg['signreward'] ?? null) ? $cfg['signreward'] : [];
    $picked = null;
    foreach ($signReward as $rw) {
        if (!is_array($rw)) continue;
        if ($reqId > 0 && (int)($rw['id'] ?? 0) !== $reqId) continue;
        $picked = $rw;
        if ($reqId > 0) break;
    }
    if (!is_array($picked)) {
        return ['state' => 0, 'error' => 'sign reward not found', 'server_time' => amf_server_time()];
    }
    $need = max(0, (int)($picked['count'] ?? 0));
    $rid = max(0, (int)($picked['id'] ?? 0));

    $state = amf_state_normalize(amf_shop_state_load($uid));
    $signedDates = is_array($state['sign']['signed_dates'] ?? null) ? $state['sign']['signed_dates'] : [];
    $totalCount = count(array_filter($signedDates, static fn($v): bool => (int)$v > 0));
    if (!isset($state['sign']['claimed_rewards']) || !is_array($state['sign']['claimed_rewards'])) {
        $state['sign']['claimed_rewards'] = [];
    }
    if (!empty($state['sign']['claimed_rewards'][(string)$rid])) {
        amf_shop_state_save($uid, $state);
        return ['state' => 1, 'already' => 1, 'id' => $rid, 'server_time' => amf_server_time()];
    }
    if ($totalCount < $need) {
        amf_shop_state_save($uid, $state);
        return ['state' => 0, 'error' => 'not enough signcount', 'need' => $need, 'current' => $totalCount, 'server_time' => amf_server_time()];
    }

    $rewards = is_array($picked['rewards'] ?? null) ? $picked['rewards'] : [];
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        foreach ($rewards as $rw) {
            if (!is_array($rw)) continue;
            $tp = (string)($rw['type'] ?? '');
            $data = is_array($rw['data'] ?? null) ? $rw['data'] : [];
            $itemId = max(0, (int)($data['id'] ?? 0));
            $num = max(0, (int)($data['num'] ?? 0));
            if ($itemId <= 0 || $num <= 0) continue;
            if ($tp === '2') {
                amf_inventory_add($pdo, $uid, 'money', $num);
            } else {
                amf_inventory_add($pdo, $uid, 'tool:' . $itemId, $num);
            }
        }
        $state['sign']['claimed_rewards'][(string)$rid] = 1;
        amf_shop_state_save($uid, $state);
        amf_mark_inventory_dirty($uid);
        $pdo->commit();
        return ['state' => 1, 'already' => 0, 'id' => $rid, 'server_time' => amf_server_time()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['state' => 0, 'error' => $e->getMessage(), 'server_time' => amf_server_time()];
    }
};

$activeRewardPoint = static function () use ($raw): mixed {
    // Dynamic active-point milestone claim.
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $reqId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    if ($uid <= 0) {
        return ['state' => 1, 'point' => 0, 'need' => 0, 'server_time' => amf_server_time()];
    }

    $state = amf_state_normalize(amf_shop_state_load($uid));
    $active = amf_state_prepare_active_daily($state);
    $spentToday = amf_active_effective_spent_today($uid, $state);
    $point = 0;
    foreach (amf_active_welfare_task_defs() as $d) {
        if ($spentToday >= (int)($d['need'] ?? 0)) {
            $point += max(0, (int)($d['point'] ?? 20));
        }
    }
    $state['active']['point'] = $point;

    $cfg = amf_load_runtime_json('api.active.getSignInfo');
    $rows = is_array($cfg['activereward'] ?? null) ? $cfg['activereward'] : [];
    $picked = null;
    if ($reqId > 0) {
        foreach ($rows as $r) {
            if (is_array($r) && (int)($r['id'] ?? 0) === $reqId) {
                $picked = $r;
                break;
            }
        }
    } else {
        // Fallback: first claimable row.
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $need0 = max(0, (int)($r['count'] ?? 0));
            $rid0 = max(0, (int)($r['id'] ?? 0));
            $ck0 = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $done0 = !empty($active['point_claimed'][$ck0]) || !empty($active['point_claimed'][(string)$rid0]) || !empty($active['point_claimed'][(string)$need0]);
            if (!$done0 && $point >= $need0) {
                $picked = $r;
                break;
            }
        }
    }
    if (!is_array($picked)) {
        amf_shop_state_save($uid, $state);
        return ['state' => 0, 'error' => 'active reward not found', 'point' => $point, 'server_time' => amf_server_time()];
    }

    $pointNeed = max(0, (int)($picked['count'] ?? 0));
    $rewardId = max(0, (int)($picked['id'] ?? 0));
    $claimKey = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;

    if (!empty($active['point_claimed'][$claimKey]) || !empty($active['point_claimed'][(string)$rewardId]) || !empty($active['point_claimed'][(string)$pointNeed])) {
        amf_shop_state_save($uid, $state);
        return ['state' => 1, 'already' => 1, 'id' => $rewardId, 'point' => $point, 'need' => $pointNeed, 'server_time' => amf_server_time()];
    }
    if ($point < $pointNeed) {
        amf_shop_state_save($uid, $state);
        return ['state' => 0, 'error' => 'not enough point', 'id' => $rewardId, 'point' => $point, 'need' => $pointNeed, 'server_time' => amf_server_time()];
    }

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $rewards = is_array($picked['rewards'] ?? null) ? $picked['rewards'] : [];
        foreach ($rewards as $r) {
            if (!is_array($r)) {
                continue;
            }
            $tp = (string)($r['type'] ?? '');
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $itemId = max(0, (int)($d['id'] ?? 0));
            $num = max(0, (int)($d['num'] ?? 0));
            if ($itemId <= 0 || $num <= 0) {
                continue;
            }
            if ($tp === '2') {
                amf_inventory_add($pdo, $uid, 'money', $num);
            } else {
                amf_inventory_add($pdo, $uid, 'tool:' . $itemId, $num);
            }
        }
        $state['active']['point_claimed'][$claimKey] = 1;
        amf_shop_state_save($uid, $state);
        amf_mark_inventory_dirty($uid);
        $pdo->commit();
        return ['state' => 1, 'already' => 0, 'id' => $rewardId, 'point' => $point, 'need' => $pointNeed, 'server_time' => amf_server_time()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['state' => 0, 'error' => $e->getMessage(), 'id' => $rewardId, 'point' => $point, 'need' => $pointNeed, 'server_time' => amf_server_time()];
    }
};

$zombieGetInfo = static function (): mixed {
    // Used by "闂佺妫勫ú锕傛偉? and some UIs that show zombie/monster info.
    // Keep editable via runtime/config/amf/api.zombie.getInfo.json.
    $data = amf_load_runtime_json('api.zombie.getInfo');
    if (is_array($data)) {
        return $data;
    }

    $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/api.zombie.getInfo.rsp.latest.amf');
    if ($capture === false) {
        $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/0029_api.zombie.getInfo.rsp.amf');
    }
    if ($capture === false) {
        throw new RuntimeException('Missing capture: api.zombie.getInfo.rsp');
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        throw new RuntimeException('Failed to read capture: api.zombie.getInfo.rsp');
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

// InvitePrizeWindow / scholarship-like rewards ("api.guide.*")
$guideGetDailyReward = static function (): mixed {
    // Daily reward amount (number) used by EveryDayPrize UI.
    // Also apply real grant to wallet once per day per user, so relog won't revert to old static value.
    $data = amf_load_runtime_json('api.guide.getDailyReward');
    $amount = 0;
    if (is_int($data) || is_float($data) || is_string($data)) {
        $amount = (int)$data;
    }
    if ($amount <= 0) {
        return 0;
    }

    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return $amount;
    }

    $today = date('Y-m-d');
    try {
        $store = new StateStore();
        $loaded = $store->load($uid);
        $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
        $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
        $guide = is_array($state['guide'] ?? null) ? $state['guide'] : [];
        $lastDate = (string)($guide['daily_reward_date'] ?? '');

        if ($lastDate !== $today) {
            $pdo = DB::pdo();
            amf_inventory_add($pdo, $uid, 'gold', $amount);
            $guide['daily_reward_date'] = $today;
            $guide['daily_reward_amount'] = $amount;
            $state['guide'] = $guide;
            $store->save($uid, $phase, $state);
        }
    } catch (Throwable $e) {
        // Keep old behavior on any DB/state failure: return numeric amount for UI.
    }

    return $amount;
};

$guideGetGuideInfo = static function (): mixed {
    // Consume rewards panel init. Expected shape:
    // - getType: 0/1 (can claim)
    // - storeObject: [{pic,num,content:[{id,num}],type}]
    // - num/pre/next: current/prev/next thresholds for the tip string
    $data = amf_load_runtime_json('api.guide.getGuideInfo');
    if (is_array($data)) {
        return $data;
    }
    return [
        'getType' => 0,
        'num' => 0,
        'pre' => 0,
        'next' => 100,
        'storeObject' => [
            [
                'pic' => 1,
                'num' => 100,
                'content' => [
                    ['id' => 1001, 'num' => 10],
                ],
                // 0: can get (will show arrow), 1: already got/disabled, 2: locked
                'type' => 2,
            ],
            [
                'pic' => 2,
                'num' => 300,
                'content' => [
                    ['id' => 1001, 'num' => 30],
                ],
                'type' => 2,
            ],
            [
                'pic' => 3,
                'num' => 500,
                'content' => [
                    ['id' => 1001, 'num' => 50],
                ],
                'type' => 2,
            ],
            [
                'pic' => 4,
                'num' => 1000,
                'content' => [
                    ['id' => 1001, 'num' => 100],
                ],
                'type' => 2,
            ],
        ],
    ];
};

$guideSetCusReward = static function (): mixed {
    // Consume rewards claim. Expected: array of {id,num}.
    $data = amf_load_runtime_json('api.guide.setCusReward');
    if (is_array($data)) {
        return $data;
    }
    return [
        ['id' => 1001, 'num' => 10],
    ];
};

$guideGetCurAccSmall = static function (): mixed {
    // Active small init. Expected shape used by layoutActiveCard(type=a):
    // {type:"a", actived:int, money:int, start:string, end:string, getType:int, storeObject:[{id,money,content}]}
    $data = amf_load_runtime_json('api.guide.getCurAccSmall');
    if (is_array($data)) {
        return $data;
    }
    return [
        'type' => 'a',
        'actived' => 1,
        'money' => 0,
        'start' => 'local',
        'end' => 'local',
        'getType' => 0,
        'storeObject' => [
            [
                'id' => 1,
                'money' => 10,
                'content' => [
                    ['id' => 1001, 'num' => 10],
                ],
            ],
            [
                'id' => 2,
                'money' => 30,
                'content' => [
                    ['id' => 1001, 'num' => 30],
                ],
            ],
        ],
    ];
};

$guideSetAccSmall = static function (): mixed {
    // Active small claim. Expected: {tools:[{id,num}], start, end}
    $data = amf_load_runtime_json('api.guide.setAccSmall');
    if (is_array($data)) {
        return $data;
    }
    return [
        'tools' => [
            ['id' => 1001, 'num' => 10],
        ],
        'start' => 'local',
        'end' => 'local',
    ];
};

$guideGetCurAccBig = static function (): mixed {
    // Active big init. Expected shape used by layoutActiveCard(type=b): has curMoney too.
    $data = amf_load_runtime_json('api.guide.getCurAccBig');
    if (is_array($data)) {
        return $data;
    }
    return [
        'type' => 'b',
        'actived' => 1,
        'money' => 0,
        'curMoney' => 0,
        'start' => 'local',
        'end' => 'local',
        'getType' => 0,
        'storeObject' => [
            [
                'id' => 1,
                'money' => 100,
                'content' => [
                    ['id' => 1001, 'num' => 100],
                ],
            ],
        ],
    ];
};

$guideSetAccBig = static function (): mixed {
    $data = amf_load_runtime_json('api.guide.setAccBig');
    if (is_array($data)) {
        return $data;
    }
    return [
        'tools' => [
            ['id' => 1001, 'num' => 100],
        ],
        'start' => 'local',
        'end' => 'local',
    ];
};

$guideGetSumTimeAct = static function (): mixed {
    // Limit activity init. layoutActiveCard routes it into layoutLimitPrize().
    // Expected keys:
    // - start/end: timestamps (seconds) for FuncKit.getFullYearAndTime
    // - actived: 0/1
    // - has_reward: 0/1
    // - reward: [{money,content:[{id,num}], ...label stats...}]
    $data = amf_load_runtime_json('api.guide.getsumtimeact');
    if (is_array($data)) {
        return $data;
    }
    $now = time();
    return [
        'start' => $now,
        'end' => $now + 7 * 24 * 3600,
        'actived' => 1,
        'has_reward' => 0,
        'reward' => [
            [
                'money' => 60,
                'content' => [
                    ['id' => 1001, 'num' => 6],
                ],
                // keep extra fields tolerated by UI label
                'type' => 0,
                'done' => 0,
            ],
        ],
    ];
};

$guideGetSumTimeReward = static function (): mixed {
    // Limit activity claim. showActiveToolsPrizes(type=1) expects:
    // {content:[{id,num}], start:int, end:int}
    $data = amf_load_runtime_json('api.guide.getsumtimereward');
    if (is_array($data)) {
        return $data;
    }
    $now = time();
    return [
        'content' => [
            ['id' => 1001, 'num' => 6],
        ],
        'start' => $now,
        'end' => $now + 7 * 24 * 3600,
    ];
};

$arenaGetAwardWeekInfo = static function (): mixed {
    $data = amf_load_runtime_json('api.arena.getAwardWeekInfo');
    if (is_array($data)) {
        return $data;
    }
    // Fallback to captured response body.
    $capture = realpath(__DIR__ . '/../../../../tools/proxy_web/AMF_CAPTURE/raw/0012_20260214_140813_689_RSP_200_api.arena.getAwardWeekInfo.amf');
    if ($capture === false) {
        $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/0000_api.arena.getAwardWeekInfo.rsp.amf');
    }
    if ($capture === false) {
        // Minimal: empty list + rank placeholder.
        return ['award' => [], 'rank' => ['grade' => 0, 'rank' => 0, 'is_reward' => '0']];
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        return ['award' => [], 'rank' => ['grade' => 0, 'rank' => 0, 'is_reward' => '0']];
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

// Fuben (InsideWorld) / checkpoints (api.fuben.*)
$fubenDisplay = static function () use ($raw): mixed {
    // Client sends (cmd, mapId). Use the mapId to pick the matching InsideWorldX.swf checkpoints.
    $sceneId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 5;

    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $state = $uid > 0 ? amf_shop_state_load($uid) : [];
    $state = amf_state_prepare_gameplay_counters($state);
    if ($uid > 0) {
        amf_shop_state_save($uid, $state);
    }
    $fubenLcc = (int)($state['counters']['fuben_lcc'] ?? 0);

    if (fuben_minimal_enabled()) {
        // Minimal deterministic map for debugging rendering:
        // - single scene (1)
        // - single checkpoint (cave_id=1)
        // This must include fields the client reads (boss/money/open_tools/child/parent/etc),
        // otherwise it may treat the node as boss or crash while parsing.
        return [
            '_reward' => 0,
            '_lcc' => $fubenLcc,
            '_ycc' => 0,
            '_last_challenge_cave' => 1,
            '_open_scenes' => ['1'],
            '_integral' => 0,
            '_caves' => [
                [
                    'reward' => '',
                    'parent' => '',
                    'child' => '',
                    'min_grade' => '1',
                    'open_cave_grid' => '1',
                    'lcc' => 0,
                    'challenge_count' => $fubenLcc,
                    'point' => '0',
                    'cave_id' => '1',
                    'vid' => '1',
                    'money' => '0',
                    't' => '0',
                    'boss' => '0',
                    'name' => 'DEBUG 1-1',
                    'open_tools' => '30|0',
                    'status' => 5,
                ],
            ],
        ];
    }

    // Use the merged big table only: runtime/config/amf/api.fuben.display.json
    // No per-scene files and no capture replay fallback (by request) so behavior is deterministic.
    $merged = amf_load_runtime_json('api.fuben.display');
    if (is_array($merged)) {
        $k = (string)$sceneId;
        if (isset($merged[$k]) && is_array($merged[$k])) {
            $resp = fuben_normalize_display($merged[$k]);
            $resp['_lcc'] = $fubenLcc;
            if (isset($resp['_caves']) && is_array($resp['_caves'])) {
                foreach ($resp['_caves'] as &$cv) {
                    if (is_array($cv)) {
                        $cv['challenge_count'] = $fubenLcc;
                    }
                }
                unset($cv);
            }
            return $resp;
        }
        if (isset($merged['_caves'])) {
            $resp = fuben_normalize_display($merged);
            $resp['_lcc'] = $fubenLcc;
            if (isset($resp['_caves']) && is_array($resp['_caves'])) {
                foreach ($resp['_caves'] as &$cv) {
                    if (is_array($cv)) {
                        $cv['challenge_count'] = $fubenLcc;
                    }
                }
                unset($cv);
            }
            return $resp;
        }
    }
    throw new RuntimeException('Missing/invalid runtime config: runtime/config/amf/api.fuben.display.json (sceneId=' . $sceneId . ')');
};

$fubenCaveInfo = static function () use ($raw): mixed {
    // Client sends (cmd, caveId). Use the caveId.
    $caveId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 0;

    // Prefer merged big table (runtime/config/amf/api.fuben.caveInfo.json) so every caveId can be defined in one place.
    $merged = amf_load_runtime_json('api.fuben.caveInfo');
    if (is_array($merged)) {
        $k = (string)$caveId;
        if ($caveId > 0 && isset($merged[$k]) && is_array($merged[$k])) {
            return $merged[$k];
        }
        if (isset($merged['_monsters'])) {
            return $merged;
        }
    }

    // Prefer per-cave JSON: runtime/config/amf/api.fuben.caveInfo/<caveId>.json
    if ($caveId > 0) {
        $perCave = __DIR__ . '/../../../runtime/config/amf/api.fuben.caveInfo/' . $caveId . '.json';
        $perCaveData = amf_json_decode_file($perCave);
        if (is_array($perCaveData)) {
            return $perCaveData;
        }
        // Help discover which cave ids are needed.
        amf_append_log_line(__DIR__ . '/../../../runtime/logs/fuben_missing_caveinfo.log', date('Y-m-d H:i:s') . ' caveId=' . $caveId);
    }

    // Fallback: replay captured response. This is enough to keep the UI moving,
    // even if the caveId isn't captured yet.
    $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/api.fuben.caveInfo.rsp.latest.amf');
    if ($capture === false) {
        $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/0053_api.fuben.caveInfo.rsp.amf');
    }
    if ($capture === false) {
        throw new RuntimeException('Missing capture: api.fuben.caveInfo.rsp');
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        throw new RuntimeException('Failed to read capture: api.fuben.caveInfo.rsp');
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

$fubenOpenCave = static function () use ($raw): mixed {
    // Called when opening a checkpoint. Client expects:
    // {open_cave_grid,status,challenge_count,lcc}
    // Client sends (cmd, caveId). Use the caveId.
    $caveId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 0;
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $state = $uid > 0 ? amf_shop_state_load($uid) : [];
    $state = amf_state_prepare_gameplay_counters($state);
    if ($uid > 0) {
        amf_shop_state_save($uid, $state);
    }
    $fubenLcc = (int)($state['counters']['fuben_lcc'] ?? 0);
    if ($caveId > 0) {
        $per = __DIR__ . '/../../../runtime/config/amf/api.fuben.openCave/' . $caveId . '.json';
        $perData = amf_json_decode_file($per);
        if (is_array($perData)) {
            $perData['challenge_count'] = $fubenLcc;
            $perData['lcc'] = $fubenLcc;
            return $perData;
        }
        amf_append_log_line(__DIR__ . '/../../../runtime/logs/fuben_missing_openCave.log', date('Y-m-d H:i:s') . ' caveId=' . $caveId);
    }

    $merged = amf_load_runtime_json('api.fuben.openCave');
    if (is_array($merged)) {
        $k = (string)$caveId;
        if ($caveId > 0 && isset($merged[$k]) && is_array($merged[$k])) {
            $resp = $merged[$k];
            $resp['challenge_count'] = $fubenLcc;
            $resp['lcc'] = $fubenLcc;
            return $resp;
        }
        if (isset($merged['open_cave_grid'])) {
            $resp = $merged;
            $resp['challenge_count'] = $fubenLcc;
            $resp['lcc'] = $fubenLcc;
            return $resp;
        }
    }

    // Default: allow opening and show some limits.
    return [
        'open_cave_grid' => 10,
        'status' => 5,
        'challenge_count' => $fubenLcc,
        'lcc' => $fubenLcc,
    ];
};

$fubenChallenge = static function (): mixed {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['state' => 0, 'error' => 'guest cannot challenge'];
    }
    $state = amf_shop_state_load($uid);
    $state = amf_state_prepare_gameplay_counters($state);
    $lcc = (int)($state['counters']['fuben_lcc'] ?? 0);
    if ($lcc <= 0) {
        return ['state' => 0, 'error' => 'not enough fuben times', 'lcc' => 0];
    }
    $state['counters']['fuben_lcc'] = $lcc - 1;
    amf_shop_state_save($uid, $state);

    // Battle response; easiest to keep compatible by replaying a capture.
    $data = amf_load_runtime_json('api.fuben.challenge');
    if ($data !== null) {
        return $data;
    }
    $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/api.fuben.challenge.rsp.latest.amf');
    if ($capture === false) {
        $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/0055_api.fuben.challenge.rsp.amf');
    }
    if ($capture === false) {
        throw new RuntimeException('Missing capture: api.fuben.challenge.rsp');
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        throw new RuntimeException('Failed to read capture: api.fuben.challenge.rsp');
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

$fubenReward = static function (): mixed {
    // Deprecated/disabled feature on the real site. Keep it deterministic and config-driven.
    $data = amf_load_runtime_json('api.fuben.reward');
    if (is_array($data)) {
        return $data;
    }

    // Fixed default to avoid empty UI.
    return [
        'current' => ['integral' => '0', 'medal' => '0'],
        'integral' => '0',
        'medal' => ['amount' => '0', 'tool_id' => null],
        'rule' => [
            'integral' => [
                ['current' => 25, 'type' => 'integral', 'tools' => '2,1'],
                ['current' => 50, 'type' => 'integral', 'tools' => '2,1'],
                ['current' => 75, 'type' => 'integral', 'tools' => '2,1'],
                ['current' => 100, 'type' => 'integral', 'tools' => '2,1'],
            ],
            'medal' => [
                ['current' => '25', 'type' => 'medal', 'tools' => '2,1'],
                ['current' => '50', 'type' => 'medal', 'tools' => '2,1'],
                ['current' => '75', 'type' => 'medal', 'tools' => '2,1'],
                ['current' => '100', 'type' => 'medal', 'tools' => '2,1'],
            ],
        ],
    ];
};

$fubenAward = static function () use ($raw): mixed {
    // Request params are (cmd:int, reward_type:string, mapId:int).
    // Real endpoint can error; for local we return a fixed success payload.
    $rewardType = amf_extract_param_string_at($raw, 1) ?? 'integral';
    if ($rewardType !== 'integral' && $rewardType !== 'medal') {
        $rewardType = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
    }

    // Optional override via JSON for experiments.
    $data = amf_load_runtime_json('api.fuben.award');
    if (is_array($data)) {
        // If user provided a static response, ensure reward_type is present.
        $data['reward_type'] = $data['reward_type'] ?? $rewardType;
        $data['next'] = $data['next'] ?? 0;
        $data['tools'] = $data['tools'] ?? [['tool_id' => 2, 'amount' => 1]];
        return $data;
    }

    return [
        'reward_type' => $rewardType,
        'next' => 0,
        'tools' => [
            ['tool_id' => 2, 'amount' => 1],
        ],
    ];
};

// Cave flow (init/list/challenge/fight/lottery) minimal real-state implementation.
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
            // Fallback ONLY to deployed lineup (fight>0), otherwise assailant ids
            // may not match client-side battle list and freeze animation.
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
        if (
            is_file(__DIR__ . '/../../../runtime/config/enable_cave_debug.txt') ||
            is_file(__DIR__ . '/../../../runtime/logs/enable_cave_debug.txt')
        ) {
            amf_append_log_line(
                __DIR__ . '/../../../runtime/logs/cave_challenge_debug.log',
                '[' . date('Y-m-d H:i:s') . '] uid=' . $uid . ' cave=' . $caveId . ' battle_id=' . $battleId . ' selected=' . json_encode($validSelected, JSON_UNESCAPED_UNICODE) . ' resp=' . json_encode($resp, JSON_UNESCAPED_UNICODE)
            );
        }
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
    // HuntingForelet.onResult(OPENCAVE) expects these fields.
    return [
        'id' => $caveId,
        'status' => 2,         // ARRIVE
        'open_id' => max(0, $caveId - 1),
        'come_time' => 0,
        'lock_time' => 0,
        'open_money' => 100,   // keep consistent with cave/index bm
        'monsters' => [
            [
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
            ],
        ],
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

$caveGetReward = static function () use ($rewardLottery, $raw): array {
    return $rewardLottery();
};

$caveQuickChallenge = static function () use ($caveChallenge, $rewardLottery): array {
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

// "闂佹椿鍘奸悘婵嗩焽濞嗘劕绶為柛婵嗗閺? in the client is implemented as the Stone copy system (api.stone.*).
// We keep this feature config-driven: edit JSON under runtime/config/amf/ and refresh the game.
$stoneGetChapInfo = static function (): mixed {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $state = $uid > 0 ? amf_shop_state_load($uid) : [];
    $state = amf_state_prepare_gameplay_counters($state);
    if ($uid > 0) {
        amf_shop_state_save($uid, $state);
    }
    $chaCount = (int)($state['counters']['stone_cha_count'] ?? 0);

    $data = amf_load_runtime_json('api.stone.getChapInfo');
    if ($data !== null) {
        if (is_array($data)) {
            $data['cha_count'] = $chaCount;
        }
        return $data;
    }
    // Minimal safe default: one open chapter with 0 stars.
    return [
        'chap_info' => [
            [
                'id' => 1,
                'name' => 'chapter_1',
                'total_star' => 30,
                'star' => 0,
                'stone' => ['local stub'],
                'condition' => [],
                'desc' => 'local stub',
                'actived' => 1,
            ],
        ],
        'cha_count' => $chaCount,
        'next_time' => 0,
        'buy_max_count' => 0,
    ];
};

$stoneGetCaveInfo = static function () use ($raw): mixed {
    // Client sends (cmd, chapId). Prefer chapId at index 1.
    $chapId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $state = $uid > 0 ? amf_shop_state_load($uid) : [];
    $state = amf_state_prepare_gameplay_counters($state);
    if ($uid > 0) {
        amf_shop_state_save($uid, $state);
    }
    $chaCount = (int)($state['counters']['stone_cha_count'] ?? 0);
    $all = amf_load_runtime_json('api.stone.getCaveInfo');
    if (is_array($all)) {
        $k = (string)$chapId;
        if (isset($all[$k])) {
            $resp = $all[$k];
            if (is_array($resp)) {
                $resp['cha_count'] = $chaCount;
            }
            return $resp;
        }
        // Back-compat: allow single-object config too.
        if (isset($all['caves'])) {
            $all['cha_count'] = $chaCount;
            return $all;
        }
    }
    return [
        'caves' => [],
        'cha_count' => $chaCount,
        'has_star' => 0,
        'tol_star' => 0,
    ];
};

$stoneGetRewardInfo = static function () use ($raw): mixed {
    // Client sends (cmd, chapId). Prefer chapId at index 1.
    $chapId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $all = amf_load_runtime_json('api.stone.getRewardInfo');
    if (is_array($all)) {
        $k = (string)$chapId;
        if (isset($all[$k])) {
            return $all[$k];
        }
        if (isset($all['info'])) {
            return $all;
        }
    }
    return [
        'info' => [],
        'has_reward' => 0,
        'star' => 0,
        'star_tol' => 0,
        'star_can' => 0,
        'next_star' => 0,
        'chap_id' => $chapId,
        'all_rewarded' => 0,
    ];
};

$stoneReward = static function () use ($raw): mixed {
    // Client sends (cmd, chapId). Prefer chapId at index 1.
    $chapId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $all = amf_load_runtime_json('api.stone.reward');
    if (is_array($all)) {
        $k = (string)$chapId;
        if (isset($all[$k]) && is_array($all[$k])) {
            return $all[$k];
        }
        if (array_is_list($all)) {
            return $all;
        }
    }
    // Client expects an array of {id,num}.
    return [];
};

$stoneGetRankByCid = static function () use ($raw): mixed {
    // Client sends (cmd, chapId). Prefer chapId at index 1.
    $chapId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $all = amf_load_runtime_json('api.stone.getRankByCid');
    if (is_array($all)) {
        $k = (string)$chapId;
        if (isset($all[$k])) {
            return $all[$k];
        }
        if (isset($all['ranks'])) {
            return $all;
        }
    }
    return [
        'chap_id' => $chapId,
        'has_star' => 0,
        'tol_star' => 0,
        'ranks' => [],
    ];
};

$stoneAddCountByMoney = static function () use ($raw): mixed {
    // Client sends (cmd, buyCount). Prefer buyCount at index 1.
    $buyCount = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $buyCount = max(1, $buyCount);
    $data = amf_load_runtime_json('api.stone.addCountByMoney');
    if (is_array($data)) {
        // Allow config to override everything.
        return $data;
    }
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['state' => 0, 'error' => 'guest cannot buy count'];
    }
    $pdo = DB::pdo();
    $state = amf_shop_state_load($uid);
    $state = amf_state_prepare_gameplay_counters($state);
    $costPer = 100;
    $cost = $costPer * $buyCount;
    if (amf_wallet_get($pdo, $uid, 'gold') < $cost) {
        return ['state' => 0, 'error' => 'insufficient currency'];
    }
    $pdo->beginTransaction();
    try {
        amf_wallet_add($pdo, $uid, 'gold', -$cost);
        $state['counters']['stone_cha_count'] = max(0, (int)($state['counters']['stone_cha_count'] ?? 0)) + $buyCount;
        amf_shop_state_save($uid, $state);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['state' => 0, 'error' => $e->getMessage()];
    }
    return [
        'can_buy_count' => 999,
        'cha_count' => (int)$state['counters']['stone_cha_count'],
        'money' => amf_wallet_get($pdo, $uid, 'gold'),
        'cus_money' => amf_wallet_get($pdo, $uid, 'money'),
        'buy_count' => $buyCount,
    ];
};

$stoneGetCaveThrougInfo = static function () use ($raw): mixed {
    // Client sends (cmd, chapId). Prefer chapId at index 1.
    $chapId = amf_extract_param_int_at($raw, 1) ?? amf_extract_first_param_int($raw) ?? 1;
    $all = amf_load_runtime_json('api.stone.getCaveThrougInfo');
    if (is_array($all)) {
        $k = (string)$chapId;
        if (isset($all[$k]) && is_array($all[$k])) {
            return $all[$k];
        }
        if (array_is_list($all)) {
            return $all;
        }
    }
    return [];
};

// Battle entry for Stone copy. We don't have a real capture in file/real_amf yet,
// so we default to replaying a structurally-compatible battle response from api.cave.challenge
// to avoid the client spinner. You can override with runtime JSON later if you capture it.
$stoneChallenge = static function (): mixed {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['state' => 0, 'error' => 'guest cannot challenge'];
    }
    $state = amf_shop_state_load($uid);
    $state = amf_state_prepare_gameplay_counters($state);
    $cha = (int)($state['counters']['stone_cha_count'] ?? 0);
    if ($cha <= 0) {
        return ['state' => 0, 'error' => 'not enough stone challenge count', 'cha_count' => 0];
    }
    $state['counters']['stone_cha_count'] = $cha - 1;
    amf_shop_state_save($uid, $state);

    $data = amf_load_runtime_json('api.stone.challenge');
    if ($data !== null) {
        return $data;
    }

    $capture = realpath(__DIR__ . '/../../../../file/real_amf/pure/api.cave.challenge.rsp.latest.amf');
    if ($capture === false) {
        throw new RuntimeException('Missing capture fallback: api.cave.challenge.rsp.latest.amf');
    }
    $rawRsp = file_get_contents($capture);
    if (!is_string($rawRsp) || $rawRsp === '') {
        throw new RuntimeException('Failed to read capture fallback: api.cave.challenge.rsp.latest.amf');
    }
    return new AmfRawValue(AmfGateway::extractFirstMessageBodyRaw($rawRsp));
};

$orgGetEvolutionOrgs = static function (): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return [];
    }
    $pdo = DB::pdo();
    $rows = amf_organism_list($pdo, $uid);
    $out = [];
    foreach ($rows as $r) {
        $out[] = amf_org_to_response($r);
    }
    return $out;
};

$orgGetEvolutionCost = static function () use ($raw): mixed {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, amf_extract_param_int_at($raw, 0) ?? 0);
    if ($uid <= 0 || $orgId <= 0) {
        return ['state' => 0, 'gold' => 0, 'diamond' => 0, 'materials' => [], 'success' => 0];
    }
    $pdo = DB::pdo();
    $org = amf_organism_get($pdo, $uid, $orgId);
    if (!is_array($org)) {
        return ['state' => 0, 'gold' => 0, 'diamond' => 0, 'materials' => [], 'success' => 0];
    }
    $cost = amf_evolution_cost_for_org($org);
    $cost['org'] = amf_org_to_response($org);
    return $cost;
};

$orgRefreshHp = static function () use ($raw): mixed {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, amf_extract_param_int_at($raw, 0) ?? 0);
    $consumeToolId = max(0, amf_extract_param_int_at($raw, 1) ?? 14);
    if ($uid <= 0 || $orgId <= 0) {
        return '0';
    }
    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $orgBefore = amf_organism_get($pdo, $uid, $orgId);
    $fallbackHp = is_array($orgBefore) ? (string)((int)($orgBefore['hp'] ?? 0)) : '0';
    $pdo->beginTransaction();
    try {
        $org = is_array($orgBefore) ? $orgBefore : amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) {
            throw new RuntimeException('organism not found');
        }
        if ($consumeToolId > 0 && !amf_inventory_remove($pdo, $uid, 'tool:' . $consumeToolId, 1)) {
            throw new RuntimeException('not enough tool amount');
        }
        $hp = amf_organism_max_hp($org);
        $dao->updateFields($uid, $orgId, ['hp' => $hp]);
        $pdo->commit();
        amf_mark_inventory_dirty($uid);
        // Captured response shape is a string numeric hp-like value (not object).
        return (string)$hp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Keep compose UI stable: never force hp to 0 on failed refresh.
        return $fallbackHp;
    }
};

$orgStrengthen = static function () use ($raw): array {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, amf_extract_param_int_at($raw, 0) ?? 0);
    $materialToolId = max(0, amf_extract_param_int_at($raw, 1) ?? 79);
    $materialCount = max(1, amf_extract_param_int_at($raw, 2) ?? 1);
    if ($uid <= 0 || $orgId <= 0) {
        return ['state' => 0];
    }
    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayKey = $uid . ':' . amf_extract_replay_token($raw);
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.apiorganism.strengthen');
    if (is_array($cached)) {
        return $cached;
    }
    $dao = new OrgDao($pdo);
    $params = amf_decode_first_params($raw);
    $materials = amf_extract_materials_from_params($params, $materialToolId, $materialCount);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) {
            throw new RuntimeException('organism not found');
        }
        $consumeRows = [];
        $totalCount = 0;
        foreach ($materials as $m) {
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = max(1, (int)$m['qty']);
            if (!amf_inventory_remove($pdo, $uid, $iid, $qty)) {
                throw new RuntimeException('not enough material');
            }
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.apiorganism.strengthen');
            $consumeRows[] = ['id' => (int)$m['id'], 'qty' => $qty];
            $totalCount += $qty;
        }

        $before = $org;
        $gainExp = 100 * max(1, $totalCount);
        $newExp = (int)$org['exp'] + $gainExp;
        $newLv = amf_org_level_by_exp($newExp);

        $orgForCalc = $org;
        $orgForCalc['exp'] = $newExp;
        $orgForCalc['level'] = $newLv;
        $statPatch = amf_recompute_org_stats($orgForCalc);
        $patch = [
            'exp' => $newExp,
            'level' => $newLv,
        ] + $statPatch;
        $dao->updateFields($uid, $orgId, $patch);
        $pdo->commit();
        amf_mark_inventory_dirty($uid);
        $orgAfter = amf_organism_get($pdo, $uid, $orgId);
        $resp = [
            'state' => 1,
            'org' => is_array($orgAfter) ? amf_org_to_response($orgAfter) : amf_org_to_response($org),
            'consume' => $consumeRows,
            'wallet' => [
                'gold' => amf_wallet_get($pdo, $uid, 'gold'),
                'diamond' => amf_wallet_get($pdo, $uid, 'money'),
            ],
            'success' => 1,
        ];
        if (is_array($orgAfter)) {
            amf_org_delta_log($uid, $orgId, $before, $orgAfter, 'api.apiorganism.strengthen');
        }
        amf_compose_log('api.apiorganism.strengthen', $uid, $orgId, ['materials' => $materials], ['materials' => $consumeRows], $resp, $startTs);
        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.apiorganism.strengthen', $resp, 3);
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $resp = ['state' => 0, 'error' => $e->getMessage(), 'success' => 0];
        amf_compose_log('api.apiorganism.strengthen', $uid, $orgId, ['materials' => $materials ?? []], [], $resp, $startTs);
        return $resp;
    }
};

$orgQualityUp = static function () use ($raw): array {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $p0 = amf_extract_param_int_at($raw, 0) ?? 0;
    $p1 = amf_extract_param_int_at($raw, 1) ?? 0;
    // qualityUp call shapes:
    // - (orgId)
    // - (type, orgId) where type=3/15/16
    $type = 3;
    $orgId = max(0, $p0);
    if ($p0 > 0 && $p0 <= 20 && $p1 > 0) {
        $type = $p0;
        $orgId = $p1;
    }
    if ($uid <= 0 || $orgId <= 0) return [];
    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayKey = $uid . ':' . amf_extract_replay_token($raw);
    // For normal quality refresh (type=3), avoid replay-cached stale UI results
    // when player clicks quickly; deterministic mode should evaluate each request.
    if ($type !== 3) {
        $cached = amf_replay_guard_get($pdo, $replayKey, 'api.apiorganism.qualityUp');
        if (is_array($cached)) return $cached;
    }
    $dao = new OrgDao($pdo);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) throw new RuntimeException('organism not found');
        $before = $org;
        $curQ = max(1, (int)$org['quality']);
        if ($curQ >= 18) {
            throw new RuntimeException('quality already max');
        }
        // Book selection follows source quality ladder.
        $isMoshenJump = ($type === 16);
        if ($isMoshenJump) {
            if ($curQ < 3 || $curQ >= 12) {
                throw new RuntimeException('moshen refresh requires quality 3..11');
            }
            $bookId = 1071;
            $requiredBooks = 1;
        } else {
            $bookId = amf_quality_required_tool_id($curQ);
            $requiredBooks = ($curQ < 12) ? amf_quality_required_books_before_moshen($curQ) : 1;
        }
        $cost = amf_evolution_cost_for_org($org);
        $cost['materials'] = [['id' => $bookId, 'qty' => $requiredBooks]];
        foreach ($cost['materials'] as $m) {
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = (int)$m['qty'];
            if (!amf_inventory_remove($pdo, $uid, $iid, $qty)) throw new RuntimeException('not enough material');
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.apiorganism.qualityUp');
        }
        if ((int)$cost['gold'] > 0 && !amf_wallet_deduct($pdo, $uid, 'gold', (int)$cost['gold'])) throw new RuntimeException('not enough gold');
        if ((int)$cost['diamond'] > 0 && !amf_wallet_deduct($pdo, $uid, 'money', (int)$cost['diamond'])) throw new RuntimeException('not enough diamond');
        if ((int)$cost['gold'] > 0) amf_inventory_delta_log($uid, 'gold', -((int)$cost['gold']), 'api.apiorganism.qualityUp');
        if ((int)$cost['diamond'] > 0) amf_inventory_delta_log($uid, 'money', -((int)$cost['diamond']), 'api.apiorganism.qualityUp');

        // Deterministic success model:
        // - Before 濮掑嫭姊婚〃?q<12): succeed once enough required books are present.
        // - 濮掑嫭姊婚〃?and above: keep fixed-success behavior.
        $chance = 100;
        $roll = 1;
        $up = true;
        $newQ = $up ? ($isMoshenJump ? 12 : min(18, $curQ + 1)) : $curQ;

        $patch = [
            'quality' => $newQ,
            'quality_name' => amf_quality_name_by_level($newQ),
        ];
        if ($up) {
            // Quality up uses per-step 5% rounded bonus on current values.
            $patch = $patch + amf_quality_step_bonus_patch($org);
        }
        $dao->updateFields($uid, $orgId, $patch);
        $pdo->commit();
        $after = amf_organism_get($pdo, $uid, $orgId);
        amf_mark_inventory_dirty($uid);

        $afterRow = is_array($after) ? $after : $org;
        $afterRow['quality_name'] = amf_quality_name_by_level((int)($afterRow['quality'] ?? $newQ));
        $resp = amf_org_qualityup_response($afterRow, (int)($afterRow['tpl_id'] ?? 151));
        $resp['result'] = $up ? 1 : 0;
        $resp['refresh_item_id'] = $bookId;
        $resp['refresh_item_used'] = $requiredBooks;
        $resp['quality_type'] = $type;
        $resp['wallet_gold'] = amf_wallet_get($pdo, $uid, 'gold');
        $resp['wallet_diamond'] = amf_wallet_get($pdo, $uid, 'money');

        if (is_array($after)) amf_org_delta_log($uid, $orgId, $before, $after, 'api.apiorganism.qualityUp');
        amf_compose_log(
            'api.apiorganism.qualityUp',
            $uid,
            $orgId,
            ['orgId' => $orgId, 'type' => $type, 'chance' => $chance, 'roll' => $roll, 'logic_mode' => 'deterministic_v2'],
            ['cost' => $cost, 'book_id' => $bookId, 'book_required' => $requiredBooks, 'up' => $up ? 1 : 0, 'to_quality' => $newQ, 'logic_mode' => 'deterministic_v2'],
            $resp,
            $startTs
        );
        if ($type !== 3) {
            amf_replay_guard_put($pdo, $replayKey, $uid, 'api.apiorganism.qualityUp', $resp, 3);
        }
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $org = amf_organism_get($pdo, $uid, $orgId);
        $resp = is_array($org) ? amf_org_qualityup_response($org, (int)($org['tpl_id'] ?? 151)) : [];
        $resp['result'] = 0;
        $resp['error'] = $e->getMessage();
        amf_compose_log('api.apiorganism.qualityUp', $uid, $orgId, ['orgId' => $orgId], [], $resp, $startTs);
        return $resp;
    }
};

$orgMatureRecompute = static function () use ($raw): array {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, amf_extract_param_int_at($raw, 0) ?? 0);
    if ($uid <= 0 || $orgId <= 0) {
        return [];
    }
    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayKey = $uid . ':' . amf_extract_replay_token($raw);
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.apiorganism.matureRecompute');
    if (is_array($cached)) {
        return $cached;
    }
    $dao = new OrgDao($pdo);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) {
            throw new RuntimeException('organism not found');
        }
        if (!amf_inventory_remove($pdo, $uid, 'tool:17', 1)) {
            throw new RuntimeException('not enough tool amount');
        }
        amf_inventory_delta_log($uid, 'tool:17', -1, 'api.apiorganism.matureRecompute');

        $tplId = max(1, (int)($org['tpl_id'] ?? 151));
        $newGrowth = amf_roll_growth_value_for_tpl($tplId);
        $before = $org;
        $orgForCalc = $org;
        $orgForCalc['mature'] = $newGrowth;
        $patch = ['mature' => $newGrowth] + amf_recompute_org_stats($orgForCalc);
        $dao->updateFields($uid, $orgId, $patch);
        $pdo->commit();

        $after = amf_organism_get($pdo, $uid, $orgId);
        $resp = is_array($after) ? amf_org_qualityup_response($after, (int)($after['tpl_id'] ?? 151)) : amf_org_qualityup_response($org, $tplId);
        $resp['pullulation'] = (string)$newGrowth;
        $resp['ma'] = (string)$newGrowth;
        $resp['mature'] = (string)$newGrowth;
        $resp['gi'] = (string)((int)($after['gi'] ?? $org['gi'] ?? 0));
        if (is_array($after)) {
            amf_org_delta_log($uid, $orgId, $before, $after, 'api.apiorganism.matureRecompute');
        }
        amf_mark_inventory_dirty($uid);
        amf_compose_log(
            'api.apiorganism.matureRecompute',
            $uid,
            $orgId,
            ['orgId' => $orgId],
            ['tool:17' => 1, 'mature' => $newGrowth],
            $resp,
            $startTs
        );
        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.apiorganism.matureRecompute', $resp, 3);
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $fallback = amf_organism_get($pdo, $uid, $orgId);
        $resp = is_array($fallback) ? amf_org_qualityup_response($fallback, (int)($fallback['tpl_id'] ?? 151)) : [];
        $resp['error'] = $e->getMessage();
        amf_compose_log('api.apiorganism.matureRecompute', $uid, $orgId, ['orgId' => $orgId], [], $resp, $startTs);
        return $resp;
    }
};

$orgSkillUp = static function () use ($raw): array {
    $startTs = microtime(true);
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, amf_extract_param_int_at($raw, 0) ?? 0);
    $skillId = max(0, amf_extract_param_int_at($raw, 1) ?? 0);
    if ($uid <= 0 || $orgId <= 0) return ['now_id' => (string)$skillId, 'prev_id' => (string)$skillId];
    $pdo = DB::pdo();
    amf_replay_guard_ensure_table($pdo);
    $replayKey = $uid . ':' . amf_extract_replay_token($raw);
    $cached = amf_replay_guard_get($pdo, $replayKey, 'api.apiorganism.skillUp');
    if (is_array($cached)) return $cached;
    $dao = new OrgDao($pdo);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) throw new RuntimeException('organism not found');
        $before = $org;
        $costCfg = amf_load_cfg_json('skills/upgrade_cost.json');
        $rule = is_array($costCfg) ? ($costCfg[(string)$skillId] ?? null) : null;
        if (!is_array($rule)) {
            $rule = ['gold' => 1000, 'diamond' => 0, 'materials' => [['itemId' => 80, 'qty' => 1]]];
            amf_missing_config_log('api.apiorganism.skillUp', ['skillId' => $skillId, 'reason' => 'missing upgrade_cost']);
        }
        foreach ((array)($rule['materials'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = max(1, (int)($m['qty'] ?? 1));
            if ($iid === 'tool:0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) throw new RuntimeException('not enough material');
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.apiorganism.skillUp');
        }
        $goldCost = max(0, (int)($rule['gold'] ?? 0));
        $diamondCost = max(0, (int)($rule['diamond'] ?? 0));
        if ($goldCost > 0 && !amf_wallet_deduct($pdo, $uid, 'gold', $goldCost)) throw new RuntimeException('not enough gold');
        if ($diamondCost > 0 && !amf_wallet_deduct($pdo, $uid, 'money', $diamondCost)) throw new RuntimeException('not enough diamond');
        if ($goldCost > 0) amf_inventory_delta_log($uid, 'gold', -$goldCost, 'api.apiorganism.skillUp');
        if ($diamondCost > 0) amf_inventory_delta_log($uid, 'money', -$diamondCost, 'api.apiorganism.skillUp');
        $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
        if (!is_array($skills)) $skills = [];
        $skills = amf_skills_assoc($skills);
        $allMap = amf_skill_all_map();
        $def = $allMap[$skillId] ?? null;
        if (!is_array($def)) {
            throw new RuntimeException('unknown skill id');
        }
        $group = (string)($def['group'] ?? '');
        $targetKey = (string)$skillId;
        $cur = isset($skills[$targetKey]) && is_array($skills[$targetKey]) ? $skills[$targetKey] : ['level' => 0];
        $curLevel = max(0, (int)($cur['level'] ?? 0));

        // Find current skill in the same group (client may send old/new id variants).
        if ($group !== '') {
            foreach ($skills as $kk => $vv) {
                $sid = (int)$kk;
                if ($sid <= 0 || !isset($allMap[$sid])) continue;
                if ((string)($allMap[$sid]['group'] ?? '') !== $group) continue;
                $targetKey = (string)$sid;
                $cur = is_array($vv) ? $vv : ['level' => (int)$vv];
                $curLevel = max(0, (int)($cur['level'] ?? $cur['grade'] ?? 0));
                break;
            }
        }
        if ($curLevel <= 0) {
            throw new RuntimeException('skill not learned');
        }

        $nextId = (int)($def['next_grade_id'] ?? 0);
        if ($nextId <= 0) {
            $nextId = $skillId;
        }
        $nextDef = $allMap[$nextId] ?? null;
        $nextLevel = is_array($nextDef) ? max(1, (int)($nextDef['grade'] ?? 1)) : ($curLevel + 1);
        if ($nextLevel <= 0) $nextLevel = 1;

        // Keep only one id in the same group to avoid id-split causing level reset.
        if ($group !== '') {
            foreach (array_keys($skills) as $kk) {
                $sid = (int)$kk;
                if ($sid <= 0 || !isset($allMap[$sid])) continue;
                if ((string)($allMap[$sid]['group'] ?? '') === $group) {
                    unset($skills[(string)$kk]);
                }
            }
        } else {
            unset($skills[$targetKey]);
        }
        $skills[(string)$nextId] = ['level' => $nextLevel];
        $skillXmlPatch = amf_build_skill_xml_from_skills_json($skills);
        $dao->updateFields($uid, $orgId, [
            'skills_json' => json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'skill' => $skillXmlPatch['skill'],
            'exskill' => $skillXmlPatch['exskill'],
        ]);
        $pdo->commit();
        $after = amf_organism_get($pdo, $uid, $orgId);
        $resp = ['now_id' => (string)$nextId, 'prev_id' => (string)$skillId];
        if (is_array($after)) amf_org_delta_log($uid, $orgId, $before, $after, 'api.apiorganism.skillUp');
        amf_compose_log('api.apiorganism.skillUp', $uid, $orgId, ['skillId' => $skillId], ['cost' => $rule], $resp, $startTs);
        amf_replay_guard_put($pdo, $replayKey, $uid, 'api.apiorganism.skillUp', $resp, 3);
        return $resp;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resp = ['now_id' => (string)$skillId, 'prev_id' => (string)$skillId];
        amf_compose_log('api.apiorganism.skillUp', $uid, $orgId, ['skillId' => $skillId], [], $resp, $startTs);
        return $resp;
    }
};

$orgSkillLearn = static function () use ($raw): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    $toolOrSkillId = max(0, (int)(amf_extract_param_int_at($raw, 1) ?? 0));
    if ($uid <= 0 || $orgId <= 0 || $toolOrSkillId <= 0) return [];
    // Never treat tool id as direct skill id in learn flow.
    // It causes wrong-learn when tool ids overlap numeric skill-id ranges.
    $allowDirectByRange = false;
    $resolved = amf_resolve_learn_target($toolOrSkillId, true, $allowDirectByRange);
    if (!is_array($resolved)) {
        amf_missing_config_log('api.apiorganism.skillLearn', ['orgId' => $orgId, 'toolOrSkillId' => $toolOrSkillId, 'reason' => 'unresolved learn target']);
        return [];
    }
    $skillId = (int)$resolved['skill_id'];
    $isSpec = !empty($resolved['is_spec']);
    if (!$isSpec) {
        $defCheck = amf_skill_all_map()[$skillId] ?? null;
        $touch = is_array($defCheck) ? (int)($defCheck['touch_off'] ?? 0) : 0;
        amf_append_log_line(
            __DIR__ . '/../../../runtime/logs/skill_learn_resolve.log',
            '[' . date('Y-m-d H:i:s') . '] uid=' . $uid . ' org=' . $orgId . ' tool=' . $toolOrSkillId . ' resolved_touch=' . $touch
        );
    }
    amf_append_log_line(
        __DIR__ . '/../../../runtime/logs/skill_learn_resolve.log',
        '[' . date('Y-m-d H:i:s') . '] uid=' . $uid . ' org=' . $orgId . ' tool=' . $toolOrSkillId . ' => skill=' . $skillId . ' spec=' . ($isSpec ? '1' : '0')
    );
    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) throw new RuntimeException('organism not found');
        $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
        if (!is_array($skills)) $skills = [];
        $skills = amf_skills_assoc($skills);
        // Decide if replacement is actually needed first.
        $needReplace = false;
        if (!$isSpec) {
            $allMapCheck = amf_skill_all_map();
            $newDefCheck = $allMapCheck[$skillId] ?? null;
            if (is_array($newDefCheck)) {
                $newTouchCheck = (int)($newDefCheck['touch_off'] ?? 1);
                $activeCntCheck = 0;
                $passiveCntCheck = 0;
                foreach ($skills as $kk => $_vv) {
                    $sid = (int)$kk;
                    if ($sid <= 0) continue;
                    $d = $allMapCheck[$sid] ?? null;
                    if (is_array($d)) {
                        if ((int)($d['touch_off'] ?? 1) === 2) $activeCntCheck++;
                        else $passiveCntCheck++;
                    } else {
                        $passiveCntCheck++;
                    }
                }
                $needReplace = ($activeCntCheck + $passiveCntCheck) >= 4
                    || ($newTouchCheck === 2 && $activeCntCheck >= 1)
                    || ($newTouchCheck !== 2 && $passiveCntCheck >= 3);
            }
        } else {
            foreach ($skills as $kk => $_vv) {
                if (str_starts_with((string)$kk, 'spec:')) {
                    $needReplace = true;
                    break;
                }
            }
        }

        $pending = amf_skill_replace_pending_get($uid, $orgId);
        if (is_array($pending) && !$needReplace) {
            // Not full, do not trigger replacement path.
            amf_skill_replace_pending_clear($uid, $orgId);
            $pending = null;
        }
        if (is_array($pending)) {
            $pts = (int)($pending['ts'] ?? 0);
            if ($pts > 0 && (time() - $pts) > 120) {
                // Stale pending marker.
                amf_skill_replace_pending_clear($uid, $orgId);
                $pending = null;
            }
        }
        if (is_array($pending)) {
            $oldKey = (string)($pending['old_key'] ?? '');
            $oldId = (int)($pending['skill_id'] ?? 0);
            if ($oldKey === '' && $oldId > 0) {
                $oldKey = isset($skills['spec:' . $oldId]) ? ('spec:' . $oldId) : (string)$oldId;
            }
            if ($oldKey !== '') {
                $oldIsSpec = str_starts_with($oldKey, 'spec:');
                // Replace cannot cross normal/ex slot.
                if (($oldIsSpec && !$isSpec) || (!$oldIsSpec && $isSpec)) {
                    amf_skill_replace_pending_clear($uid, $orgId);
                    throw new RuntimeException('replace slot mismatch');
                }
                // EX replacement is allowed only at level 1.
                if ($oldIsSpec) {
                    $oldVal = $skills[$oldKey] ?? null;
                    $oldLv = is_array($oldVal) ? (int)($oldVal['level'] ?? $oldVal['grade'] ?? 0) : (int)$oldVal;
                    if ($oldLv > 1) {
                        amf_skill_replace_pending_clear($uid, $orgId);
                        throw new RuntimeException('ex skill level too high for replace');
                    }
                }
                unset($skills[$oldKey]);
            } elseif ($oldId > 0) {
                $skills = amf_remove_skill_from_assoc($skills, $oldId);
            }
        }
        $skills = amf_normalize_normal_skills($skills) + amf_normalize_spec_skills($skills);
        $k = $isSpec ? ('spec:' . $skillId) : (string)$skillId;
        if (isset($skills[$k])) {
            $pdo->commit();
            return [];
        }
        if (!$isSpec) {
            $allMap = amf_skill_all_map();
            $newDef = $allMap[$skillId] ?? null;
            if (!is_array($newDef)) {
                throw new RuntimeException('unknown skill id');
            }
            $newTouch = (int)($newDef['touch_off'] ?? 1); // 2=active,1=passive
            $newGroup = (string)($newDef['group'] ?? '');
            $activeCnt = 0;
            $passiveCnt = 0;
            foreach ($skills as $kk => $_vv) {
                $sid = (int)$kk;
                if ($sid <= 0) continue;
                $d = $allMap[$sid] ?? null;
                if (is_array($d)) {
                    if ((string)($d['group'] ?? '') === $newGroup && $newGroup !== '') {
                        // same group cannot be learned twice
                        throw new RuntimeException('skill repetition');
                    }
                    if ((int)($d['touch_off'] ?? 1) === 2) $activeCnt++;
                    else $passiveCnt++;
                } else {
                    // Unknown normal skill id still occupies one normal slot.
                    $passiveCnt++;
                }
            }
            $total = $activeCnt + $passiveCnt;
            amf_append_log_line(
                __DIR__ . '/../../../runtime/logs/skill_passive_check.log',
                sprintf(
                    '[%s] uid=%d org=%d learnSkill=%d touch=%d active=%d passive=%d total=%d',
                    date('Y-m-d H:i:s'),
                    $uid,
                    $orgId,
                    $skillId,
                    $newTouch,
                    $activeCnt,
                    $passiveCnt,
                    $total
                )
            );
            if ($total >= 4) {
                throw new RuntimeException('skill full');
            }
            if ($newTouch === 2 && $activeCnt >= 1) {
                throw new RuntimeException('active skill full');
            }
            if ($newTouch !== 2 && $passiveCnt >= 3) {
                throw new RuntimeException('passive skill full');
            }
        } else {
            // ex skill max 1
            foreach ($skills as $kk => $_vv) {
                if (str_starts_with((string)$kk, 'spec:')) {
                    throw new RuntimeException('ex skill full');
                }
            }
        }

        // Learning consume rule:
        // - If param is real learn_tool, consume that tool.
        // - If param is direct skill id (client variants), consume skill's configured learn_tool.
        $all = $isSpec ? amf_load_cfg_json('skills/spec.json') : amf_load_cfg_json('skills/all.json');
        $cost = ['gold' => 0, 'diamond' => 0, 'materials' => []];
        $resolvedFromTool = false;
        $mapCfg = amf_load_cfg_json('skills/learn_tool_map.json');
        if (is_array($mapCfg) && isset($mapCfg[(string)$toolOrSkillId])) {
            $resolvedFromTool = true;
        } else {
            $idxN = amf_skill_learn_tool_index(false);
            $idxS = amf_skill_learn_tool_index(true);
            if (isset($idxN[$toolOrSkillId]) || isset($idxS[$toolOrSkillId])) {
                $resolvedFromTool = true;
            }
        }
        if ($resolvedFromTool && $toolOrSkillId > 0) {
            $cost['materials'][] = ['itemId' => $toolOrSkillId, 'qty' => 1];
        } elseif (is_array($all)) {
            foreach ($all as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) === $skillId) {
                    $learnTool = max(0, (int)($row['learn_tool'] ?? 0));
                    if ($learnTool > 0) {
                        $cost['materials'][] = ['itemId' => $learnTool, 'qty' => 1];
                    }
                    break;
                }
            }
        }
        foreach ((array)$cost['materials'] as $m) {
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = max(1, (int)$m['qty']);
            $iid = (string)max(0, (int)($m['itemId'] ?? 0));
            if ($iid === '0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) throw new RuntimeException('not enough material');
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.apiorganism.skillLearn');
        }
        // Learn always starts at Lv1; never inherit table grade.
        $learnLv = 1;
        $skills[$k] = ['level' => $learnLv];
        $skillXmlPatch = amf_build_skill_xml_from_skills_json($skills);
        $dao->updateFields($uid, $orgId, [
            'skills_json' => json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'skill' => $skillXmlPatch['skill'],
            'exskill' => $skillXmlPatch['exskill'],
        ]);
        $pdo->commit();
        amf_skill_replace_pending_clear($uid, $orgId);
        $after = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($after)) {
            return [];
        }
        return amf_org_qualityup_response($after, (int)($after['tpl_id'] ?? 151));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        amf_append_log_line(
            __DIR__ . '/../../../runtime/logs/skill_learn_error.log',
            sprintf(
                '[%s] uid=%d org=%d tool=%d error=%s',
                date('Y-m-d H:i:s'),
                $uid,
                $orgId,
                $toolOrSkillId,
                $e->getMessage()
            )
        );
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (is_array($org)) {
            $resp = amf_org_qualityup_response($org, (int)($org['tpl_id'] ?? 151));
            $resp['error'] = $e->getMessage();
            return $resp;
        }
        return [];
    }
};

$orgSpecSkillUp = static function () use ($raw): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $orgId = max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    $skillId = max(0, (int)(amf_extract_param_int_at($raw, 1) ?? 0));
    if ($uid <= 0 || $orgId <= 0 || $skillId <= 0) return ['now_id' => (string)$skillId, 'prev_id' => (string)$skillId];
    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $pdo->beginTransaction();
    try {
        $org = amf_organism_get($pdo, $uid, $orgId);
        if (!is_array($org)) throw new RuntimeException('organism not found');
        $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
        if (!is_array($skills)) $skills = [];
        $skills = amf_skills_assoc($skills);
        $specMap = amf_skill_spec_map();

        $targetSkillId = $skillId;
        $targetKey = 'spec:' . $targetSkillId;
        if (!isset($skills[$targetKey])) {
            $group = (string)($specMap[$skillId]['group'] ?? '');
            if ($group !== '') {
                foreach ($skills as $k => $_v) {
                    $kk = (string)$k;
                    if (!str_starts_with($kk, 'spec:')) continue;
                    $sid = (int)substr($kk, 5);
                    if ($sid <= 0 || !isset($specMap[$sid])) continue;
                    if ((string)($specMap[$sid]['group'] ?? '') === $group) {
                        $targetSkillId = $sid;
                        $targetKey = $kk;
                        break;
                    }
                }
            }
        }
        if (!isset($skills[$targetKey])) throw new RuntimeException('spec skill not learned');

        $cfg = amf_load_cfg_json('skills/spec_upgrade_cost.json');
        $rule = is_array($cfg) ? ($cfg[(string)$targetSkillId] ?? $cfg['0'] ?? null) : null;
        if (!is_array($rule)) {
            $rule = ['gold' => 2000, 'diamond' => 0, 'materials' => [['itemId' => 81, 'qty' => 1]]];
            amf_missing_config_log('api.apiorganism.specSkillUp', ['skillId' => $targetSkillId]);
        }
        foreach ((array)($rule['materials'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $qty = max(1, (int)($m['qty'] ?? 1));
            $iid = (string)max(0, (int)($m['itemId'] ?? 0));
            if ($iid === '0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) throw new RuntimeException('not enough material');
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.apiorganism.specSkillUp');
        }
        $gold = max(0, (int)($rule['gold'] ?? 0));
        $diamond = max(0, (int)($rule['diamond'] ?? 0));
        if ($gold > 0 && !amf_wallet_deduct($pdo, $uid, 'gold', $gold)) throw new RuntimeException('not enough gold');
        if ($diamond > 0 && !amf_wallet_deduct($pdo, $uid, 'money', $diamond)) throw new RuntimeException('not enough diamond');

        $nextSkillId = (int)($specMap[$targetSkillId]['next_grade_id'] ?? 0);
        if ($nextSkillId <= 0 || !isset($specMap[$nextSkillId])) {
            $nextSkillId = $targetSkillId;
        }
        $cur = isset($skills[$targetKey]) && is_array($skills[$targetKey]) ? $skills[$targetKey] : ['level' => 1];
        $curLevel = max(1, (int)($cur['level'] ?? 1));
        unset($skills[$targetKey]);
        $skills['spec:' . $nextSkillId] = ['level' => $curLevel + 1];
        $skillXmlPatch = amf_build_skill_xml_from_skills_json($skills);
        $dao->updateFields($uid, $orgId, [
            'skills_json' => json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'skill' => $skillXmlPatch['skill'],
            'exskill' => $skillXmlPatch['exskill'],
        ]);
        $pdo->commit();
        return ['now_id' => (string)$nextSkillId, 'prev_id' => (string)$targetSkillId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['now_id' => (string)$skillId, 'prev_id' => (string)$skillId];
    }
};

$toolSynthesis = static function () use ($raw): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $recipeId = (string)max(0, (int)(amf_extract_param_int_at($raw, 0) ?? 0));
    $times = max(1, (int)(amf_extract_param_int_at($raw, 1) ?? 1));
    if ($uid <= 0) return ['state' => 0, 'error' => 'guest cannot synthesis'];
    $cfg = amf_load_cfg_json('recipes/synthesis.json');
    if (!is_array($cfg) || !isset($cfg[$recipeId]) || !is_array($cfg[$recipeId])) {
        amf_missing_config_log('api.tool.synthesis', ['recipeId' => $recipeId, 'times' => $times]);
        return ['state' => 0, 'error' => 'missing synthesis recipe'];
    }
    $rule = $cfg[$recipeId];
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        foreach ((array)($rule['inputs'] ?? []) as $in) {
            if (!is_array($in)) continue;
            $iid = '閼惧嘲绶遍柆鎾冲徔' . $giveId . ' +' . $giveCount;
            $qty = max(1, (int)($in['qty'] ?? 1)) * $times;
            if ($iid === 'tool:0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) throw new RuntimeException('not enough input');
            amf_inventory_delta_log($uid, $iid, -$qty, 'api.tool.synthesis');
        }
        $gold = max(0, (int)($rule['cost']['gold'] ?? 0)) * $times;
        $diamond = max(0, (int)($rule['cost']['diamond'] ?? 0)) * $times;
        if ($gold > 0 && !amf_wallet_deduct($pdo, $uid, 'gold', $gold)) throw new RuntimeException('not enough gold');
        if ($diamond > 0 && !amf_wallet_deduct($pdo, $uid, 'money', $diamond)) throw new RuntimeException('not enough diamond');

        $outputs = [];
        foreach ((array)($rule['outputs'] ?? []) as $out) {
            if (!is_array($out)) continue;
            $oid = max(0, (int)($out['itemId'] ?? 0));
            $qty = max(1, (int)($out['qty'] ?? 1)) * $times;
            if ($oid <= 0) continue;
            amf_inventory_add($pdo, $uid, 'tool:' . $oid, $qty);
            amf_inventory_delta_log($uid, 'tool:' . $oid, $qty, 'api.tool.synthesis');
            $outputs[] = ['item_id' => $oid, 'qty' => $qty];
        }
        $pdo->commit();
        amf_mark_inventory_dirty($uid);
        return [
            'state' => 1,
            'result' => $outputs,
            'wallet' => ['gold' => amf_wallet_get($pdo, $uid, 'gold'), 'diamond' => amf_wallet_get($pdo, $uid, 'money')],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['state' => 0, 'error' => $e->getMessage()];
    }
};

$vipRewards = static function (): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    $state = $uid > 0 ? amf_state_normalize(amf_shop_state_load($uid)) : amf_state_normalize([]);
    $claimed = amf_state_prepare_vip_daily_claims($state);
    if ($uid > 0) {
        amf_shop_state_save($uid, $state);
    }
    $vip = $state['vip'];
    $vipExp = (int)($vip['exp'] ?? 0);
    $tiers = [0, 2000, 5000, 10000];
    $reward = [];
    foreach ($tiers as $idx => $minExp) {
        $tier = $idx + 1;
        $status = -1;
        if ($vipExp >= $minExp) {
            // Unclaimed: 1 (can claim), Claimed: -2 (already claimed)
            $done = !empty($claimed[(string)$tier]) || !empty($claimed[$tier]);
            $status = $done ? -2 : 1;
        }
        $reward[] = [
            'min_exp' => $minExp,
            'status' => $status,
        ];
    }
    return [
        'reward' => $reward,
        'vip_exp' => $vipExp,
        'user_day_max_exp' => 400,
        'next_exp' => max(0, 18000 - $vipExp),
        'server_time' => amf_server_time(),
        'next_refresh_at' => strtotime(date('Y-m-d 00:00:00', amf_server_time())) + 86400,
    ];
};

$vipAwards = static function () use ($raw): mixed {
    // VIP daily chest reward by tier:
    // Tier1 = base(1000 each), Tier2 = x2, Tier3 = x4, Tier4 = x6.
    // Keep payload shape as array<tool_id,amount> so existing client can consume it.
    $tier = amf_extract_param_int_at($raw, 0) ?? 0;
    $multiByTier = [1 => 1, 2 => 2, 3 => 4, 4 => 6];
    $m = (int)($multiByTier[$tier] ?? 1);

    $base = [
        ['tool_id' => 497, 'amount' => 1000],
        ['tool_id' => 7, 'amount' => 1000],
        ['tool_id' => 29, 'amount' => 1000],
        ['tool_id' => 30, 'amount' => 1000],
        ['tool_id' => 16, 'amount' => 1000],
        ['tool_id' => 79, 'amount' => 1000],
        ['tool_id' => 80, 'amount' => 1000],
        ['tool_id' => 81, 'amount' => 1000],
        ['tool_id' => 766, 'amount' => 100],
        ['tool_id' => 767, 'amount' => 100],
        ['tool_id' => 768, 'amount' => 100],
        ['tool_id' => 769, 'amount' => 100],
    ];
    foreach ($base as &$r) {
        $r['amount'] = (int)$r['amount'] * $m;
    }
    unset($r);

    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) {
        return [
            ['tool_id' => 766, 'amount' => 0],
            ['tool_id' => 767, 'amount' => 0],
            ['tool_id' => 768, 'amount' => 0],
            ['tool_id' => 769, 'amount' => 0],
        ];
    }

    $state = amf_state_normalize(amf_shop_state_load($uid));
    $vipExp = (int)($state['vip']['exp'] ?? 0);
    $needExpByTier = [1 => 0, 2 => 2000, 3 => 5000, 4 => 10000];
    $claimed = amf_state_prepare_vip_daily_claims($state);

    // Some clients don't send tier in params. Fallback: claim the next eligible unclaimed tier.
    if ($tier < 1 || $tier > 4) {
        $tier = 0;
        for ($i = 1; $i <= 4; $i++) {
            $done = !empty($claimed[(string)$i]) || !empty($claimed[$i]);
            $ok = $vipExp >= (int)$needExpByTier[$i];
            if (!$done && $ok) {
                $tier = $i;
                break;
            }
        }
        if ($tier === 0) {
            // No eligible tier, return claimed-state shape.
            return [
                ['tool_id' => 766, 'amount' => !empty($claimed['1']) ? 1 : 0],
                ['tool_id' => 767, 'amount' => !empty($claimed['2']) ? 1 : 0],
                ['tool_id' => 768, 'amount' => !empty($claimed['3']) ? 1 : 0],
                ['tool_id' => 769, 'amount' => !empty($claimed['4']) ? 1 : 0],
            ];
        }
    }
    $needExp = (int)$needExpByTier[$tier];

    if ($vipExp < $needExp) {
        return [
            ['tool_id' => 766, 'amount' => !empty($claimed['1']) ? 1 : 0],
            ['tool_id' => 767, 'amount' => !empty($claimed['2']) ? 1 : 0],
            ['tool_id' => 768, 'amount' => !empty($claimed['3']) ? 1 : 0],
            ['tool_id' => 769, 'amount' => !empty($claimed['4']) ? 1 : 0],
        ];
    }
    if (!empty($claimed[(string)$tier]) || !empty($claimed[$tier])) {
        return [
            ['tool_id' => 766, 'amount' => !empty($claimed['1']) ? 1 : 0],
            ['tool_id' => 767, 'amount' => !empty($claimed['2']) ? 1 : 0],
            ['tool_id' => 768, 'amount' => !empty($claimed['3']) ? 1 : 0],
            ['tool_id' => 769, 'amount' => !empty($claimed['4']) ? 1 : 0],
        ];
    }

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        foreach ($base as $row) {
            $toolId = (int)($row['tool_id'] ?? 0);
            $amount = (int)($row['amount'] ?? 0);
            if ($toolId <= 0 || $amount <= 0) {
                continue;
            }
            amf_inventory_add($pdo, $uid, 'tool:' . $toolId, $amount);
        }

        if (!isset($state['vip_awards_claimed']) || !is_array($state['vip_awards_claimed'])) {
            $state['vip_awards_claimed'] = [];
        }
        $state['vip_awards_claimed'][(string)$tier] = 1;
        amf_shop_state_save($uid, $state);
        amf_mark_inventory_dirty($uid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        ['tool_id' => 766, 'amount' => !empty($state['vip_awards_claimed']['1']) ? 1 : 0],
        ['tool_id' => 767, 'amount' => !empty($state['vip_awards_claimed']['2']) ? 1 : 0],
        ['tool_id' => 768, 'amount' => !empty($state['vip_awards_claimed']['3']) ? 1 : 0],
        ['tool_id' => 769, 'amount' => !empty($state['vip_awards_claimed']['4']) ? 1 : 0],
    ];
};

$vipStartAuto = static function (): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid > 0) {
        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state['vip']['restore_hp'] = 1;
        amf_shop_state_save($uid, $state);
    }
    return ['state' => 1];
};

$vipStopAuto = static function (): array {
    $ctx = RequestContext::get();
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid > 0) {
        $state = amf_state_normalize(amf_shop_state_load($uid));
        $state['vip']['restore_hp'] = 0;
        amf_shop_state_save($uid, $state);
    }
    return ['state' => 1];
};

require_once __DIR__ . '/skills_module.php';
$skillHandlers = function_exists('amf_register_skill_handlers')
    ? amf_register_skill_handlers((string)$raw)
    : [];

// Route table (avoid big if/else chains). Only implement what you explicitly ask for.
$handlers = [
    // migrated out: duty, shop, synthesis, organism core, active/vip/message, cave/fuben/stone
    'api.apiorganism.skillUp' => $skillHandlers['api.apiorganism.skillUp'] ?? $orgSkillUp,
    'api.apiorganism.skillLearn' => $skillHandlers['api.apiorganism.skillLearn'] ?? $orgSkillLearn,
    'api.apiorganism.specSkillUp' => $skillHandlers['api.apiorganism.specSkillUp'] ?? $orgSpecSkillUp,
    'api.apiskill.getAllSkills' => $skillHandlers['api.apiskill.getAllSkills'] ?? $skillGetAll,
    'api.apiskill.getSpecSkillAll' => $skillHandlers['api.apiskill.getSpecSkillAll'] ?? $specSkillGetAll,
    'api.zombie.getInfo' => $zombieGetInfo,
];
    return $handlers;
}

