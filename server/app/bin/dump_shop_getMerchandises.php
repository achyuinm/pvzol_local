<?php
declare(strict_types=1);

// Debug helper: decode captured api.shop.getMerchandises req/rsp enough to see params + top-level shape.
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/dump_shop_getMerchandises.php
//   C:\php\php.exe server/game_root/server/app/bin/dump_shop_getMerchandises.php path\\to\\req.amf path\\to\\rsp.amf

require_once __DIR__ . '/../amf/AmfGateway.php';

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$defaultReq = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure' . DIRECTORY_SEPARATOR . '0013_api.shop.getMerchandises.req.amf';
$defaultRsp = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure' . DIRECTORY_SEPARATOR . '0013_api.shop.getMerchandises.rsp.amf';

$reqPath = $argv[1] ?? $defaultReq;
$rspPath = $argv[2] ?? $defaultRsp;

foreach ([['req', $reqPath], ['rsp', $rspPath]] as [$label, $p]) {
    $real = realpath($p);
    if ($real === false) {
        fwrite(STDERR, strtoupper($label) . " not found: {$p}\n");
        exit(1);
    }
}

$reqRaw = file_get_contents($reqPath);
$rspRaw = file_get_contents($rspPath);
if (!is_string($reqRaw) || $reqRaw === '' || !is_string($rspRaw) || $rspRaw === '') {
    fwrite(STDERR, "Failed to read req/rsp.\n");
    exit(1);
}

$req = AmfGateway::parseRequest($reqRaw);
fwrite(STDOUT, "method={$req->targetUri} responseUri={$req->responseUri}\n");

$reqBody = AmfGateway::extractFirstMessageBodyRaw($reqRaw);
$rr = new AmfByteReader($reqBody);
$reqDecoded = Amf0::readValueDecode($rr);
fwrite(STDOUT, "req.params=" . json_encode($reqDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

$rspBody = AmfGateway::extractFirstMessageBodyRaw($rspRaw);
$sr = new AmfByteReader($rspBody);
$rspDecoded = Amf0::readValueDecode($sr);

if (is_array($rspDecoded) && array_is_list($rspDecoded)) {
    fwrite(STDOUT, "rsp is a list: n=" . count($rspDecoded) . "\n");
    $first = $rspDecoded[0] ?? null;
    if (is_array($first)) {
        $keys = array_keys($first);
        sort($keys);
        fwrite(STDOUT, "  sample[0] keys (" . count($keys) . "): " . implode(', ', $keys) . "\n");
        fwrite(STDOUT, "  sample[0] json: " . json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
} elseif (is_array($rspDecoded)) {
    $keys = array_keys($rspDecoded);
    sort($keys);
    fwrite(STDOUT, "rsp keys (" . count($keys) . "): " . implode(', ', $keys) . "\n");
} else {
    fwrite(STDOUT, "rsp type=" . gettype($rspDecoded) . "\n");
}

exit(0);
