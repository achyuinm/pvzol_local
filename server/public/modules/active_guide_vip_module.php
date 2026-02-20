<?php
declare(strict_types=1);

function amf_register_active_guide_vip_handlers(string $raw): array
{
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
                $k1 = 'reward_' . $rid . '_' . $need;
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

    return [
        'sign' => [],
        'day' => 0,
        'sum_day' => 0,
        'sum_reward_day' => 0,
        'can_sign' => 1,
        'server_time' => amf_server_time(),
    ];
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
            $ck0 = 'reward_' . $rid0 . '_' . $need0;
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
    $claimKey = 'reward_' . $rewardId . '_' . $pointNeed;

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
    return ['award' => [], 'rank' => ['grade' => 0, 'rank' => 0, 'is_reward' => '0']];
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


    return [
        'api.vip.rewards' => $vipRewards,
        'api.vip.awards' => $vipAwards,
        'api.vip.startAuto' => $vipStartAuto,
        'api.vip.stopAuto' => $vipStopAuto,
        'api.active.getSignInfo' => $activeGetSignInfo,
        'api.active.sign' => $activeSign,
        'api.active.getState' => $activeGetState,
        'api.active.rewardTimes' => $activeRewardTimes,
        'api.active.rewardPoint' => $activeRewardPoint,
        'api.message.gets' => $messageGets,
        'api.guide.getDailyReward' => $guideGetDailyReward,
        'api.guide.getGuideInfo' => $guideGetGuideInfo,
        'api.guide.setCusReward' => $guideSetCusReward,
        'api.guide.getCurAccSmall' => $guideGetCurAccSmall,
        'api.guide.setAccSmall' => $guideSetAccSmall,
        'api.guide.getCurAccBig' => $guideGetCurAccBig,
        'api.guide.setAccBig' => $guideSetAccBig,
        'api.guide.getsumtimeact' => $guideGetSumTimeAct,
        'api.guide.getsumtimereward' => $guideGetSumTimeReward,
        'api.arena.getAwardWeekInfo' => $arenaGetAwardWeekInfo,
    ];
}
