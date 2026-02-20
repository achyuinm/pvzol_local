<?php
declare(strict_types=1);

/**
 * Find the "largest-looking" organism entry in warehouse.index.xml and normalize its
 * stats to values that are safe for AS3 int conversions (<= 2,147,483,647), while
 * keeping HP/HM as big integer strings (no scientific notation).
 *
 * This is a quick experiment tool to debug "clicking an organism does nothing"
 * when extremely large numeric fields overflow inside the SWF UI.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/tune_warehouse_max_plant.php
 */

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function digitsOnly(?string $s): string
{
    if (!is_string($s)) {
        return '';
    }
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    // Keep only leading sign and digits (most XML values are digits already).
    if (preg_match('/^-?[0-9]+$/', $s) === 1) {
        return $s;
    }
    return '';
}

/**
 * Compare decimal integer strings without converting to PHP int/float.
 * Returns 1 if $a > $b, -1 if $a < $b, 0 if equal.
 */
function cmpDec(string $a, string $b): int
{
    $a = ltrim($a, '+');
    $b = ltrim($b, '+');
    $aNeg = str_starts_with($a, '-');
    $bNeg = str_starts_with($b, '-');
    if ($aNeg && !$bNeg) return -1;
    if (!$aNeg && $bNeg) return 1;
    if ($aNeg && $bNeg) {
        $a = substr($a, 1);
        $b = substr($b, 1);
        return -cmpDec($a, $b);
    }
    $a = ltrim($a, '0');
    $b = ltrim($b, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    if (strlen($a) !== strlen($b)) {
        return strlen($a) > strlen($b) ? 1 : -1;
    }
    if ($a === $b) return 0;
    return $a > $b ? 1 : -1;
}

$xmlPath = __DIR__ . '/../../../runtime/config/http/warehouse.index.xml';
if (!is_file($xmlPath)) {
    fail("Not found: {$xmlPath}");
}

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
libxml_use_internal_errors(true);
$ok = $dom->load($xmlPath, LIBXML_NONET);
if (!$ok) {
    fail("Failed to parse XML: {$xmlPath}");
}
libxml_clear_errors();

$xp = new DOMXPath($dom);
/** @var DOMNodeList $items */
$items = $xp->query('/root/warehouse/organisms/item');
if (!$items || $items->length === 0) {
    fail("No organism items found in: {$xmlPath}");
}

$best = null;
$bestKey = null;

for ($i = 0; $i < $items->length; $i++) {
    $el = $items->item($i);
    if (!$el instanceof DOMElement) continue;

    $hp = digitsOnly($el->getAttribute('hp'));
    $at = digitsOnly($el->getAttribute('at'));
    $fight = digitsOnly($el->getAttribute('fight'));

    // Key: prefer biggest HP, then biggest AT, then biggest FIGHT.
    $key = [
        $hp !== '' ? strlen(ltrim($hp, '-')) : 0,
        $hp !== '' ? $hp : '0',
        $at !== '' ? strlen(ltrim($at, '-')) : 0,
        $at !== '' ? $at : '0',
        $fight !== '' ? strlen(ltrim($fight, '-')) : 0,
        $fight !== '' ? $fight : '0',
        $el->getAttribute('id'),
    ];

    if ($best === null) {
        $best = $el;
        $bestKey = $key;
        continue;
    }

    // Compare keys without float conversion.
    // hpLen
    if ($key[0] !== $bestKey[0]) {
        if ($key[0] > $bestKey[0]) {
            $best = $el; $bestKey = $key;
        }
        continue;
    }
    // hp numeric
    $c = cmpDec($key[1], $bestKey[1]);
    if ($c !== 0) {
        if ($c > 0) { $best = $el; $bestKey = $key; }
        continue;
    }
    // atLen
    if ($key[2] !== $bestKey[2]) {
        if ($key[2] > $bestKey[2]) {
            $best = $el; $bestKey = $key;
        }
        continue;
    }
    // at numeric
    $c = cmpDec($key[3], $bestKey[3]);
    if ($c !== 0) {
        if ($c > 0) { $best = $el; $bestKey = $key; }
        continue;
    }
    // fightLen
    if ($key[4] !== $bestKey[4]) {
        if ($key[4] > $bestKey[4]) {
            $best = $el; $bestKey = $key;
        }
        continue;
    }
    // fight numeric
    $c = cmpDec($key[5], $bestKey[5]);
    if ($c !== 0) {
        if ($c > 0) { $best = $el; $bestKey = $key; }
        continue;
    }
}

if (!$best instanceof DOMElement) {
    fail("Internal error: best item not found");
}

$id = $best->getAttribute('id');

// Keep id/pid/gr/ma as-is (client uses int for those).
// Normalize the "big number" fields to safe int-range values.
$SAFE_INT = '2000000000'; // < 2^31
$SAFE_SPEED = '5000';
$SAFE_BIG_HP = '999999999999999999999999999999'; // 30 digits, BigInt-friendly.

$best->setAttribute('at', $SAFE_INT);
$best->setAttribute('mi', $SAFE_INT);
$best->setAttribute('pr', $SAFE_INT);
$best->setAttribute('new_miss', $SAFE_INT);
$best->setAttribute('new_precision', $SAFE_INT);
$best->setAttribute('sa', $SAFE_INT);
$best->setAttribute('sh', $SAFE_INT);
$best->setAttribute('sm', $SAFE_INT);
$best->setAttribute('spr', $SAFE_INT);
$best->setAttribute('ss', $SAFE_SPEED);
$best->setAttribute('sp', $SAFE_SPEED);
$best->setAttribute('new_syn_precision', $SAFE_INT);
$best->setAttribute('new_syn_miss', $SAFE_INT);
$best->setAttribute('fight', $SAFE_INT);
$best->setAttribute('hp', $SAFE_BIG_HP);
$best->setAttribute('hm', $SAFE_BIG_HP);

if (file_put_contents($xmlPath, $dom->saveXML()) === false) {
    fail("Failed to write: {$xmlPath}");
}

fwrite(STDOUT, "OK tuned organism id={$id} in warehouse.index.xml\n");
