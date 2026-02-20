<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/SessionResolver.php';
require_once __DIR__ . '/../core/StateStore.php';
require_once __DIR__ . '/../core/UserXmlBucket.php';
require_once __DIR__ . '/WarehouseXmlBuilder.php';

/**
 * Boot chain HTTP endpoints observed in real traffic.
 *
 * IMPORTANT:
 * Keep this controller limited to the minimal boot chain only.
 * Do not grow this into a giant if/else file; add new endpoints as new controllers.
 */
final class BootChainController
{
    /**
     * @param array{seed:string,uid:int} $ctx
     */
    public static function dispatch(string $path, array $ctx): ?HttpResponse
    {
        $ctx['path'] = $path;
        // Extract sig from route path when present, e.g. ".../sig/<hex>".
        if (!isset($ctx['sig']) || !is_string($ctx['sig'])) {
            if (preg_match('#/sig/([A-Za-z0-9_-]{6,128})$#', $path, $m)) {
                $ctx['sig'] = strtolower(trim($m[1]));
            } else {
                $ctx['sig'] = '';
            }
        }

        // Route table (regex -> handler) keeps index.php thin and avoids piling logic there.
        $routes = [
            '#^default/isnew/sig/[a-f0-9]+$#i' => [self::class, 'isNew'],
            '#^default/user/sig/[a-f0-9]+$#i' => [self::class, 'user'],
            '#^tree/addheight/sig/[a-f0-9]+$#i' => [self::class, 'treeAddHeight'],
            '#^garden/index/id/\d+(?:/sig/[a-f0-9]+)?$#i' => [self::class, 'gardenIndex'],
            '#^default/invite/sig/[a-f0-9]+$#i' => [self::class, 'defaultInvite'],
            '#^cave/index/id/\d+/type/[A-Za-z0-9_]+(?:/sig/[a-f0-9]+)?$#i' => [self::class, 'caveIndex'],
            '#^Warehouse/index/sig/[a-f0-9]+$#i' => [self::class, 'warehouse'],
            '#^user/recommendfriend/sig/[a-f0-9]+$#i' => [self::class, 'recommendFriend'],
            '#^invite/downline/sig/[a-f0-9]+$#i' => [self::class, 'inviteDownline'],
            '#^shop/invite/id/\\d+/sig/[a-f0-9]+$#i' => [self::class, 'shopInviteClaim'],
            '#^user/inviteup/id/\\d+/sig/[a-f0-9]+$#i' => [self::class, 'userInviteUpClaim'],
        ];

        foreach ($routes as $re => $handler) {
            if (preg_match($re, $path)) {
                /** @var callable $handler */
                return $handler($ctx);
            }
        }

        return null;
    }

    public static function gardenIndex(array $ctx): HttpResponse
    {
        $path = (string)($ctx['path'] ?? '');
        $gardenId = 0;
        if (preg_match('#^garden/index/id/(\d+)#i', $path, $m)) {
            $gardenId = (int)$m[1];
        }
        $sig = isset($ctx['sig']) && is_string($ctx['sig']) ? trim($ctx['sig']) : '';
        $session = SessionResolver::resolveFromRequest($sig);
        $uid = (int)($session['user_id'] ?? 0);
        if ($uid <= 0) {
            $uid = max(1, (int)($ctx['uid'] ?? 0));
        }
        if ($gardenId <= 0) {
            $gardenId = $uid;
        }

        $ownerId = $gardenId;
        $state = [];
        try {
            $store = new StateStore();
            $loaded = $store->load($ownerId);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
        } catch (Throwable $e) {
            $state = [];
        }
        $state = self::normalizeGardenState($state);

        $orgRows = [];
        try {
            if (class_exists('DB') && class_exists('OrgDao')) {
                $pdo = DB::pdo();
                $orgDao = new OrgDao($pdo);
                $orgRows = $orgDao->getByUser($ownerId);
            }
        } catch (Throwable $e) {
            $orgRows = [];
        }
        $orgMap = [];
        foreach ($orgRows as $row) {
            $oid = (int)($row['org_id'] ?? 0);
            if ($oid > 0) {
                $orgMap[$oid] = $row;
            }
        }

        $slots = is_array($state['garden']['slots'] ?? null) ? $state['garden']['slots'] : [];
        $orsXml = '';
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $orgId = (int)($slot['org_id'] ?? 0);
            if ($orgId <= 0) {
                continue;
            }
            $row = is_array($orgMap[$orgId] ?? null) ? $orgMap[$orgId] : [];
            $pid = max(1, (int)($row['pid'] ?? $row['tpl_id'] ?? 1));
            $hp = max(1, (int)($row['hp'] ?? $row['hm'] ?? 1));
            $hm = max($hp, (int)($row['hm'] ?? $row['hp_max'] ?? $hp));
            $gr = max(1, (int)($row['gr'] ?? $row['grade'] ?? $row['level'] ?? 1));
            $qu = (string)($row['qu'] ?? $row['quality_name'] ?? '1');
            $x = max(0, (int)($slot['x'] ?? 0));
            $y = max(0, (int)($slot['y'] ?? 0));
            $ty = max(0, (int)($slot['type'] ?? 0));
            $ti = (int)($slot['time'] ?? 0);
            $rt = (int)($slot['ripe_time'] ?? 0);
            $orsXml .= '<or id="' . $orgId . '" pid="' . $pid . '" gr="' . $gr . '" qu="' . HttpUtil::xmlEscape($qu) . '" ou="25" ow="' . HttpUtil::xmlEscape((string)$ownerId)
                . '" owid="' . (int)$ownerId . '" rt="' . $rt . '" hp="' . $hp . '" hm="' . $hm . '" ft="0" it="0" soul="0">'
                . '<position lx="' . $x . '" ly="' . $y . '" />'
                . '<state ti="' . $ti . '" ty="' . $ty . '" />'
                . '</or>';
        }

        $monsterXml = '';
        // Self garden should not spawn hunt monsters occupying own slots.
        // Keep monsters only for visiting friend's garden.
        if ($ownerId !== $uid) {
            for ($i = 1; $i <= 3; $i++) {
                $monId = 1000 + $i;
                $hp = 1000 * $i;
                $atk = 150 * $i;
                $monsterXml .= '<mon id="' . $i . '" monid="' . $monId . '" pid="19" owid="' . (int)$ownerId . '" name="garden_mon_' . $i . '" grade_max="' . (1 + $i * 2) . '" grade_min="' . $i . '" reward="1001,1002">'
                    . '<position lx="' . ($i - 1) . '" ly="0" />'
                    . '<read><org id="' . (9000 + $i) . '" pi="19" ak="' . $atk . '" mi="10" hp="' . $hp . '" gd="' . $i . '" ps="10" new_miss="0" new_precision="0"><talent></talent><skill></skill></org></read>'
                    . '</mon>';
            }
        }

