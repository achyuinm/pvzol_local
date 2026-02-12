<?php
/**
 * Minimal dynamic endpoints for local PVZ.
 *
 * NOTE: This is intentionally small. As you implement a real server later,
 * move logic into server/app/* and keep this file as a thin dispatcher.
 */

declare(strict_types=1);

function send_text(string $text, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $text;
    exit;
}

function send_xml(string $xml, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $xml;
    exit;
}

function short_token(int $bytes = 16): string
{
    $b = random_bytes($bytes);
    return strtoupper(bin2hex($b));
}

function set_short_lived_token_cookie(): void
{
    // Real site sets a 1-second HttpOnly token frequently. Keep compatible.
    $token = short_token(16);
    setcookie('token', $token, [
        'expires' => time() + 1,
        'path' => '/',
        'httponly' => true,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
}

function xml_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function pvzol_seed(): string
{
    $seed = (string)($_COOKIE['pvzol'] ?? '');
    if ($seed !== '') {
        return $seed;
    }
    // Allow bootstrap via query string (launcher can pass ?seed=...).
    $seed = (string)($_GET['seed'] ?? '');
    if ($seed === '') {
        $seed = 'local';
    }
    // Persist for Flash cookie jar.
    setcookie('pvzol', $seed, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/',
        'httponly' => false,
        'secure' => false,
        'samesite' => 'Lax',
    ]);
    return $seed;
}

function user_id_from_seed(string $seed): int
{
    // Stable 1..2^31-1
    $h = hash('sha256', $seed, true);
    $v = unpack('N', substr($h, 0, 4))[1];
    $id = (int)($v & 0x7fffffff);
    return $id > 0 ? $id : 1;
}

function route_path(): string
{
    $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $prefix = '/pvz/index.php/';
    $pos = strpos($uri, $prefix);
    if ($pos === false) {
        return '';
    }
    return trim(substr($uri, $pos + strlen($prefix)), '/');
}

set_short_lived_token_cookie();
$seed = pvzol_seed();
$uid = user_id_from_seed($seed);

// AMF gateway placeholder. Real implementation later.
if (preg_match('#^/pvz/amf/?$#', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) {
    // For now just respond with 501 so you can see requests in logs/tools.
    send_text('AMF not implemented', 501);
}

$path = route_path();
if ($path === '') {
    send_text('pvz index.php alive');
}

// Boot chain endpoints observed from real traffic.
if (preg_match('#^default/isnew/sig/[a-f0-9]+$#i', $path)) {
    send_text('0');
}

if (preg_match('#^default/user/sig/[a-f0-9]+$#i', $path)) {
    $name = xml_escape('local_' . $uid);
    $face = xml_escape('/pvz/assets/avatar/' . $uid . '.jpg');
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<root><response><status>success</status></response>'
        . sprintf(
            '<user id="%d" name="%s" charm="0" money="0" date_award="0" reward_daily="0" has_reward_cus="0" has_reward_once="0" has_reward_sum="0" has_reward_first="0" state="" rmb_money="0" open_cave_grid="0" wins="0" is_new="0" login_reward="0" invite_amount="0" use_invite_num="0" max_use_invite_num="0" lottery_key="" banner_num="0" banner_url="" hasActivitys="1" face_url="" face="%s" vip_grade="0" vip_etime="0" vip_restore_hp="0" is_auto="0" serverbattle_status="0" IsNewTaskSystem="1" registrationReward="0" stone_cha_count="0" vip_exp="0">',
            $uid,
            $name,
            $face
        )
        . '<arena_rank_date old_start="今日" old_end="明日" the_start="今日" the_end="明日" />'
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
    send_xml($xml);
}

if (preg_match('#^Warehouse/index/sig/[a-f0-9]+$#i', $path)) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<root><response><status>success</status></response>'
        . '<warehouse organism_grid_amount="100" tool_grid_amount="200">'
        . '<open_info><organism grade="0" money="0" /><tool grade="0" money="0" /></open_info>'
        . '<tools></tools>'
        . '<organisms><organisms_arena ids="" /><organisms_territory ids="" /><organisms_serverbattle ids="" /></organisms>'
        . '</warehouse></root>';
    send_xml($xml);
}

if (preg_match('#^user/recommendfriend/sig/[a-f0-9]+$#i', $path)) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<root><response><status>success</status></response>'
        . '<friends amount="0" page_count="1" current="1" page_size="300"></friends></root>';
    send_xml($xml);
}

send_text('Not Found', 404);

