<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/DB.php';
require_once __DIR__ . '/../app/dao/OrgDao.php';

header('Content-Type: text/html; charset=utf-8');

$msg = '';
$err = '';
$userSearchRows = [];
$userSearchQ = '';
$selectedUserId = 100006;
$orgRows = [];

/**
 * @return array<int,array{id:string,name:string}>
 */
function load_xml_item_options(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = (string)@file_get_contents($file);
    if ($raw === '') {
        return [];
    }
    $out = [];
    if (preg_match_all('/<item\b[^>]*\bid="([^"]+)"[^>]*\bname="([^"]*)"/isu', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $id = trim((string)$one[1]);
            $name = trim((string)$one[2]);
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : ('ID_' . $id)];
        }
    }
    $seen = [];
    $uniq = [];
    foreach ($out as $row) {
        if (isset($seen[$row['id']])) {
            continue;
        }
        $seen[$row['id']] = true;
        $uniq[] = $row;
    }
    return $uniq;
}

function parse_growth_range_from_tag(string $itemTag): array
{
    $expl = '';
    if (preg_match('/\bexpl="([^"]*)"/isu', $itemTag, $m)) {
        $expl = (string)$m[1];
    }
    if ($expl === '') {
        return [0, 0];
    }
    if (preg_match('/(\d+)\D+(\d+)/u', $expl, $m2)) {
        $a = (int)$m2[1];
        $b = (int)$m2[2];
        if ($a > 0 && $b > 0) {
            return [min($a, $b), max($a, $b)];
        }
    }
    return [0, 0];
}

/**
 * @return array<int,array{id:string,name:string,growth_min:int,growth_max:int}>
 */
function load_organism_options_with_growth(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = (string)@file_get_contents($file);
    if ($raw === '') {
        return [];
    }
    $out = [];
    if (preg_match_all('/<item\b[^>]*>/isu', $raw, $mm)) {
        foreach (($mm[0] ?? []) as $tag) {
            $id = '';
            $name = '';
            if (preg_match('/\bid="([^"]+)"/isu', $tag, $mId)) {
                $id = trim((string)$mId[1]);
            }
            if ($id === '') {
                continue;
            }
            if (preg_match('/\bname="([^"]*)"/isu', $tag, $mName)) {
                $name = trim((string)$mName[1]);
            }
            [$gmin, $gmax] = parse_growth_range_from_tag($tag);
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('ID_' . $id),
                'growth_min' => $gmin,
                'growth_max' => $gmax,
            ];
        }
    }
    $seen = [];
    $uniq = [];
    foreach ($out as $row) {
        if (isset($seen[$row['id']])) {
            continue;
        }
        $seen[$row['id']] = true;
        $uniq[] = $row;
    }
    return $uniq;
}

function post_int(string $k, int $default = 0): int
{
    $v = $_POST[$k] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    return (int)$v;
}

function post_str(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? null;
    if (!is_string($v)) {
        return $default;
    }
    return trim($v);
}

function org_exp_by_level(int $level): int
{
    $lv = max(1, min(999, $level));
    return ($lv - 1) * 1000;
}

