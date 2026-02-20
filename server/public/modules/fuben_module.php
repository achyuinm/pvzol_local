<?php
declare(strict_types=1);

function amf_register_fuben_handlers(string $raw): array
{
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
        $perCave = __DIR__ . '/../../runtime/config/amf/api.fuben.caveInfo/' . $caveId . '.json';
        $perCaveData = amf_json_decode_file($perCave);
        if (is_array($perCaveData)) {
            return $perCaveData;
        }
        // Help discover which cave ids are needed.
        amf_append_log_line(__DIR__ . '/../../runtime/logs/fuben_missing_caveinfo.log', date('Y-m-d H:i:s') . ' caveId=' . $caveId);
    }

    return [
        '_id' => (int)$caveId,
        '_name' => 'fuben_cave_' . (int)$caveId,
        '_monster' => [],
        '_reward' => [],
        '_state' => 1,
    ];
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
        $per = __DIR__ . '/../../runtime/config/amf/api.fuben.openCave/' . $caveId . '.json';
        $perData = amf_json_decode_file($per);
        if (is_array($perData)) {
            $perData['challenge_count'] = $fubenLcc;
            $perData['lcc'] = $fubenLcc;
            return $perData;
        }
        amf_append_log_line(__DIR__ . '/../../runtime/logs/fuben_missing_openCave.log', date('Y-m-d H:i:s') . ' caveId=' . $caveId);
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

    $data = amf_load_runtime_json('api.fuben.challenge');
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
    ];
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
        $rewardType = 'integral';
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

    return [
        'api.fuben.display' => $fubenDisplay,
        'api.fuben.caveInfo' => $fubenCaveInfo,
        'api.fuben.openCave' => $fubenOpenCave,
        'api.fuben.challenge' => $fubenChallenge,
        'api.fuben.reward' => $fubenReward,
        'api.fuben.award' => $fubenAward,
    ];
}
