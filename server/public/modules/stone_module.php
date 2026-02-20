<?php
declare(strict_types=1);

function amf_register_stone_handlers(string $raw): array
{
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
    return [
        'state' => 1,
        'is_winning' => true,
        'defenders' => [],
        'assailants' => [],
        'proceses' => [],
        'awards_key' => (string)time(),
        'cha_count' => (int)($state['counters']['stone_cha_count'] ?? 0),
    ];
};

    return [
        'api.stone.getChapInfo' => $stoneGetChapInfo,
        'api.stone.getCaveInfo' => $stoneGetCaveInfo,
        'api.stone.getRewardInfo' => $stoneGetRewardInfo,
        'api.stone.reward' => $stoneReward,
        'api.stone.getRankByCid' => $stoneGetRankByCid,
        'api.stone.addCountByMoney' => $stoneAddCountByMoney,
        'api.stone.getCaveThrougInfo' => $stoneGetCaveThrougInfo,
        'api.stone.challenge' => $stoneChallenge,
    ];
}
