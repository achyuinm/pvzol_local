<?php
declare(strict_types=1);

function amf_register_organism_core_handlers(string $raw): array
{
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
            $iid = (string)max(0, (int)($m['id'] ?? 0));
            $qty = max(1, (int)$m['qty']);
            if ($iid === '0' || !amf_inventory_remove($pdo, $uid, $iid, $qty)) {
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
            $iid = (string)max(0, (int)($m['id'] ?? 0));
            $qty = (int)$m['qty'];
            $ok = false;
            if ($iid !== '0') {
                $ok = amf_inventory_remove($pdo, $uid, $iid, $qty);
                if (!$ok) {
                    $ok = amf_inventory_remove($pdo, $uid, 'tool:' . $iid, $qty);
                }
            }
            if (!$ok) throw new RuntimeException('not enough material');
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


    return [
        'api.apiorganism.getEvolutionOrgs' => $orgGetEvolutionOrgs,
        'api.apiorganism.getEvolutionCost' => $orgGetEvolutionCost,
        'api.apiorganism.refreshHp' => $orgRefreshHp,
        'api.apiorganism.strengthen' => $orgStrengthen,
        'api.apiorganism.levelUp' => $orgStrengthen,
        'api.apiorganism.qualityUp' => $orgQualityUp,
        'api.apiorganism.quality12Up' => $orgQualityUp,
        'api.apiorganism.matureRecompute' => $orgMatureRecompute,
    ];
}
