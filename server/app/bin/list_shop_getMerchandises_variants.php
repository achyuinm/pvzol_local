<?php
declare(strict_types=1);

// List captured api.shop.getMerchandises variants (request param -> response size).
//
// Usage:
//   C:\php\php.exe server/game_root/server/app/bin/list_shop_getMerchandises_variants.php

require_once __DIR__ . '/../amf/AmfGateway.php';

$root = realpath(__DIR__ . '/../../../../..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve repo root.\n");
    exit(1);
}

$dir = $root . DIRECTORY_SEPARATOR . 'file' . DIRECTORY_SEPARATOR . 'real_amf' . DIRECTORY_SEPARATOR . 'pure';
$reqs = glob($dir . DIRECTORY_SEPARATOR . '*_api.shop.getMerchandises.req.amf') ?: [];
sort($reqs);

foreach ($reqs as $reqPath) {
    $prefix = basename($reqPath, '.req.amf');
    $rspPath = $dir . DIRECTORY_SEPARATOR . $prefix . '.rsp.amf';
    if (!is_file($rspPath)) {
        continue;
    }

    $rawReq = file_get_contents($reqPath);
    if (!is_string($rawReq) || $rawReq === '') {
        continue;
    }
    $reqBody = AmfGateway::extractFirstMessageBodyRaw($rawReq);
    $r = new AmfByteReader($reqBody);
    $params = Amf0::readValueDecode($r);

    $param0 = null;
    if (is_array($params) && array_is_list($params) && count($params) > 0) {
        $param0 = $params[0];
    }

    $rspLen = filesize($rspPath) ?: 0;
    fwrite(STDOUT, basename($reqPath) . "\tparam0=" . json_encode($param0, JSON_UNESCAPED_UNICODE) . "\trsp_bytes={$rspLen}\n");
}

exit(0);

