<?php
header('Content-Type: text/plain; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__.'/ipv6-log.lib.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(403);exit('Acesso negado');}
foreach(['token','user','ip','ipv6','prefix','prefixes','nas','v','radius_id'] as $field)if(isset($_POST[$field])&&!is_string($_POST[$field])){http_response_code(400);exit('Dados invalidos');}
$db=new mysqli('127.0.0.1','root','vertrigo','mkradius');
if($db->connect_errno){http_response_code(503);exit('Banco indisponivel');}
$db->set_charset('utf8mb4');
$r=$db->query("SELECT value FROM ipv6_settings WHERE name='api_token'");$setting=$r?$r->fetch_assoc():null;$expected=(string)($setting['value']??'');
if($expected===''||!hash_equals($expected,(string)($_POST['token']??''))){http_response_code(403);exit('Token invalido');}
$user=trim($_POST['user']??'');$ip=trim($_POST['ip']??'');$nas=trim($_POST['nas']??'');
$radiusId=0;
if(($_POST['radius_id']??'')!==''){
    $radiusId=filter_var($_POST['radius_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($radiusId===false){http_response_code(400);exit('Sessao invalida');}
}
if($user===''||strlen($user)>100||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||($nas!==''&&!filter_var($nas,FILTER_VALIDATE_IP))){http_response_code(400);exit('Dados invalidos');}
$raw=trim($_POST['prefixes']??($_POST['prefix']??''));
if(strlen($raw)>8192){http_response_code(400);exit('Lista de prefixos excede o limite');}
$prefixes=$raw===''?[]:array_map('trim',explode(',',$raw));
if(count($prefixes)>32){http_response_code(400);exit('Muitos prefixos');}
foreach($prefixes as $prefix)if(!ipv6LogValid($prefix,true)){http_response_code(400);exit('Prefixo IPv6 invalido');}
try{
    $radius=ipv6LogRadius($db,$user,$ip,$nas,$radiusId);
    if(!$radius)exit(($_POST['v']??'')==='2'?($radiusId?'SESSION_ENDED':'RADIUS_PENDING'):'SEM_IPV6');
    $reply=ipv6LogStore($db,$radius,$prefixes,trim($_POST['ipv6']??''));
    if(($_POST['v']??'')==='2')echo $reply.'|'.$radius['radacctid'];
    else echo $reply==='OK_WAN'?'SEM_IPV6':$reply;
}catch(Throwable $e){
    http_response_code($e->getMessage()==='SESSAO_AMBIGUA'?409:500);
    echo $e->getMessage()==='SESSAO_AMBIGUA'?'Sessao ambigua':'Falha ao registrar IPv6';
}
