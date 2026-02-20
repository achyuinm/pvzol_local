<?php
declare(strict_types=1);

function amf_register_synthesis_handlers(string $raw): array
{
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


    return [
        'api.tool.synthesis' => $toolSynthesis,
    ];
}