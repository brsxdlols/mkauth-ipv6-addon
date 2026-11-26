<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(0);
function failPatch($code) { echo json_encode(['ok'=>false,'error'=>$code]); exit(1); }
if (!class_exists('mysqli')) failPatch('mysqli_unavailable');
mysqli_report(MYSQLI_REPORT_OFF);
$c=json_decode(stream_get_contents(STDIN),true);
if (!is_array($c)) failPatch('invalid_input');
foreach (['server','login','password','radius_db','port','query'] as $key)
    if (!isset($c[$key]) || !is_string($c[$key])) failPatch('invalid_config');
if (strpos($c['query'],';')!==false || strpos($c['query'],'%{')!==false || strpos($c['query'],'${')!==false) failPatch('unresolved_query');
$host=$c['server']==='localhost'?'127.0.0.1':$c['server'];
$db=@new mysqli($host,$c['login'],$c['password'],$c['radius_db'],(int)$c['port']);
if ($db->connect_errno) failPatch('database_connection_failed');
$db->set_charset('utf8mb4');
$r=$db->query('EXPLAIN '.$c['query']);
if (!$r) failPatch('schema_or_query_invalid');
$r->free();$db->close();
echo json_encode(['ok'=>true,'schema_validated'=>true]);
