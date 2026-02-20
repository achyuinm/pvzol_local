<?php
declare(strict_types=1);

function amf_register_duty_handlers(string $raw): array
{
    $dutyGetAll = static function (): mixed {
        $cfgPath = __DIR__ . '/../../../runtime/config/amf/api.duty.getAll.json';
        if (is_file($cfgPath)) {
            $json = file_get_contents($cfgPath);
            if (is_string($json) && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $ctx = RequestContext::get();
                    $uid = (int)($ctx['user_id'] ?? 0);
                    return amf_inject_state_into_payload($data, $uid);
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
                    $toolId = max(0, (int)($rw['tool_id'] ?? 0));
                    $qty = max(1, (int)($rw['num'] ?? 1));
                    if ($toolId <= 0) {
                        continue;
                    }
                    $iid = 'tool:' . $toolId;
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
            $state = amf_state_prepare_active_daily($state);
            $spentToday = amf_active_effective_spent_today($uid, $state);
            if ($spentToday < $need) {
                return ['state' => 0, 'error' => 'not enough spend', 'need' => $need, 'current' => $spentToday, 'up_grade' => []];
            }
            if (!isset($state['active']['times_claimed']) || !is_array($state['active']['times_claimed'])) {
                $state['active']['times_claimed'] = [];
            }
            if (!empty($state['active']['times_claimed'][(string)$taskId])) {
                return ['state' => 1, 'already' => 1, 'task_id' => $taskId, 'active_point_gain' => 0, 'up_grade' => []];
            }
            $state['active']['times_claimed'][(string)$taskId] = 1;
            $totalPoint = 0;
            foreach ($defs as $d) {
                if ($spentToday >= (int)($d['need'] ?? 0)) {
                    $totalPoint += max(0, (int)($d['point'] ?? 20));
                }
            }
            $state['active']['point'] = $totalPoint;
            amf_shop_state_save($uid, $state);
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
            $seq = (int)$taskId - 101000;
            $needLevel = $seq > 0 ? ($seq * 5) : 5;
            $userGrade = max(1, (int)($state['user']['grade'] ?? 1));
            if ($userGrade < $needLevel) {
                return ['state' => 0, 'error' => 'level not reached', 'need_level' => $needLevel, 'up_grade' => []];
            }
        } else {
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
            return ['state' => 1, 'already' => 1, 'up_grade' => []];
        }

        $reward = is_array($task['reward'] ?? null) ? $task['reward'] : $task;
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $goldGain = max(0, (int)($reward['money'] ?? 0));
            if ($goldGain > 0) {
                amf_wallet_add_gold($pdo, $uid, $goldGain);
                amf_inventory_delta_log($uid, 'gold', $goldGain, 'api.duty.reward');
            }
            $tools = is_array($reward['tools'] ?? null) ? $reward['tools'] : [];
            foreach ($tools as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $toolId = max(0, (int)($row['id'] ?? $row['tool_id'] ?? 0));
                $num = max(0, (int)($row['num'] ?? $row['amount'] ?? 0));
                if ($toolId <= 0 || $num <= 0) {
                    continue;
                }
                $iid = 'tool:' . $toolId;
                amf_inventory_add($pdo, $uid, $iid, $num);
                amf_inventory_delta_log($uid, $iid, $num, 'api.duty.reward');
            }

            if (!isset($state['duty_claimed']) || !is_array($state['duty_claimed'])) {
                $state['duty_claimed'] = [];
            }
            if (!isset($state['duty_claimed'][$today]) || !is_array($state['duty_claimed'][$today])) {
                $state['duty_claimed'][$today] = [];
            }
            $state['duty_claimed'][$today][$taskKey] = 1;
            amf_shop_state_save($uid, $state);
            $pdo->commit();

            return [
                'state' => 1,
                'already' => 0,
                'task_id' => $taskId,
                'money' => $goldGain,
                'tools' => $tools,
                'up_grade' => [],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['state' => 0, 'error' => $e->getMessage(), 'up_grade' => []];
        }
    };

    return [
        'api.duty.getAll' => $dutyGetAll,
        'api.duty.getall' => $dutyGetAll,
        'api.duty.getDuty' => $dutyGetAll,
        'api.duty.reward' => $dutyReward,
    ];
}
