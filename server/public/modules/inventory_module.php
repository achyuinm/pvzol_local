<?php
declare(strict_types=1);

function amf_register_inventory_handlers(string $raw): array
{
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
                // Keep a single client branch for challenge-book feedback/update.
                $nameCode = 1;
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
            // Counter fields used by different UIs (old/new aliases).
            'fuben_lcc' => (int)($stateAfter['counters']['fuben_lcc'] ?? 0),
            'lcc' => (int)($stateAfter['counters']['fuben_lcc'] ?? 0),
            'cave_times' => (int)($stateAfter['counters']['cave_times'] ?? 0),
            'cave_refresh' => (int)($stateAfter['counters']['cave_refresh'] ?? 0),
            'hunt_times' => (int)($stateAfter['counters']['hunt_times'] ?? 0),
            'cave_amount' => (int)($stateAfter['counters']['cave_amount'] ?? 0),
            'arena_times' => (int)($stateAfter['counters']['arena_times'] ?? 0),
            'ter_ch_count' => (int)($stateAfter['counters']['stone_cha_count'] ?? 0),
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


    return [
        'api.tool.useOf' => $toolUseOf,
        'api.reward.openbox' => $rewardOpenbox,
    ];
}
