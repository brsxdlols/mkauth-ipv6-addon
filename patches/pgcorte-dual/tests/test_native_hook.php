<?php
define('MKAUTH_PGCORTE_DUAL_TEST', true);
require __DIR__.'/../native_hook.php';
$native="/ip firewall address-list\nadd list=pgcorte address=192.0.2.1 comment=api_corte_fixture\n";
$worker=file_get_contents(__DIR__.'/../pgcorte_dual.rsc');
$result=mkauthPgcorteDualTransform($native,200,$worker);
$cases=[
 'native_preserved'=>strpos($result,rtrim($native)."\n")===0,
 'dual_appended'=>substr_count($result,'# BEGIN MKAUTH_PGCORTE_DUAL')===1,
 'idempotent'=>mkauthPgcorteDualTransform($result,200,$worker)===$result,
 'unauthorized'=>mkauthPgcorteDualTransform($native,403,$worker)===$native,
 'failure'=>mkauthPgcorteDualTransform($native,500,$worker)===$native,
 'denied_body'=>mkauthPgcorteDualTransform('Acesso negado',200,$worker)==='Acesso negado',
 'login_page'=>mkauthPgcorteDualTransform('<html>'.$native.'</html>',200,$worker)==='<html>'.$native.'</html>',
 'missing_worker'=>mkauthPgcorteDualTransform($native,200,false)===$native,
 'ipv4_without_clients'=>strpos(mkauthPgcorteDualTransform('/ip firewall address-list remove [find comment~"api_corte_"]',200,$worker),'# BEGIN MKAUTH_PGCORTE_DUAL')!==false
];
foreach($cases as $name=>$ok)if(!$ok){fwrite(STDERR,"FAILED $name\n");exit(1);}
echo 'NATIVE_HOOK_'.count($cases)."_CASES_OK\n";
