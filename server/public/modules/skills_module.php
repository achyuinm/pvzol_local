<?php
declare(strict_types=1);

function amf_register_skill_handlers(string $raw): array
{
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
            amf_append_log_line(
                __DIR__ . '/../../../runtime/logs/skills_module.log',
                sprintf('[%s] api.apiskill.getSpecSkillAll rows=%d mode=core_spec_defs', date('Y-m-d H:i:s'), count($cfg))
            );
            return $cfg;
        }
        amf_missing_config_log('api.apiskill.getSpecSkillAll', ['path' => 'runtime/config/skills/spec.json']);
        $fallback = amf_load_runtime_json('api.apiskill.getSpecSkillAll');
        return is_array($fallback) ? $fallback : [];
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
                $rawItem = (string)($m['itemId'] ?? '');
                $iid = amf_exchange_item_id_from_raw($rawItem);
                $qty = max(1, (int)($m['qty'] ?? 1));
                if (($iid === '' || $iid === 'gold' || $iid === 'money') || !amf_inventory_remove($pdo, $uid, $iid, $qty)) {
                    throw new RuntimeException('not enough material');
                }
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
            if (!is_array($def)) throw new RuntimeException('unknown skill id');

            $group = (string)($def['group'] ?? '');
            $targetKey = (string)$skillId;
            $cur = isset($skills[$targetKey]) && is_array($skills[$targetKey]) ? $skills[$targetKey] : ['level' => 0];
            $curLevel = max(0, (int)($cur['level'] ?? 0));
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
            if ($curLevel <= 0) throw new RuntimeException('skill not learned');

            $nextId = (int)($def['next_grade_id'] ?? 0);
            if ($nextId <= 0) $nextId = $skillId;
            $nextDef = $allMap[$nextId] ?? null;
            $nextLevel = is_array($nextDef) ? max(1, (int)($nextDef['grade'] ?? 1)) : ($curLevel + 1);
            if ($nextLevel <= 0) $nextLevel = 1;

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

        $pdo = DB::pdo();
        $dao = new OrgDao($pdo);
        $pdo->beginTransaction();
        try {
            $org = amf_organism_get($pdo, $uid, $orgId);
            if (!is_array($org)) throw new RuntimeException('organism not found');
            $skills = json_decode((string)($org['skills_json'] ?? '{}'), true);
            if (!is_array($skills)) $skills = [];
            $skills = amf_skills_assoc($skills);

            // Replacement flow: if user selected an old skill in DelSkillWindow
            // (removeskill.php writes pending file), apply replacement first.
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
                amf_skill_replace_pending_clear($uid, $orgId);
                $pending = null;
            }
            if (is_array($pending)) {
                $pts = (int)($pending['ts'] ?? 0);
                if ($pts > 0 && (time() - $pts) > 120) {
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
                    if (($oldIsSpec && !$isSpec) || (!$oldIsSpec && $isSpec)) {
                        amf_skill_replace_pending_clear($uid, $orgId);
                        throw new RuntimeException('replace slot mismatch');
                    }
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

            // Slot constraints: total 4; active max 1; passive max 3; ex max 1.
            if (!$isSpec) {
                $allMap = amf_skill_all_map();
                $newDef = $allMap[$skillId] ?? null;
                if (!is_array($newDef)) {
                    throw new RuntimeException('unknown skill id');
                }
                $newTouch = (int)($newDef['touch_off'] ?? 1);
                $activeCnt = 0;
                $passiveCnt = 0;
                foreach ($skills as $kk => $_vv) {
                    $sid = (int)$kk;
                    if ($sid <= 0) continue;
                    $d = $allMap[$sid] ?? null;
                    if (is_array($d) && (int)($d['touch_off'] ?? 1) === 2) $activeCnt++;
                    else $passiveCnt++;
                }
                $total = $activeCnt + $passiveCnt;
                if ($total >= 4) throw new RuntimeException('skill full');
                if ($newTouch === 2 && $activeCnt >= 1) throw new RuntimeException('active skill full');
                if ($newTouch !== 2 && $passiveCnt >= 3) throw new RuntimeException('passive skill full');
            } else {
                foreach ($skills as $kk => $_vv) {
                    if (str_starts_with((string)$kk, 'spec:')) throw new RuntimeException('ex skill full');
                }
            }

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
                        if ($learnTool > 0) $cost['materials'][] = ['itemId' => $learnTool, 'qty' => 1];
                        break;
                    }
                }
            }

            foreach ((array)$cost['materials'] as $m) {
                $rawItem = (string)($m['itemId'] ?? '');
                $iid = amf_exchange_item_id_from_raw($rawItem);
                $qty = max(1, (int)$m['qty']);
                if (($iid === '' || $iid === 'gold' || $iid === 'money') || !amf_inventory_remove($pdo, $uid, $iid, $qty)) {
                    throw new RuntimeException('not enough material');
                }
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
            if (!is_array($after)) return [];
            return amf_org_qualityup_response($after, (int)($after['tpl_id'] ?? 151));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

            // Client may pass preview/next-grade id. Try resolving to actually learned skill in same group.
            if (!isset($skills[$targetKey])) {
                $group = (string)(($specMap[$skillId]['group'] ?? ''));
                if ($group !== '') {
                    foreach ($skills as $k => $_v) {
                        $kk = (string)$k;
                        if (!str_starts_with($kk, 'spec:')) continue;
                        $sid = (int)substr($kk, 5);
                        if ($sid <= 0) continue;
                        if (!isset($specMap[$sid])) continue;
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
                $rawItem = (string)($m['itemId'] ?? '');
                $iid = amf_exchange_item_id_from_raw($rawItem);
                $qty = max(1, (int)($m['qty'] ?? 1));
                if (($iid === '' || $iid === 'gold' || $iid === 'money') || !amf_inventory_remove($pdo, $uid, $iid, $qty)) {
                    throw new RuntimeException('not enough material');
                }
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

    return [
        'api.apiskill.getAllSkills' => $skillGetAll,
        'api.apiskill.getSpecSkillAll' => $specSkillGetAll,
        'api.apiorganism.skillUp' => $orgSkillUp,
        'api.apiorganism.skillLearn' => $orgSkillLearn,
        'api.apiorganism.specSkillUp' => $orgSpecSkillUp,
    ];
}
