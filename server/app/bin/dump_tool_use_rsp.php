<?php
require_once __DIR__ . '/../amf/AmfGateway.php';
require_once __DIR__ . '/../amf/Amf0.php';
require_once __DIR__ . '/../amf/AmfByteStream.php';
$files = [
'D:/Hreta_working/file/real_amf/pure/0019_api.tool.useOf.rsp.amf',
'D:/Hreta_working/file/real_amf/pure/0077_api.tool.useOf.rsp.amf',
'D:/Hreta_working/file/real_amf/pure/0230_api.tool.useOf.rsp.amf',
'D:/Hreta_working/file/real_amf/pure/api.tool.useOf.rsp.latest.amf',
];
foreach ($files as $f){
 if(!is_file($f)){continue;}
 $raw=file_get_contents($f);
 $body=AmfGateway::extractFirstMessageBodyRaw($raw);
 $r=new AmfByteReader($body);
 $v=Amf0::readValueDecode($r);
 echo "==== $f ====\n";
 echo json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n\n";
}