        $cn = max(0, (int)($state['counters']['garden_cha_count'] ?? 0));
        if ($cn <= 0) {
            $cn = 1;
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response><garden id="' . (int)$gardenId . '" cn="' . $cn . '" am="0" bt="0" ba="0">'
            . '<ors>' . $orsXml . '</ors>'
            . '<monster>' . $monsterXml . '</monster>'
            . '</garden></root>';
        return HttpResponse::xml($xml);
    }

    public static function caveIndex(array $ctx): HttpResponse
    {
        $path = (string)($ctx['path'] ?? '');
        $viewerId = 0;
        $mode = 'private';
        if (preg_match('#^cave/index/id/(\d+)/type/([A-Za-z0-9_]+)#i', $path, $m)) {
            $viewerId = (int)$m[1];
            $mode = strtolower((string)$m[2]);
        }
        $sig = isset($ctx['sig']) && is_string($ctx['sig']) ? trim($ctx['sig']) : '';
        $session = SessionResolver::resolveFromRequest($sig);
        $uid = (int)($session['user_id'] ?? 0);
        if ($uid <= 0) {
            $uid = max(1, $viewerId);
        }

        $userLevel = 1;
        try {
            $store = new StateStore();
            $loaded = $store->load($uid);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            $userLevel = max(1, (int)($state['user']['grade'] ?? 1));
        } catch (Throwable $e) {
            $userLevel = 1;
        }

        $caveCfg = self::getCaveConfig();
        $levels = self::caveLevelSequenceUpTo($userLevel);
        $layerIndex = self::caveLayerIndexByMode($mode);
        $typeCfg = is_array(($caveCfg['types'] ?? null)) ? ($caveCfg['types'][$mode] ?? null) : null;
        if (!is_array($typeCfg)) {
            if ($mode === 'public' || str_starts_with($mode, 'public_')) {
                $typeCfg = is_array(($caveCfg['types']['public'] ?? null)) ? $caveCfg['types']['public'] : null;
            } elseif ($mode === 'private_2' || $mode === 'private_4' || $mode === 'private_6') {
                $typeCfg = is_array(($caveCfg['types']['night'] ?? null)) ? $caveCfg['types']['night'] : null;
            } else {
                $typeCfg = is_array(($caveCfg['types']['private'] ?? null)) ? $caveCfg['types']['private'] : null;
            }
        }
        $total = max(1, (int)($typeCfg['hole_count'] ?? ($caveCfg['total_holes'] ?? 12)));
        $layerOffset = max(0, ($layerIndex - 1) * $total);
        $available = max(0, count($levels) - $layerOffset);
        $maxOpen = min($total, $available);
        if ($maxOpen <= 0) {
            $maxOpen = 1;
        }
        $lastId = $maxOpen;
        $namePrefix = (string)($typeCfg['prefix'] ?? self::lang('server.cave.prefix.default', 'cave_lv'));
        $hpBase = max(1, (int)($typeCfg['hp_base'] ?? 5000));
        $atkBase = max(1, (int)($typeCfg['atk_base'] ?? 1000));
        $openCost = max(0, (int)($typeCfg['open_cost'] ?? 1000));
        $battleCost = max(0, (int)($typeCfg['battle_cost'] ?? 100));

        $holes = [];
        for ($i = 1; $i <= $total; $i++) {
            $isOpen = $i <= $maxOpen;
            $status = $isOpen ? 2 : 0;
            $levelIdx = $layerOffset + $i - 1;
            $monsterLv = ($isOpen && isset($levels[$levelIdx])) ? (int)$levels[$levelIdx] : 1;
            $holes[] = [
                'id' => $i,
                'oi' => max(0, $i - 1),
                'na' => $namePrefix . $monsterLv,
                't' => $status,
                'ct' => 0,
                'og' => $monsterLv,
                'oc' => $openCost,
                'bm' => $battleCost,
                'lt' => 0,
                'la' => '',
                'hp' => self::cavePowerByIndex($i, $hpBase),
                'atk' => self::cavePowerByIndex($i, $atkBase),
            ];
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<root><response><status>success</status></response><hunting>';
        $xml .= '<user my_id="' . $lastId . '" />';
        foreach ($holes as $h) {
            $xml .= '<h id="' . (int)$h['id'] . '">';
            $xml .= '<oi>' . (int)$h['oi'] . '</oi>';
            $xml .= '<na>' . HttpUtil::xmlEscape((string)$h['na']) . '</na>';
            $xml .= '<t>' . (int)$h['t'] . '</t>';
            $xml .= '<ct>' . (int)$h['ct'] . '</ct>';
            $xml .= '<og>' . (int)$h['og'] . '</og>';
            $xml .= '<oc>' . (int)$h['oc'] . '</oc>';
            $xml .= '<bm>' . (int)$h['bm'] . '</bm>';
            $xml .= '<la><nk>' . HttpUtil::xmlEscape((string)$h['la']) . '</nk></la>';
            $xml .= '<lt>' . (int)$h['lt'] . '</lt>';
            if ((int)$h['t'] === 2) {
                $xml .= '<orgs>';
                $xml .= '<org id="' . (3000 + (int)$h['id']) . '">';
                $xml .= '<pi>19</pi>';
                $xml .= '<ak>' . (int)$h['atk'] . '</ak>';
                $xml .= '<mi>31</mi>';
                $xml .= '<hp>' . (int)$h['hp'] . '</hp>';
                $xml .= '<gd>' . (int)$h['og'] . '</gd>';
                $xml .= '<ps>21</ps>';
                $xml .= '<sp>0</sp>';
                $xml .= '<new_miss>0</new_miss>';
                $xml .= '<new_precision>0</new_precision>';
                // Keep talent list complete to avoid client-side undefined fields in org tooltip.
                $xml .= '<talent><item>0</item><item>0</item><item>0</item><item>0</item><item>0</item><item>0</item><item>0</item><item>0</item></talent>';
                // Avoid mojibake from legacy lang tables here; keep cave default skill name stable.
                $xml .= '<skill><item><id>66</id><name>skill_66</name><grade>1</grade></item></skill>';
                $xml .= '</org>';
                $xml .= '</orgs>';
            } else {
                $xml .= '<orgs></orgs>';
            }
            $xml .= '</h>';
        }
        $xml .= '</hunting></root>';
        return HttpResponse::xml($xml);
    }
    /** @return int[] */
    private static function caveLevelSequenceUpTo(int $userLevel): array
    {
        $userLevel = max(1, $userLevel);
        $seq = [];
        $cfg = self::getCaveConfig();
        $offsets = [1, 3, 6, 9];
        if (is_array($cfg['level_offsets'] ?? null) && $cfg['level_offsets'] !== []) {
            $offsets = array_values(array_filter(array_map('intval', $cfg['level_offsets']), static fn($v): bool => $v > 0));
            if ($offsets === []) {
                $offsets = [1, 3, 6, 9];
            }
        }
        $bandStep = max(1, (int)($cfg['band_step'] ?? 10));
        for ($band = 0; $band < 200; $band++) {
            $base = $band * $bandStep;
            foreach ($offsets as $o) {
                $lv = $base + $o;
                if ($lv > $userLevel) {
                    return $seq === [] ? [1] : $seq;
                }
                $seq[] = $lv;
            }
        }
        return $seq === [] ? [1] : $seq;
    }

    private static function cavePowerByIndex(int $index, int $base): int
    {
        $index = max(1, $index);
        $v = (float)$base;
        for ($i = 1; $i < $index; $i++) {
            $v *= 2.0;
            if ($v > (float)PHP_INT_MAX) {
                return PHP_INT_MAX;
            }
        }
        return (int)$v;
    }

    private static function caveLayerIndexByMode(string $mode): int
    {
        static $map = [
            'private' => 1,
            'private_3' => 2,
            'private_5' => 3,
            'private_7' => 4,
            'public' => 1,
            'public_2' => 2,
            'public_3' => 3,
            'public_4' => 4,
            'night' => 1,
            'private_2' => 2,
            'private_4' => 3,
            'private_6' => 4,
        ];
        return (int)($map[strtolower($mode)] ?? 1);
    }

    private static function getCaveConfig(): array
    {
        static $cfg = null;
        if (is_array($cfg)) {
            return $cfg;
        }
        $cfg = [
            'total_holes' => 12,
            'band_step' => 10,
            'level_offsets' => [1, 3, 6, 9],
            'types' => [
                'private' => ['prefix' => self::lang('server.cave.prefix.private', 'private_cave_lv'), 'hp_base' => 5000, 'atk_base' => 1000, 'open_cost' => 1000, 'battle_cost' => 100],
                'public' => ['prefix' => self::lang('server.cave.prefix.public', 'public_cave_lv'), 'hp_base' => 5000, 'atk_base' => 1000, 'open_cost' => 1000, 'battle_cost' => 100],
                'night' => ['prefix' => self::lang('server.cave.prefix.night', 'night_cave_lv'), 'hp_base' => 5000, 'atk_base' => 1000, 'open_cost' => 1000, 'battle_cost' => 100, 'hole_count' => 9],
            ],
        ];
        $path = dirname(__DIR__, 3) . '/runtime/config/cave_progression.json';
        if (!is_file($path)) {
            return $cfg;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $cfg;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $cfg = array_replace_recursive($cfg, $json);
        }
        return $cfg;
    }

    /**
     * @param array{seed:string,uid:int} $ctx
     */
    public static function isNew(array $ctx): HttpResponse
    {
        // Real response body is "0" (text/plain).
        return HttpResponse::text('0');
    }

    /**
     * @param array{seed:string,uid:int} $ctx
     */
    public static function user(array $ctx): HttpResponse
    {
        $sig = isset($ctx['sig']) && is_string($ctx['sig']) ? trim($ctx['sig']) : '';
        $session = SessionResolver::resolveFromRequest($sig);
        $xmlPath = UserXmlBucket::ensureUserXml((int)($session['user_id'] ?? 0));
        $xml = file_get_contents($xmlPath);
        if (is_string($xml) && $xml !== '') {
            // Inject dynamic wallet values into user XML.
            $uid = (int)($session['user_id'] ?? 0);
            if ($uid > 0) {
                $gold = self::inventoryQty($uid, 'gold');
                $money = self::inventoryQty($uid, 'money');
                $xml2 = self::injectUserWallet($xml, $gold, $money);
                if ($xml2 !== '') {
                    $xml = $xml2;
                }
                $xml3 = self::injectUserVipFromState($xml, $uid);
                if ($xml3 !== '') {
                    $xml = $xml3;
                }
                $xml4 = self::injectUserProgressFromState($xml, $uid);
                if ($xml4 !== '') {
                    $xml = $xml4;
                }
            }
            return HttpResponse::xml($xml);
        }

        // Fallback minimal response (keeps SWF boot sequence moving).
        $uid = (int)$ctx['uid'];
        $name = HttpUtil::xmlEscape('local_' . $uid);
        $face = HttpUtil::xmlEscape('/pvz/avatar/' . $uid . '.jpg');

        // Minimal user XML that keeps the SWF boot sequence moving.
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . sprintf(
                '<user id="%d" name="%s" charm="0" money="0" date_award="0" reward_daily="0" has_reward_cus="0" has_reward_once="0" has_reward_sum="0" has_reward_first="0" state="" rmb_money="0" open_cave_grid="0" wins="0" is_new="0" login_reward="0" invite_amount="0" use_invite_num="0" max_use_invite_num="0" lottery_key="" banner_num="0" banner_url="" hasActivitys="1" face_url="" face="%s" vip_grade="0" vip_etime="0" vip_restore_hp="0" is_auto="0" serverbattle_status="0" IsNewTaskSystem="1" registrationReward="0" stone_cha_count="0" vip_exp="0">',
                $uid,
                $name,
                $face
            )
            . '<arena_rank_date old_start="' . HttpUtil::xmlEscape(self::lang('server.date.today', 'today')) . '" old_end="' . HttpUtil::xmlEscape(self::lang('server.date.tomorrow', 'tomorrow')) . '" the_start="' . HttpUtil::xmlEscape(self::lang('server.date.today', 'today')) . '" the_end="' . HttpUtil::xmlEscape(self::lang('server.date.tomorrow', 'tomorrow')) . '" />'
            . '<tree height="0" today="0" today_max="0" />'
            . '<grade id="1" exp="0" exp_min="0" exp_max="100" today_exp="0" today_exp_max="999999" />'
            . '<garden amount="0" organism_amount="0" garden_organism_amount="0" />'
            . '<cave amount="0" max_amount="0" open_grid_grade="0" open_grid_money="0" />'
            . '<territory honor="0" amount="0" max_amount="0" />'
            . '<fuben fuben_lcc="0" />'
            . '<copy_active state="0" />'
            . '<copy_zombie state="0" />'
            . '<friends amount="0" page_count="1" current="1" page_size="300"></friends>'
            . '</user></root>';

        return HttpResponse::xml($xml);
    }

    private static function inventoryQty(int $userId, string $itemId): ?int
    {
        require_once __DIR__ . '/../DB.php';
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('SELECT qty FROM inventory WHERE user_id = :uid AND item_id = :iid LIMIT 1');
            $stmt->execute([':uid' => $userId, ':iid' => $itemId]);
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['qty'])) {
                return (int)$row['qty'];
            }
        } catch (Throwable $e) {
            // ignore DB failures, keep original xml
        }
        return null;
    }

    private static function injectUserWallet(string $xml, ?int $gold, ?int $money): string
    {
        $sx = @simplexml_load_string($xml);
        if ($sx !== false && isset($sx->user)) {
            if ($gold !== null) {
                $sx->user['money'] = (string)$gold;
            }
            if ($money !== null) {
                $sx->user['rmb_money'] = (string)$money;
            }
            $out = $sx->asXML();
            return is_string($out) ? $out : $xml;
        }

        // Regex fallback for malformed xml spacing.
        $out = $xml;
        if ($gold !== null) {
            $out = preg_replace('/(<user\b[^>]*\bmoney=")[^"]*(")/i', '$1' . (string)$gold . '$2', $out, 1) ?? $out;
        }
        if ($money !== null) {
            $out = preg_replace('/(<user\b[^>]*\brmb_money=")[^"]*(")/i', '$1' . (string)$money . '$2', $out, 1) ?? $out;
        }
        return $out;
    }

    private static function injectUserVipFromState(string $xml, int $userId): string
    {
        if ($userId <= 0) {
            return $xml;
        }
        try {
            $store = new StateStore();
            $loaded = $store->load($userId);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            $vipNode = is_array($state['vip'] ?? null) ? $state['vip'] : [];
            $counters = is_array($state['counters'] ?? null) ? $state['counters'] : [];
            $vipEtime = (int)($vipNode['etime'] ?? $state['vip_etime'] ?? 0);
            $vipGrade = (int)($vipNode['grade'] ?? $state['vip_grade'] ?? 0);
            $vipExp = (int)($vipNode['exp'] ?? $state['vip_exp'] ?? 0);
            $vipRestoreHp = (int)($vipNode['restore_hp'] ?? $state['vip_restore_hp'] ?? 0);
            $stoneChaCount = (int)($counters['stone_cha_count'] ?? $state['stone_cha_count'] ?? 0);
            $caveAmount = (int)($counters['cave_amount'] ?? $state['cave_amount'] ?? 0);
            $fubenLcc = (int)($counters['fuben_lcc'] ?? $state['fuben_lcc'] ?? 0);
            $tree = self::prepareTreeState($state);
            $treeHeight = (int)($tree['height'] ?? 0);
            $treeUsed = (int)($tree['today_used'] ?? 0);
            $treeMax = (int)($tree['today_max'] ?? 1);
            $sx = @simplexml_load_string($xml);
            if ($sx !== false && isset($sx->user)) {
                if ($vipEtime > 0) {
                    $sx->user['vip_etime'] = (string)$vipEtime;
                }
                if ($vipGrade > 0) {
                    $sx->user['vip_grade'] = (string)$vipGrade;
                }
                if ($vipExp > 0) {
                    $sx->user['vip_exp'] = (string)$vipExp;
                }
                if ($vipRestoreHp > 0) {
                    $sx->user['vip_restore_hp'] = (string)$vipRestoreHp;
                }
                $sx->user['stone_cha_count'] = (string)$stoneChaCount;
                if (isset($sx->user->cave)) {
                    $sx->user->cave['amount'] = (string)$caveAmount;
                }
                if (isset($sx->user->fuben)) {
                    $sx->user->fuben['fuben_lcc'] = (string)$fubenLcc;
                }
                if (isset($sx->user->tree)) {
                    $sx->user->tree['height'] = (string)$treeHeight;
                    $sx->user->tree['today'] = (string)$treeUsed;
                    $sx->user->tree['today_max'] = (string)$treeMax;
                }
                $out = $sx->asXML();
                return is_string($out) ? $out : $xml;
            }
        } catch (Throwable $e) {
            // keep original xml
        }
        return $xml;
    }

    private static function injectUserProgressFromState(string $xml, int $userId): string
    {
        if ($userId <= 0) {
            return $xml;
        }
        try {
            $store = new StateStore();
            $loaded = $store->load($userId);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            $user = is_array($state['user'] ?? null) ? $state['user'] : [];

            $grade = max(1, (int)($user['grade'] ?? 20));
            $exp = max(0, (int)($user['exp'] ?? 0));
            $todayExp = max(0, (int)($user['today_exp'] ?? 0));
            $todayExpMax = max(1, (int)($user['today_exp_max'] ?? 2200));
            $expMin = self::gradeExpMin($grade);
            $expMax = self::gradeExpMax($grade);

            $sx = @simplexml_load_string($xml);
            if ($sx !== false && isset($sx->user) && isset($sx->user->grade)) {
                $sx->user->grade['id'] = (string)$grade;
                $sx->user->grade['exp'] = (string)$exp;
                $sx->user->grade['exp_min'] = (string)$expMin;
                $sx->user->grade['exp_max'] = (string)$expMax;
                $sx->user->grade['today_exp'] = (string)$todayExp;
                $sx->user->grade['today_exp_max'] = (string)$todayExpMax;
                $out = $sx->asXML();
                return is_string($out) ? $out : $xml;
            }
        } catch (Throwable $e) {
            // keep original xml
        }
        return $xml;
    }

    private static function gradeExpMin(int $grade): int
    {
        $g = max(1, $grade);
        $cfg = self::loadUserLevelExpCfg();
        if (($cfg['mode'] ?? '') === 'curve') {
            return self::curveCumulativeExp($g - 1, $cfg);
        }
        $levels = is_array($cfg['levels'] ?? null) ? $cfg['levels'] : [];
        $k = (string)$g;
        if (isset($levels[$k]) && is_array($levels[$k]) && isset($levels[$k]['exp_min'])) {
            return max(0, (int)$levels[$k]['exp_min']);
        }
        $step = max(1, (int)($cfg['default_step'] ?? 100));
        return ($g - 1) * $step;
    }

    private static function gradeExpMax(int $grade): int
    {
        $g = max(1, $grade);
        $cfg = self::loadUserLevelExpCfg();
        if (($cfg['mode'] ?? '') === 'curve') {
            return max(1, self::curveCumulativeExp($g, $cfg));
        }
        $levels = is_array($cfg['levels'] ?? null) ? $cfg['levels'] : [];
        $k = (string)$g;
        if (isset($levels[$k]) && is_array($levels[$k]) && isset($levels[$k]['exp_max'])) {
            return max(1, (int)$levels[$k]['exp_max']);
        }
        $step = max(1, (int)($cfg['default_step'] ?? 100));
        return $g * $step;
    }

    private static function loadUserLevelExpCfg(): array
    {
        static $cfg = null;
        if (is_array($cfg)) {
            return $cfg;
        }
        $path = realpath(__DIR__ . '/../../../runtime/config/user_level_exp.json')
            ?: (__DIR__ . '/../../../runtime/config/user_level_exp.json');
        if (!is_file($path)) {
            $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
            return $cfg;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
            return $cfg;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $cfg = ['default_step' => 100, 'levels' => [], 'max_level' => 999];
            return $cfg;
        }
        $cfg = $decoded + ['default_step' => 100, 'levels' => [], 'max_level' => 999];
        return $cfg;
    }

    private static function curveCumulativeExp(int $level, array $cfg): int
    {
        $lv = max(0, $level);
        $curve = is_array($cfg['curve'] ?? null) ? $cfg['curve'] : [];
        $base = (float)($curve['base'] ?? 32120.0);
        $rate = (float)($curve['rate'] ?? 1.00679);
        if ($base <= 0 || $rate <= 1.0) {
            return $lv * max(1, (int)($cfg['default_step'] ?? 100));
        }
        $v = $base * (pow($rate, $lv) - 1.0);
        return max(0, (int)round($v));
    }

    /**
     * @param array{seed:string,uid:int} $ctx
     */
    public static function warehouse(array $ctx): HttpResponse
    {
        // Load template XML first, then project runtime inventory into it.
        $override = realpath(__DIR__ . '/../../../runtime/config/http/warehouse.index.xml')
            ?: (__DIR__ . '/../../../runtime/config/http/warehouse.index.xml');
        $xml = '';
        if (is_file($override)) {
            $raw = file_get_contents($override);
            if (is_string($raw) && $raw !== '') {
                $xml = $raw;
            }
        }

        if ($xml === '') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>'
                . '<root><response><status>success</status></response>'
                . '<warehouse organism_grid_amount="100" tool_grid_amount="200">'
                . '<open_info><organism grade="0" money="0" /><tool grade="0" money="0" /></open_info>'
                . '<tools></tools>'
                . '<organisms><organisms_arena ids="" /><organisms_territory ids="" /><organisms_serverbattle ids="" /></organisms>'
                . '</warehouse></root>';
        }

        $sig = isset($ctx['sig']) && is_string($ctx['sig']) ? trim($ctx['sig']) : '';
        $session = SessionResolver::resolveFromRequest($sig);
        $uid = (int)($session['user_id'] ?? 0);
        if ($uid <= 0) {
            return HttpResponse::xml($xml);
        }

        $rows = [];
        $organismRows = [];
        try {
            require_once __DIR__ . '/../DB.php';
            require_once __DIR__ . '/../dao/OrgDao.php';
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('SELECT item_id, qty FROM inventory WHERE user_id = :uid AND qty > 0 ORDER BY item_id');
            $stmt->execute([':uid' => $uid]);
            while ($row = $stmt->fetch()) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'item_id' => (string)($row['item_id'] ?? ''),
                    'qty' => (int)($row['qty'] ?? 0),
                ];
            }
            $orgDao = new OrgDao($pdo);
            $orgDao->seedFromWarehouseTemplate($uid);
            $organismRows = $orgDao->getByUser($uid);
            // Keep warehouse "in garden" marker (gi) synchronized with garden slots state.
            try {
                $store = new StateStore();
                $loaded = $store->load($uid);
                $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
                $slots = is_array($state['garden']['slots'] ?? null) ? $state['garden']['slots'] : [];
                $inGarden = [];
                foreach ($slots as $slot) {
                    if (!is_array($slot)) {
                        continue;
                    }
                    $oid = (int)($slot['org_id'] ?? 0);
                    if ($oid > 0) {
                        $inGarden[$oid] = true;
                    }
                }
                if ($inGarden !== []) {
                    foreach ($organismRows as &$r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $oid = (int)($r['org_id'] ?? 0);
                        $r['gi'] = !empty($inGarden[$oid]) ? $uid : 0;
                    }
                    unset($r);
                } else {
                    foreach ($organismRows as &$r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $r['gi'] = 0;
                    }
                    unset($r);
                }
            } catch (Throwable $e) {
                // non-fatal: fallback to DB values
            }
            // Guard against corrupted organisms rows: only keep tpl_id that exists in organism.xml.
            $validTplIds = self::loadValidOrganismTplIdSet();
            if ($validTplIds !== []) {
                $organismRows = array_values(array_filter(
                    $organismRows,
                    static function ($r) use ($validTplIds): bool {
                        if (!is_array($r)) {
                            return false;
                        }
                        $tplId = (int)($r['tpl_id'] ?? 0);
                        $orgId = (int)($r['org_id'] ?? 0);
                        if ($orgId <= 0 || $tplId <= 0) {
                            return false;
                        }
                        return isset($validTplIds[$tplId]);
                    }
                ));
            }
            $organismRows = self::sanitizeOrganismRows($organismRows);
        } catch (Throwable $e) {
            // Never return a potentially corrupted template organisms list.
            return HttpResponse::xml(self::sanitizeWarehouseTemplateXml($xml));
        }

        $itemsMap = [];
        $itemsCfg = realpath(__DIR__ . '/../../../runtime/config/items.json')
            ?: (__DIR__ . '/../../../runtime/config/items.json');
        if (is_file($itemsCfg)) {
            $raw = file_get_contents($itemsCfg);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $itemsMap = $decoded;
                }
            }
        }

        $out = WarehouseXmlBuilder::build($xml, $rows, $itemsMap, $organismRows);

        try {
            $store = new StateStore();
            $loaded = $store->load($uid);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            if (isset($state['inventory_dirty'])) {
                $state['inventory_dirty'] = 0;
                $state['inventory_dirty_at'] = date('Y-m-d H:i:s');
                $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';
                $store->save($uid, $phase, $state);
            }
        } catch (Throwable $e) {
            // no-op
        }

        return HttpResponse::xml($out);
    }

    /**
     * @return array<int,bool> tpl_id => true
     */
    private static function loadValidOrganismTplIdSet(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $cache = [];
        $path = realpath(__DIR__ . '/../../public/php_xml/organism.xml')
            ?: (__DIR__ . '/../../public/php_xml/organism.xml');
        if (!is_file($path)) {
            return $cache;
        }
        $xml = file_get_contents($path);
        if (!is_string($xml) || $xml === '') {
            return $cache;
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return $cache;
        }
        foreach ($sx->xpath('//item') ?: [] as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $cache[$id] = true;
            }
        }
        return $cache;
    }

    private static function sanitizeWarehouseTemplateXml(string $xml): string
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false || !isset($sx->warehouse->organisms)) {
            return $xml;
        }
        unset($sx->warehouse->organisms->item);
        $out = $sx->asXML();
        return is_string($out) && $out !== '' ? $out : $xml;
    }

    /**
     * Clamp extreme organism numeric fields to keep warehouse XML client-safe.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function sanitizeOrganismRows(array $rows): array
    {
        $max = 999999999;
        $safe = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $ints = [
                'level', 'quality', 'exp', 'hp', 'hp_max', 'attack', 'miss', 'speed', 'precision_val',
                'new_miss', 'new_precision', 'mature', 'ss', 'sh', 'sa', 'spr', 'sm',
                'new_syn_precision', 'new_syn_miss', 'fight'
            ];
            foreach ($ints as $k) {
                if (!array_key_exists($k, $r)) {
                    continue;
                }
                $v = (int)$r[$k];
                if ($v < 0) {
                    $v = 0;
                }
                if ($v > $max) {
                    $v = $max;
                }
                $r[$k] = $v;
            }
            if (isset($r['quality_name']) && !is_string($r['quality_name'])) {
                $r['quality_name'] = 'Q1';
            }
            $safe[] = $r;
        }
        return $safe;
    }

    /**
     * @param array{seed:string,uid:int} $ctx
     */
    public static function recommendFriend(array $ctx): HttpResponse
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . '<friends amount="0" page_count="1" current="1" page_size="300"></friends></root>';
        return HttpResponse::xml($xml);
    }

    /**
     * Invite reward rules (HTTP XML). Used by InvitePrizeWindow -> loadInviteInfo().
     * @param array{seed:string,uid:int} $ctx
     */
    public static function defaultInvite(array $ctx): HttpResponse
    {
        // Editable override.
        $override = realpath(__DIR__ . '/../../../runtime/config/http/default.invite.xml')
            ?: (__DIR__ . '/../../../runtime/config/http/default.invite.xml');
        if (is_file($override)) {
            $xml = file_get_contents($override);
            if (is_string($xml) && $xml !== '') {
                return HttpResponse::xml($xml);
            }
        }

        // Minimal rule table for invite rewards.
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . '<invite_reward>'
            . '<item id="1" num="1"><organisms></organisms><tools><item id="1001" amount="10"/></tools></item>'
            . '<item id="2" num="2"><organisms></organisms><tools><item id="1001" amount="20"/></tools></item>'
            . '<item id="3" num="3"><organisms></organisms><tools><item id="1001" amount="30"/></tools></item>'
            . '<item id="4" num="4"><organisms></organisms><tools><item id="1001" amount="40"/></tools></item>'
            . '</invite_reward>'
            . '</root>';
        return HttpResponse::xml($xml);
    }

    /**
     * Downline friends list for invite rewards (HTTP XML). Used by InvitePrizeWindow -> loadFriendInfo().
     * @param array{seed:string,uid:int} $ctx
     */
    public static function inviteDownline(array $ctx): HttpResponse
    {
        $override = realpath(__DIR__ . '/../../../runtime/config/http/invite.downline.xml')
            ?: (__DIR__ . '/../../../runtime/config/http/invite.downline.xml');
        if (is_file($override)) {
            $xml = file_get_contents($override);
            if (is_string($xml) && $xml !== '') {
                return HttpResponse::xml($xml);
            }
        }

        $uid = (int)$ctx['uid'];
        $face = HttpUtil::xmlEscape('/pvz/avatar/' . $uid . '.jpg');

        // starts: 1 => can claim, 0 => already claimed, -1 => not eligible
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . '<reward grade="1" money="0" charm="0"/>'
            . '<down_line>'
            . '<item id="10001" nickname="friend1" grade="1" big_face="' . $face . '" small_face="' . $face . '" starts="1" vip_etime="0" vip_grade="0"/>'
            . '<item id="10002" nickname="friend2" grade="1" big_face="' . $face . '" small_face="' . $face . '" starts="-1" vip_etime="0" vip_grade="0"/>'
            . '</down_line>'
            . '</root>';
        return HttpResponse::xml($xml);
    }

    /**
     * Claim invite reward by invite count (HTTP XML). Used by InvitePrizeKindsInviteLabel -> shop/invite/id/<id>.
     * @param array{seed:string,uid:int} $ctx
     */
    public static function shopInviteClaim(array $ctx): HttpResponse
    {
        // Caller uses URLConnectionConstants.URL_INVITE_AMOUNT = "shop/invite/id/" + rewardId
        // We allow an override per rewardId.
        $path = HttpUtil::routePath((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $rewardId = 1;
        if (preg_match('#^shop/invite/id/(\\d+)/#i', $path, $m)) {
            $rewardId = (int)$m[1];
        }

        $override = __DIR__ . '/../../../runtime/config/http/shop.invite/' . $rewardId . '.xml';
        if (is_file($override)) {
            $xml = file_get_contents($override);
            if (is_string($xml) && $xml !== '') {
                return HttpResponse::xml($xml);
            }
        }

        // Minimal success payload compatible with XmlInvitePrizeInvite.getInvitePrizes().
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . '<invite_reward id="' . $rewardId . '" num="' . $rewardId . '">'
            . '<organisms></organisms>'
            . '<tools><item id="1001" amount="' . (10 * $rewardId) . '"/></tools>'
            . '</invite_reward>'
            . '</root>';
        return HttpResponse::xml($xml);
    }

    /**
     * Claim friend invite-up reward (HTTP XML). Used by InvitePrizeKindsFriendLabel -> user/inviteup/id/<friendId>.
     * @param array{seed:string,uid:int} $ctx
     */
    public static function userInviteUpClaim(array $ctx): HttpResponse
    {
        $path = HttpUtil::routePath((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $friendId = 0;
        if (preg_match('#^user/inviteup/id/(\\d+)/#i', $path, $m)) {
            $friendId = (int)$m[1];
        }

        $override = __DIR__ . '/../../../runtime/config/http/user.inviteup/' . $friendId . '.xml';
        if (is_file($override)) {
            $xml = file_get_contents($override);
            if (is_string($xml) && $xml !== '') {
                return HttpResponse::xml($xml);
            }
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>success</status></response>'
            . '<invite_up_raward money="100" charm="0"/>'
            . '</root>';
        return HttpResponse::xml($xml);
    }

    /**
     * World Tree: tree/addheight
     * Client expects XML:
     *   <response><status>success</status></response>
     *   <tree message="..." height="N"/>
     *   <awards><tools><item id="x" amount="y"/></tools></awards>
     *
     * @param array{seed:string,uid:int} $ctx
     */
    public static function treeAddHeight(array $ctx): HttpResponse
    {
        $sig = isset($ctx['sig']) && is_string($ctx['sig']) ? trim($ctx['sig']) : '';
        $session = SessionResolver::resolveFromRequest($sig);
        $uid = (int)($session['user_id'] ?? 0);
        if ($uid <= 0) {
            return HttpResponse::xml(self::treeFailXml('not logged in', 'LoginError'));
        }

        try {
            require_once __DIR__ . '/../DB.php';
            $pdo = DB::pdo();
            $store = new StateStore();
            $loaded = $store->load($uid);
            $state = is_array($loaded['state'] ?? null) ? $loaded['state'] : [];
            $phase = is_string($loaded['phase'] ?? null) && $loaded['phase'] !== '' ? $loaded['phase'] : 'INIT';

            $tree = self::prepareTreeState($state);
            $todayMax = max(1, (int)($tree['today_max'] ?? 1));
            $todayUsed = max(0, (int)($tree['today_used'] ?? 0));
            if ($todayUsed >= $todayMax) {
                return HttpResponse::xml(self::treeFailXml('today limit reached'));
            }

            if (!self::inventoryRemove($uid, 'tool:4', 1)) {
                return HttpResponse::xml(self::treeFailXml('not enough fertiliser'));
            }

            $rewards = self::treePickRewards();
            if (count($rewards) < 1) {
                $rewards = [['id' => 18, 'amount' => 10]];
            }
            foreach ($rewards as $rw) {
                $itemId = max(1, (int)($rw['id'] ?? 0));
                $amount = max(1, (int)($rw['amount'] ?? 1));
                self::inventoryAdd($uid, 'tool:' . $itemId, $amount);
            }

            $tree['height'] = max(0, (int)($tree['height'] ?? 0)) + 1;
            $tree['today_used'] = $todayUsed + 1;
            $state['tree'] = $tree;
            $store->save($uid, $phase, $state);

            $xml = '<?xml version="1.0" encoding="utf-8"?>'
                . '<root>'
                . '<response><status>success</status></response>'
                . '<tree message="' . HttpUtil::xmlEscape('tree height increased') . '" height="' . (int)$tree['height'] . '"/>'
                . '<awards><tools>' . self::treeRewardsToXml($rewards) . '</tools></awards>'
                . '</root>';
            return HttpResponse::xml($xml);
        } catch (Throwable $e) {
            return HttpResponse::xml(self::treeFailXml($e->getMessage()));
        }
    }

    private static function treeFailXml(string $message, string $className = 'Scene_Exception_Tree_AddHeight'): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<root><response><status>failed</status><error class_name="'
            . HttpUtil::xmlEscape($className) . '" message="' . HttpUtil::xmlEscape($message)
            . '"/></response></root>';
    }

    /** @return array{date:string,height:int,today_used:int,today_max:int} */
    private static function prepareTreeState(array &$state): array
    {
        $today = date('Y-m-d');
        $tree = is_array($state['tree'] ?? null) ? $state['tree'] : [];
        $day = (string)($tree['date'] ?? '');
        if ($day !== $today) {
            $tree['date'] = $today;
            $tree['today_used'] = 0;
        }
        $tree['height'] = max(0, (int)($tree['height'] ?? 0));
        $tree['today_used'] = max(0, (int)($tree['today_used'] ?? 0));
        $tree['today_max'] = max(1, (int)($tree['today_max'] ?? 1));
        $state['tree'] = $tree;
        return $tree;
    }

    /** @return array<int,array{id:int,amount:int}> */
    private static function treePickRewards(): array
    {
        // No capture available yet; keep this configurable.
        $draws = 20;
        $countMin = 1;
        $countMax = 5;
        $exclude = [1, 2];
        $pool = [];

        $path = realpath(__DIR__ . '/../../../runtime/config/tree_rewards.json')
            ?: (__DIR__ . '/../../../runtime/config/tree_rewards.json');
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && trim($raw) !== '') {
                $cfg = json_decode($raw, true);
                if (is_array($cfg)) {
                    $draws = max(1, (int)($cfg['draws'] ?? 20));
                    $countMin = max(1, (int)($cfg['count_min'] ?? 1));
                    $countMax = max($countMin, (int)($cfg['count_max'] ?? 5));
                    $exclude = array_values(array_map('intval', is_array($cfg['exclude_ids'] ?? null) ? $cfg['exclude_ids'] : [1, 2]));
                    if (is_array($cfg['tools'] ?? null) && count($cfg['tools']) > 0) {
                        foreach ($cfg['tools'] as $rw) {
                            if (!is_array($rw)) {
                                continue;
                            }
                            $id = max(1, (int)($rw['id'] ?? 0));
                            if ($id > 0) {
                                $pool[] = $id;
                            }
                        }
                    }
                }
            }
        }

        if (count($pool) < 1) {
            $pool = self::treeLoadStableToolIds($exclude);
        }
        if (count($pool) < 1) {
            $pool = [18];
        }

        $pool = array_values(array_unique(array_map('intval', $pool)));
        shuffle($pool);
        $pickCount = min($draws, count($pool));
        $picked = array_slice($pool, 0, $pickCount);

        $out = [];
        foreach ($picked as $id) {
            $out[] = [
                'id' => $id,
                'amount' => random_int($countMin, $countMax),
            ];
        }
        return $out;
    }

    private static function treeRewardsToXml(array $rewards): string
    {
        $xml = '';
        foreach ($rewards as $rw) {
            if (!is_array($rw)) {
                continue;
            }
            $id = max(1, (int)($rw['id'] ?? 0));
            $amount = max(1, (int)($rw['amount'] ?? 1));
            $xml .= '<item id="' . $id . '" amount="' . $amount . '"/>';
        }
        return $xml;
    }

    /** @return array<int,int> */
    private static function treeLoadStableToolIds(array $exclude = []): array
    {
        $toolPath = realpath(__DIR__ . '/../../public/php_xml/tool.xml')
            ?: (__DIR__ . '/../../public/php_xml/tool.xml');
        if (!is_file($toolPath)) {
            return [];
        }
        $raw = file_get_contents($toolPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $toolXmlIds = self::extractToolIdsFromXmlRaw($raw, $exclude);
        $toolXmlIds = array_values(array_unique($toolXmlIds));
        if (count($toolXmlIds) < 1) {
            return [];
        }

        // Optional compatibility filter with local browser/proxy cache tool.xml.
        $clientToolPath = realpath(__DIR__ . '/../../../../tools/proxy_web/cache/pvz/php_xml/tool.xml')
            ?: (__DIR__ . '/../../../../tools/proxy_web/cache/pvz/php_xml/tool.xml');
        if (is_file($clientToolPath)) {
            $clientRaw = file_get_contents($clientToolPath);
            if (is_string($clientRaw) && trim($clientRaw) !== '') {
                $clientIds = self::extractToolIdsFromXmlRaw($clientRaw, $exclude);
                if (count($clientIds) > 0) {
                    $toolXmlIds = array_values(array_intersect($toolXmlIds, $clientIds));
                    if (count($toolXmlIds) < 1) {
                        return [];
                    }
                }
            }
        }

        // Intersect with items.json so only stable/renderable tools are used.
        $itemsPath = realpath(__DIR__ . '/../../../runtime/config/items.json')
            ?: (__DIR__ . '/../../../runtime/config/items.json');
        if (!is_file($itemsPath)) {
            return $toolXmlIds;
        }
        $itemsRaw = file_get_contents($itemsPath);
        if (!is_string($itemsRaw) || trim($itemsRaw) === '') {
            return $toolXmlIds;
        }
        $items = json_decode($itemsRaw, true);
        if (!is_array($items)) {
            return $toolXmlIds;
        }
        $stable = [];
        foreach ($toolXmlIds as $id) {
            if (isset($items[(string)$id]) && is_array($items[(string)$id])) {
                $stable[] = $id;
            }
        }
        return array_values(array_unique($stable));
    }

    /** @return array<int,int> */
    private static function extractToolIdsFromXmlRaw(string $raw, array $exclude = []): array
    {
        $ids = [];
        if (preg_match_all('/<item\\s+[^>]*id="(\\d+)"[^>]*img_id="(\\d+)"/i', $raw, $m)) {
            $n = min(count($m[1]), count($m[2]));
            for ($i = 0; $i < $n; $i++) {
                $id = (int)$m[1][$i];
                $img = (int)$m[2][$i];
                if ($id <= 0 || $img <= 0) {
                    continue;
                }
                if (in_array($id, $exclude, true)) {
                    continue;
                }
                $ids[] = $id;
            }
        } elseif (preg_match_all('/<item\\s+[^>]*id="(\\d+)"/i', $raw, $m2)) {
            foreach ($m2[1] as $idRaw) {
                $id = (int)$idRaw;
                if ($id <= 0 || in_array($id, $exclude, true)) {
                    continue;
                }
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function inventoryAdd(int $userId, string $itemId, int $qty): void
    {
        if ($userId <= 0 || $qty <= 0) {
            return;
        }
        require_once __DIR__ . '/../DB.php';
        $pdo = DB::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO inventory (user_id,item_id,qty) VALUES (:uid,:iid,:qty)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':uid' => $userId, ':iid' => $itemId, ':qty' => $qty]);
    }

    private static function inventoryRemove(int $userId, string $itemId, int $qty): bool
    {
        if ($userId <= 0 || $qty <= 0) {
            return true;
        }
        require_once __DIR__ . '/../DB.php';
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT qty FROM inventory WHERE user_id = :uid AND item_id = :iid LIMIT 1');
        $stmt->execute([':uid' => $userId, ':iid' => $itemId]);
        $row = $stmt->fetch();
        $cur = is_array($row) ? max(0, (int)($row['qty'] ?? 0)) : 0;
        if ($cur < $qty) {
            return false;
        }
        $up = $pdo->prepare(
            'UPDATE inventory SET qty = qty - :q_sub, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :uid AND item_id = :iid AND qty >= :q_chk'
        );
        $up->execute([
            ':q_sub' => $qty,
            ':q_chk' => $qty,
            ':uid' => $userId,
            ':iid' => $itemId,
        ]);
        return $up->rowCount() > 0;
    }

    private static function normalizeGardenState(array $state): array
    {
        if (!isset($state['garden']) || !is_array($state['garden'])) {
            $state['garden'] = [];
        }
        if (!isset($state['garden']['slots']) || !is_array($state['garden']['slots'])) {
            $state['garden']['slots'] = [];
        }
        if (!isset($state['counters']) || !is_array($state['counters'])) {
            $state['counters'] = [];
        }
        $state['counters']['garden_cha_count'] = max(0, (int)($state['counters']['garden_cha_count'] ?? 0));
        return $state;
    }

    private static function lang(string $key, string $fallback = ''): string
    {
        static $map = null;
        if (!is_array($map)) {
            $map = [];
            $primaryRoot = dirname(__DIR__, 2) . '/public/config/lang';
            $fallbackRoot = dirname(__DIR__, 4) . '/cache/youkia/config/lang';
            foreach ([
                $primaryRoot . '/server_cn.xml',
                $primaryRoot . '/language_cn.xml',
                $fallbackRoot . '/server_cn.xml',
                $fallbackRoot . '/language_cn.xml',
            ] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $raw = @file_get_contents($file);
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                if (preg_match_all('/<item\\s+name="([^"]+)"\\s*>(.*?)<\\/item>/su', $raw, $m, PREG_SET_ORDER)) {
                    foreach ($m as $row) {
                        $k = trim((string)$row[1]);
                        $v = trim(html_entity_decode((string)$row[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if ($k !== '' && $v !== '') {
                            $map[$k] = $v;
                        }
                    }
                }
            }
        }
        return (is_array($map) && isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '')
            ? $map[$key]
            : ($fallback !== '' ? $fallback : $key);
    }
}

