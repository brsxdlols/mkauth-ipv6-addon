<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Acesso negado');}
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__.'/ipv6-log.lib.php';
$db=new mysqli('127.0.0.1','root','vertrigo','mkradius');
ipv6LogSchema($db);
if(function_exists('posix_geteuid')&&posix_geteuid()===0){
    $line="* * * * * www-data ".escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/reconcile_ipv6.php')." >/dev/null 2>&1\n";
    if(file_put_contents('/etc/cron.d/mkauth-ipv6-reconcile',$line)===false)throw new RuntimeException('Falha ao agendar conciliacao RADIUS.');
    chmod('/etc/cron.d/mkauth-ipv6-reconcile',0644);
}
echo "Log IPv6 preparado; nenhuma sessao PPP foi alterada.\n";
