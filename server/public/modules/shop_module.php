<?php
declare(strict_types=1);

function amf_register_shop_handlers(string $raw): array
{
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

    if ($shopTypeId === null || $shopTypeId <= 0) {
        $shopTypeId = amf_extract_param_int_at($raw, 0) ?? 1;
        if ($shopTypeId <= 0) {
            $shopTypeId = 1;
        }
    }

    if ($shopTypeId > 0) {
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

    return [
        'api.shop.init' => $shopInit,
        'api.shop.getMerchandises' => $shopGetMerchandises,
        'api.shop.buy' => $shopBuy,
        'api.shop.sell' => $shopSell,
        'api.shop.gemExchange' => $shopGemExchange,
    ];
}
