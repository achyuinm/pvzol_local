<?php
declare(strict_types=1);

require_once __DIR__ . '/../amf/AmfGateway.php';

/**
 * Analyze captured api.reward.openbox AMF requests/responses and infer candidate idempotency keys.
 *
 * Usage:
 *   C:\php\php.exe server/game_root/server/app/bin/analyze_openbox_idempotency.php [rawDir]
 *
 * Default rawDir:
 *   tools/proxy_web/AMF_CAPTURE/raw
 */

$rawDir = $argv[1] ?? (__DIR__ . '/../../../../../tools/proxy_web/AMF_CAPTURE/raw');
$rawDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawDir);
if (!is_dir($rawDir)) {
    fwrite(STDERR, "raw dir not found: {$rawDir}\n");
    exit(2);
}

$reqFiles = glob($rawDir . DIRECTORY_SEPARATOR . '*_REQ_api.reward.openbox.amf') ?: [];
sort($reqFiles, SORT_STRING);
if (!$reqFiles) {
    fwrite(STDERR, "no openbox req files in: {$rawDir}\n");
    exit(1);
}

$rows = [];
foreach ($reqFiles as $reqPath) {
    $base = basename($reqPath);
    if (!preg_match('/^(\d+)_([0-9_]+)_REQ_api\.reward\.openbox\.amf$/', $base, $m)) {
        continue;
    }
    $seq = (int)$m[1];
    $ts = $m[2];

    $reqRaw = file_get_contents($reqPath);
    if (!is_string($reqRaw) || $reqRaw === '') {
        continue;
    }
    try {
        $reqEnv = AmfGateway::parseRequest($reqRaw);
        $reqBody = AmfGateway::extractFirstMessageBodyRaw($reqRaw);
        $r = new AmfByteReader($reqBody);
        $params = Amf0::readValueDecode($r);
    } catch (Throwable $e) {
        continue;
    }

    $rspCandidates = glob($rawDir . DIRECTORY_SEPARATOR . sprintf('%04d_', $seq) . '*_RSP_*_api.reward.openbox.amf') ?: [];
    $rspHash = '';
    $rspValueHash = '';
    if ($rspCandidates) {
        $rspPath = $rspCandidates[0];
        $rspRaw = file_get_contents($rspPath);
        if (is_string($rspRaw) && $rspRaw !== '') {
            $rspHash = sha1($rspRaw);
            try {
                $rspBody = AmfGateway::extractFirstMessageBodyRaw($rspRaw);
                $rr = new AmfByteReader($rspBody);
                $rspValue = Amf0::readValueDecode($rr);
                $rspValueHash = sha1(json_encode($rspValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            } catch (Throwable $e) {
                $rspValueHash = '';
            }
        }
    }

    $paramJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    $rows[] = [
        'seq' => $seq,
        'ts' => $ts,
        'target' => $reqEnv->targetUri,
        'responseUri' => $reqEnv->responseUri,
        'reqHash' => sha1($reqRaw),
        'paramJson' => $paramJson,
        'paramHash' => sha1($paramJson),
        'rspHash' => $rspHash,
        'rspValueHash' => $rspValueHash,
    ];
}

if (!$rows) {
    fwrite(STDERR, "no decodable openbox captures\n");
    exit(1);
}

$byParam = [];
$byReq = [];
$byRespUri = [];
foreach ($rows as $row) {
    $byParam[$row['paramHash']][] = $row;
    $byReq[$row['reqHash']][] = $row;
    $byRespUri[$row['responseUri']][] = $row;
}

echo "openbox captures: " . count($rows) . PHP_EOL;
echo "unique param signatures: " . count($byParam) . PHP_EOL;
echo "unique req hashes: " . count($byReq) . PHP_EOL;
echo "unique responseUri: " . count($byRespUri) . PHP_EOL;
echo PHP_EOL;

echo "Top repeated param signatures:" . PHP_EOL;
uasort($byParam, static fn(array $a, array $b): int => count($b) <=> count($a));
$i = 0;
foreach ($byParam as $sig => $list) {
    $i++;
    $rspValUniq = [];
    foreach ($list as $r) {
        if ($r['rspValueHash'] !== '') {
            $rspValUniq[$r['rspValueHash']] = true;
        }
    }
    $sample = $list[0];
    echo sprintf(
        "  #%d count=%d rspValueVariants=%d params=%s\n",
        $i,
        count($list),
        count($rspValUniq),
        $sample['paramJson']
    );
    if ($i >= 10) {
        break;
    }
}
echo PHP_EOL;

echo "responseUri reuse (candidate client request-id):" . PHP_EOL;
$reused = 0;
foreach ($byRespUri as $uri => $list) {
    if (count($list) > 1) {
        $reused++;
        echo sprintf("  reused responseUri=%s count=%d\n", $uri, count($list));
    }
}
if ($reused === 0) {
    echo "  none (responseUri looks per-request unique)\n";
}
echo PHP_EOL;

$reportPath = __DIR__ . '/../../../runtime/logs/openbox_idempotency_report.json';
$report = [
    'generated_at' => date('Y-m-d H:i:s'),
    'raw_dir' => $rawDir,
    'summary' => [
        'total' => count($rows),
        'unique_param' => count($byParam),
        'unique_req_hash' => count($byReq),
        'unique_response_uri' => count($byRespUri),
    ],
    'rows' => $rows,
];
@file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "report saved: {$reportPath}\n";
