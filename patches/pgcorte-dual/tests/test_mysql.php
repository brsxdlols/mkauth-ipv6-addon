<?php
if (PHP_SAPI!=='cli') exit(1);
error_reporting(0);mysqli_report(MYSQLI_REPORT_OFF);
$input=json_decode(stream_get_contents(STDIN),true);
if(!is_array($input))exit(1);
$host=$input['server']==='localhost'?'127.0.0.1':$input['server'];
$db=@new mysqli($host,$input['login'],$input['password'],'',(int)$input['port']);
if($db->connect_errno){echo "TEST_DATABASE_CONNECTION_FAILED\n";exit(1);}
$name='codex_pgcorte_patch_test_'.bin2hex(random_bytes(6));
if(!preg_match('/^codex_pgcorte_patch_test_[a-f0-9]{12}$/D',$name))exit(1);
function assertPatch($condition,$label){if(!$condition)throw new RuntimeException($label);}
$exitCode=0;
$created=false;
try{
 assertPatch($db->query("CREATE DATABASE `$name`"),'create_isolated_database');
 $created=true;
 $db->select_db($name);$db->set_charset('utf8mb4');
 assertPatch($db->query("CREATE TABLE sis_cliente(login varchar(100),ip varchar(50),bloqueado varchar(3),cli_ativado varchar(1),ramal varchar(50)) CHARACTER SET latin1 COLLATE latin1_swedish_ci"),'create_customer_fixture');
 assertPatch($db->query("CREATE TABLE radreply(id int AUTO_INCREMENT PRIMARY KEY,username varchar(100),attribute varchar(100),value varchar(150),op varchar(3)) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"),'create_reply_fixture');
 assertPatch($db->query("CREATE TABLE sis_adicional(id int AUTO_INCREMENT PRIMARY KEY,username varchar(100),login varchar(100),ip varchar(50),bloqueado varchar(3)) CHARACTER SET latin1 COLLATE latin1_swedish_ci"),'create_additional_fixture');
 $cases=[
 ['missing','172.16.11.22','sim','s',true,null,'172.16.11.22'],
 ['normal','172.16.11.23','nao','s',true,null,null],
 ['inactive','172.16.11.24','sim','n',true,null,null],
 ['no_list','172.16.11.25','sim','s',false,null,null],
 ['null_ip',null,'sim','s',true,null,null],
 ['zero','0.0.0.0','sim','s',true,null,null],
 ['bad','999.1.2.3','sim','s',true,null,null],
 ['short','127.1','sim','s',true,null,null],
 ['broadcast','255.255.255.255','sim','s',true,null,null],
 ['existing','172.16.11.26','sim','s',true,'192.0.2.77','192.0.2.77'],
 ['other_nas','100.64.1.41','sim','s',true,null,'100.64.1.41']
 ];
 foreach($cases as $case){
  [$login,$ip,$blocked,$active,$list,$already,$expected]=$case;
  $s=$db->prepare("INSERT INTO sis_cliente VALUES(?,?,?,?,'198.51.100.2')");$s->bind_param('ssss',$login,$ip,$blocked,$active);assertPatch($s->execute(),'insert_customer');
  $s=$db->prepare("INSERT INTO radreply(username,attribute,value,op) VALUES(?,'Mikrotik-Rate-Limit','10M/10M',':=')");$s->bind_param('s',$login);$s->execute();
  if($list){$s=$db->prepare("INSERT INTO radreply(username,attribute,value,op) VALUES(?,'Mikrotik-Address-List','pgcorte',':=')");$s->bind_param('s',$login);$s->execute();}
  if($already!==null){$s=$db->prepare("INSERT INTO radreply(username,attribute,value,op) VALUES(?,'Framed-IP-Address',?,':=')");$s->bind_param('ss',$login,$already);$s->execute();}
  $query=str_replace(['${authreply_table}','%{SQL-User-Name}'],['radreply',$db->real_escape_string($login)],$input['template']);
  $r=$db->query($query);assertPatch((bool)$r,'query_execution');$ips=[];$rate=false;$listFound=false;
  while($row=$r->fetch_assoc()){
   if($row['attribute']==='Framed-IP-Address')$ips[]=$row['value'];
   if($row['attribute']==='Mikrotik-Rate-Limit')$rate=true;
   if($row['attribute']==='Mikrotik-Address-List'&&$row['value']==='pgcorte')$listFound=true;
  }
  assertPatch($ips===($expected===null?[]:[$expected]),'correct_ip_'.$login);
  assertPatch($rate,'preserve_other_attributes_'.$login);
  assertPatch($listFound===$list,'preserve_address_list_'.$login);
 }
 $additionalCases=[
 ['extra_own','sim','nao','s','172.16.13.47',null,true,false,'172.16.13.47'],
 ['extra_parent','nao','sim','s','172.16.13.48',null,true,false,'172.16.13.48'],
 ['extra_normal','nao','nao','s','172.16.13.49',null,true,false,null],
 ['extra_inactive','sim','sim','n','172.16.13.50',null,true,false,null],
 ['extra_zero','sim','sim','s','0.0.0.0',null,true,false,null],
 ['extra_existing','sim','sim','s','172.16.13.51','192.0.2.77',true,false,'192.0.2.77'],
 ['extra_no_list','sim','sim','s','172.16.13.52',null,false,false,null],
 ['extra_ambiguous','sim','sim','s','172.16.13.53',null,true,true,null]
 ];
 foreach($additionalCases as $case){
  [$login,$own,$parentBlocked,$active,$ip,$already,$list,$duplicate,$expected]=$case;
  $parent='parent_'.$login;
  $s=$db->prepare("INSERT INTO sis_cliente VALUES(?,'192.0.2.10',?,?,'198.51.100.2')");$s->bind_param('sss',$parent,$parentBlocked,$active);assertPatch($s->execute(),'insert_parent');
  $s=$db->prepare("INSERT INTO sis_adicional(username,login,ip,bloqueado) VALUES(?,?,?,?)");$s->bind_param('ssss',$login,$parent,$ip,$own);assertPatch($s->execute(),'insert_additional');
  if($duplicate)assertPatch($s->execute(),'insert_duplicate');
  if($list){$s=$db->prepare("INSERT INTO radreply(username,attribute,value,op) VALUES(?,'Mikrotik-Address-List','pgcorte',':=')");$s->bind_param('s',$login);assertPatch($s->execute(),'insert_extra_list');}
  if($already!==null){$s=$db->prepare("INSERT INTO radreply(username,attribute,value,op) VALUES(?,'Framed-IP-Address',?,':=')");$s->bind_param('ss',$login,$already);assertPatch($s->execute(),'insert_extra_ip');}
  $query=str_replace(['${authreply_table}','%{SQL-User-Name}'],['radreply',$db->real_escape_string($login)],$input['template']);
  $r=$db->query($query);assertPatch((bool)$r,'extra_query_execution');$ips=[];
  while($row=$r->fetch_assoc())if($row['attribute']==='Framed-IP-Address')$ips[]=$row['value'];
  assertPatch($ips===($expected===null?[]:[$expected]),'correct_additional_ip_'.$login);
 }
 echo "MYSQL_SEMANTIC_TESTS_OK ".(count($cases)+count($additionalCases))."\n";
}catch(Throwable $e){echo "MYSQL_TEST_FAILED ".$e->getMessage()."\n";$exitCode=1;}
finally{if($created&&!$db->query("DROP DATABASE `$name`")){echo "MYSQL_TEST_CLEANUP_FAILED\n";$exitCode=1;}}
exit($exitCode);