try {
    $toolOptions = load_xml_item_options(__DIR__ . '/php_xml/tool.xml');
    $orgOptions = load_organism_options_with_growth(__DIR__ . '/php_xml/organism.xml');
    $orgGrowthDefaults = [];
    foreach ($orgOptions as $oo) {
        $gmin = (int)($oo['growth_min'] ?? 0);
        $orgGrowthDefaults[(string)$oo['id']] = $gmin > 0 ? $gmin : 3;
    }

    $pdo = DB::pdo();
    $dao = new OrgDao($pdo);
    $dao->ensureTable();

    $selectedUserId = post_int('selected_user_id', post_int('user_id', 100006));
    $userSearchQ = post_str('user_search_q', '');

    if ($userSearchQ !== '') {
        $like = '%' . $userSearchQ . '%';
        $stmtUsers = $pdo->prepare(
            'SELECT id,nickname FROM players
             WHERE CAST(id AS CHAR) LIKE :q OR nickname LIKE :q
             ORDER BY id DESC LIMIT 200'
        );
        $stmtUsers->execute([':q' => $like]);
        $userSearchRows = $stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmtUsers = $pdo->query('SELECT id,nickname FROM players ORDER BY id DESC LIMIT 200');
        $userSearchRows = $stmtUsers ? ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = post_str('action');
        $userId = post_int('user_id', 0);
        if ($action !== 'search_user' && $userId <= 0) {
            throw new RuntimeException('user_id must be > 0');
        }

        if ($action === 'search_user') {
            $msg = 'OK: user search refreshed';
        } elseif ($action === 'rename_user') {
            $newName = post_str('new_nickname', '');
            if ($newName === '') {
                throw new RuntimeException('new_nickname required');
            }
            $stmt = $pdo->prepare('UPDATE players SET nickname=:nn WHERE id=:uid');
            $stmt->execute([':nn' => $newName, ':uid' => $userId]);
            $msg = "OK: user {$userId} renamed to {$newName}";
        } elseif ($action === 'add_item') {
            $itemId = post_str('item_id');
            $qty = post_int('qty', 0);
            if ($itemId === '' || $qty <= 0) {
                throw new RuntimeException('item_id/qty invalid');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO inventory (user_id,item_id,qty) VALUES (:uid,:iid,:q)
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([':uid' => $userId, ':iid' => $itemId, ':q' => $qty]);
            $msg = "OK: user {$userId} + item {$itemId} x{$qty}";
        } elseif ($action === 'add_org') {
            $tplId = post_int('tpl_id', 0);
            if ($tplId <= 0) {
                throw new RuntimeException('tpl_id must be > 0');
            }
            $level = max(1, post_int('level', 1));
            $matureInput = post_int('mature', 0);
            if ($matureInput <= 0) {
                $matureInput = (int)($orgGrowthDefaults[(string)$tplId] ?? 3);
            }
            $row = [
                'tpl_id' => $tplId,
                'level' => $level,
                'quality' => max(1, post_int('quality', 1)),
                'exp' => org_exp_by_level($level),
                'hp' => max(1, post_int('hp', 100)),
                'hp_max' => max(1, post_int('hp_max', 100)),
                'attack' => max(0, post_int('attack', 100)),
                'miss' => max(0, post_int('miss', 0)),
                'speed' => max(0, post_int('speed', 50)),
                'precision_val' => max(0, post_int('precision_val', 0)),
                'new_miss' => max(0, post_int('new_miss', 0)),
                'new_precision' => max(0, post_int('new_precision', 0)),
                'quality_name' => post_str('quality_name', 'Q1'),
                'dq' => max(0, post_int('dq', 0)),
                'gi' => max(0, post_int('gi', 0)),
                'mature' => max(1, $matureInput),
                'ss' => max(0, post_int('ss', 0)),
                'sh' => max(0, post_int('sh', 0)),
                'sa' => max(0, post_int('sa', 0)),
                'spr' => max(0, post_int('spr', 0)),
                'sm' => max(0, post_int('sm', 0)),
                'fight' => max(0, post_int('fight', 0)),
                'skill' => post_str('skill', ''),
                'exskill' => post_str('exskill', ''),
                'tal_add_xml' => post_str('tal_add_xml', '<tal_add hp="0" attack="0" speed="0" miss="0" precision="0"/>'),
                'soul_add_xml' => post_str('soul_add_xml', '<soul_add hp="0" attack="0" speed="0" miss="0" precision="0"/>'),
                'tals_xml' => post_str('tals_xml', '<tals><tal id="talent_1" level="0"/><tal id="talent_2" level="0"/><tal id="talent_3" level="0"/><tal id="talent_4" level="0"/><tal id="talent_5" level="0"/><tal id="talent_6" level="0"/><tal id="talent_7" level="0"/><tal id="talent_8" level="0"/><tal id="talent_9" level="0"/></tals>'),
                'soul_value' => max(0, post_int('soul_value', 0)),
                'skills_json' => post_str('skills_json', '[]'),
            ];
            $dao->upsertOrg($userId, $row);
            $msg = "OK: user {$userId} add org tpl_id {$tplId}";
        } elseif ($action === 'patch_org') {
            $orgId = post_int('org_id', 0);
            if ($orgId <= 0) {
                throw new RuntimeException('org_id must be > 0');
            }
            $patchRaw = post_str('patch_json', '{}');
            $patch = json_decode($patchRaw, true);
            if (!is_array($patch)) {
                throw new RuntimeException('patch_json must be valid JSON object');
            }
            if (array_key_exists('exp', $patch)) {
                unset($patch['exp']);
            }
            if (array_key_exists('level', $patch)) {
                $patch['level'] = max(1, (int)$patch['level']);
                $patch['exp'] = org_exp_by_level((int)$patch['level']);
            }
            $dao->updateFields($userId, $orgId, $patch);
            $msg = "OK: user {$userId} patch org {$orgId}";
        } elseif ($action === 'delete_org') {
            $orgId = post_int('org_id', 0);
            if ($orgId <= 0) {
                throw new RuntimeException('org_id must be > 0');
            }
            $stmt = $pdo->prepare('DELETE FROM organisms WHERE user_id=:uid AND org_id=:oid LIMIT 1');
            $stmt->execute([':uid' => $userId, ':oid' => $orgId]);
            $msg = "OK: user {$userId} deleted org {$orgId}";
        } else {
            throw new RuntimeException('unknown action');
        }

        $selectedUserId = $userId > 0 ? $userId : $selectedUserId;
    }

    if ($selectedUserId > 0) {
        $stmtOrg = $pdo->prepare(
            'SELECT org_id,tpl_id,level,quality,quality_name,hp,hp_max,attack,mature
             FROM organisms WHERE user_id=:uid ORDER BY org_id DESC LIMIT 500'
        );
        $stmtOrg->execute([':uid' => $selectedUserId]);
        $orgRows = $stmtOrg->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <title>PVZ DB Admin</title>
  <style>
    body { font-family: "Microsoft YaHei", sans-serif; margin: 16px; }
    fieldset { margin-bottom: 14px; padding: 12px; }
    input, textarea, select { width: 100%; padding: 6px; margin: 4px 0; box-sizing: border-box; }
    button { padding: 8px 14px; }
    .ok { color: #0a7a2f; }
    .err { color: #b00020; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #ddd; padding: 6px; font-size: 13px; }
    th { background: #f7f7f7; text-align: left; }
  </style>
</head>
<body>
  <h2>PVZ DB 管理页</h2>
  <p>用户检索/改名、发物品、新增植物、Patch植物、删除植物。</p>
  <?php if ($msg !== ''): ?><p class="ok"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

  <form method="post">
    <fieldset>
      <legend>用户检索 / 选择</legend>
      <input type="hidden" name="action" value="search_user" />
      <label>关键词（user_id 或 昵称）</label>
      <input name="user_search_q" value="<?= htmlspecialchars($userSearchQ, ENT_QUOTES, 'UTF-8') ?>" />
      <button type="submit">检索</button>
      <label>用户列表（players）</label>
      <select id="user_select" name="selected_user_id">
        <option value="">-- 选择用户 --</option>
        <?php foreach (($userSearchRows ?? []) as $u): ?>
          <option value="<?= (int)($u['id'] ?? 0) ?>" <?= ((int)($u['id'] ?? 0) === $selectedUserId) ? 'selected' : '' ?>>
            <?= htmlspecialchars(((string)$u['id']) . ' - ' . (string)($u['nickname'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </fieldset>
  </form>

  <form method="post">
    <fieldset>
      <legend>修改昵称</legend>
      <input type="hidden" name="action" value="rename_user" />
      <label>user_id</label><input class="js-user-id" name="user_id" value="<?= (int)$selectedUserId ?>" />
      <label>new_nickname</label><input name="new_nickname" value="" />
      <button type="submit">改名</button>
    </fieldset>
  </form>

  <form method="post">
    <fieldset>
      <legend>发物品</legend>
      <input type="hidden" name="action" value="add_item" />
      <label>user_id</label><input class="js-user-id" name="user_id" value="<?= (int)$selectedUserId ?>" />
      <label>道具选择（tool.xml）</label>
      <select id="item_select">
        <option value="">-- 选择道具 --</option>
        <?php foreach (($toolOptions ?? []) as $opt): ?>
          <option value="<?= htmlspecialchars($opt['id'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($opt['id'] . ' - ' . $opt['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label>item_id</label><input name="item_id" value="6" />
      <label>qty</label><input name="qty" value="1" />
      <button type="submit">发放</button>
    </fieldset>
  </form>

  <form method="post">
    <fieldset>
      <legend>新增植物</legend>
      <input type="hidden" name="action" value="add_org" />
      <label>user_id</label><input class="js-user-id" name="user_id" value="<?= (int)$selectedUserId ?>" />
      <label>植物选择（organism.xml）</label>
      <select id="org_select">
        <option value="">-- 选择植物 --</option>
        <?php foreach (($orgOptions ?? []) as $opt): ?>
          <option
            value="<?= htmlspecialchars($opt['id'], ENT_QUOTES, 'UTF-8') ?>"
            data-gmin="<?= (int)($opt['growth_min'] ?? 0) ?>"
            data-gmax="<?= (int)($opt['growth_max'] ?? 0) ?>"
          >
            <?= htmlspecialchars($opt['id'] . ' - ' . $opt['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="grid">
        <div><label>tpl_id</label><input name="tpl_id" value="1316" /></div>
        <div><label>level</label><input name="level" value="1" /></div>
        <div><label>quality</label><input name="quality" value="1" /></div>
        <div><label>quality_name</label><input name="quality_name" value="Q1" /></div>
        <div><label>exp (auto by level)</label><input id="org_exp_preview" value="0" readonly /></div>
        <div>
          <label>mature (growth)</label>
          <input id="mature_input" name="mature" value="3" />
          <small id="growth_hint"></small>
        </div>
        <div><label>hp</label><input name="hp" value="100" /></div>
        <div><label>hp_max</label><input name="hp_max" value="100" /></div>
        <div><label>attack</label><input name="attack" value="100" /></div>
        <div><label>speed</label><input name="speed" value="50" /></div>
        <div><label>miss</label><input name="miss" value="0" /></div>
        <div><label>precision_val</label><input name="precision_val" value="0" /></div>
      </div>
      <label>skills_json</label><textarea name="skills_json" rows="3">[]</textarea>
      <label>skill (xml string)</label><textarea name="skill" rows="2"></textarea>
      <label>exskill (xml string)</label><textarea name="exskill" rows="2"></textarea>
      <button type="submit">新增植物</button>
    </fieldset>
  </form>

  <form method="post">
    <fieldset>
      <legend>修改植物（JSON Patch）</legend>
      <input type="hidden" name="action" value="patch_org" />
      <label>user_id</label><input class="js-user-id" name="user_id" value="<?= (int)$selectedUserId ?>" />
      <label>org_id</label><input name="org_id" value="" />
      <label>patch_json</label>
      <textarea name="patch_json" rows="6">{"level":100,"quality":12,"mature":607,"attack":269750,"hp_max":1079003,"hp":1079003}</textarea>
      <button type="submit">应用修改</button>
    </fieldset>
  </form>

  <fieldset>
    <legend>仓库植物列表（按用户）</legend>
    <p>当前 user_id: <?= (int)$selectedUserId ?>，总数：<?= count($orgRows) ?></p>
    <table>
      <thead>
        <tr>
          <th>org_id</th>
          <th>tpl_id</th>
          <th>level</th>
          <th>quality</th>
          <th>quality_name</th>
          <th>mature</th>
          <th>hp/hp_max</th>
          <th>attack</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orgRows as $o): ?>
        <tr>
          <td><?= (int)($o['org_id'] ?? 0) ?></td>
          <td><?= (int)($o['tpl_id'] ?? 0) ?></td>
          <td><?= (int)($o['level'] ?? 0) ?></td>
          <td><?= (int)($o['quality'] ?? 0) ?></td>
          <td><?= htmlspecialchars((string)($o['quality_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)($o['mature'] ?? 0) ?></td>
          <td><?= (int)($o['hp'] ?? 0) ?>/<?= (int)($o['hp_max'] ?? 0) ?></td>
          <td><?= (int)($o['attack'] ?? 0) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('确认删除该植物？');">
              <input type="hidden" name="action" value="delete_org" />
              <input type="hidden" class="js-user-id" name="user_id" value="<?= (int)$selectedUserId ?>" />
              <input type="hidden" name="org_id" value="<?= (int)($o['org_id'] ?? 0) ?>" />
              <button type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </fieldset>

  <script>
    (function () {
      var itemSel = document.getElementById('item_select');
      var itemInput = document.querySelector('input[name="item_id"]');
      if (itemSel && itemInput) {
        itemSel.addEventListener('change', function () {
          if (itemSel.value) itemInput.value = itemSel.value;
        });
      }

      var orgSel = document.getElementById('org_select');
      var tplInput = document.querySelector('input[name="tpl_id"]');
      var matureInput = document.getElementById('mature_input');
      var growthHint = document.getElementById('growth_hint');
      if (orgSel && tplInput) {
        orgSel.addEventListener('change', function () {
          if (orgSel.value) tplInput.value = orgSel.value;
          var opt = orgSel.options[orgSel.selectedIndex];
          if (!opt) return;
          var gmin = parseInt(opt.getAttribute('data-gmin') || '0', 10);
          var gmax = parseInt(opt.getAttribute('data-gmax') || '0', 10);
          if (growthHint) {
            growthHint.textContent = (gmin > 0 && gmax > 0) ? ('growth range: ' + gmin + ' ~ ' + gmax + ' (default=min)') : '';
          }
          if (matureInput && gmin > 0) {
            matureInput.value = String(gmin);
          }
        });
      }

      var levelInput = document.querySelector('input[name="level"]');
      var expPreview = document.getElementById('org_exp_preview');
      function syncExpPreview() {
        if (!levelInput || !expPreview) return;
        var lv = parseInt(levelInput.value || '1', 10);
        if (!isFinite(lv) || lv < 1) lv = 1;
        if (lv > 999) lv = 999;
        expPreview.value = String((lv - 1) * 1000);
      }
      if (levelInput) {
        levelInput.addEventListener('input', syncExpPreview);
        syncExpPreview();
      }

      var userSel = document.getElementById('user_select');
      if (userSel) {
        userSel.addEventListener('change', function () {
          if (!userSel.value) return;
          var all = document.querySelectorAll('.js-user-id');
          for (var i = 0; i < all.length; i++) {
            all[i].value = userSel.value;
          }
        });
      }
    })();
  </script>
</body>
</html>

