<?php
header('Content-Type: text/plain; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__.'/ipv6-log.lib.php';
$db=new mysqli('127.0.0.1','root','vertrigo','mkradius');
if($db->connect_errno){http_response_code(503);exit('Banco indisponivel');}
$r=$db->query("SELECT name,value FROM ipv6_settings WHERE name IN ('api_token','allow_legacy_disconnect')");$settings=[];
if($r)while($row=$r->fetch_assoc())$settings[$row['name']]=$row['value'];
$token=$_GET['token']??'';$expected=(string)($settings['api_token']??'');
if(!is_string($token)||($token===''?($settings['allow_legacy_disconnect']??'1')!=='1':($expected===''||!hash_equals($expected,$token)))){http_response_code(403);exit('Token invalido');}
$user=$_GET['user']??'';$ip=$_GET['ip']??'';
if(!is_string($user)||!is_string($ip)||$user===''||strlen($user)>100||($ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))){http_response_code(400);exit('Dados invalidos');}
try{ipv6LogClose($db,$user,$ip);echo 'OK';}catch(Throwable $e){http_response_code(500);echo 'Falha ao registrar desconexao';}
