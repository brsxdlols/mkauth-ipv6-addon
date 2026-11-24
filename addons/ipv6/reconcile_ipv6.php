<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Acesso negado');}
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__.'/ipv6-log.lib.php';
$db=new mysqli('127.0.0.1','root','vertrigo','mkradius');
if($db->connect_errno)exit(1);
try{ipv6LogClose($db);}catch(Throwable $e){fwrite(STDERR,"Falha ao conciliar encerramentos IPv6.\n");exit(1);}
