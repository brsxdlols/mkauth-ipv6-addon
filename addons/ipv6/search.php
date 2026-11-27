<?php
require_once __DIR__.'/bootstrap.lib.php';
require_once __DIR__.'/ipv6-log.lib.php';
header('Content-Type: application/json; charset=utf-8');
ipv6StartMkAuthSession();
if(!ipv6HasMkAuthSession()){http_response_code(403);echo json_encode(['error'=>'Acesso negado']);exit;}
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius');$conn->set_charset('utf8mb4');
foreach(['q','status','public_ip','public_port','inicio','fim'] as $name)if(isset($_GET[$name])&&!is_string($_GET[$name])){http_response_code(400);echo json_encode(['error'=>'Filtro invalido']);exit;}
$q=trim($_GET['q']??'');$status=$_GET['status']??'';$limit=(int)($_GET['limit']??30);
if(strlen($q)>200){http_response_code(400);echo json_encode(['error'=>'Filtro muito longo']);exit;}
if(!in_array($limit,[30,50,100],true))$limit=30;
$wan = ipv6LogWanColumn($conn);
$where="r.username NOT REGEXP '^[0-9A-F:]{17}$'";$types='';$params=[];
if($q!==''){
    $where.=" AND (r.username LIKE ? OR r.framedipaddress LIKE ? OR h.ipv6 LIKE ? OR {$wan} LIKE ? OR r.delegatedipv6prefix LIKE ? OR h.callingstationid LIKE ? OR cm.public_ip LIKE ?".(ctype_digit($q)?' OR '.((int)$q).' BETWEEN cm.port_start AND cm.port_end':'').')';
    $types='sssssss';$params=array_fill(0,7,'%'.$q.'%');
}
$publicIp=trim($_GET['public_ip']??'');$publicPort=trim($_GET['public_port']??'');
if(filter_var($publicIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){$where.=' AND cm.public_ip=?';$types.='s';$params[]=$publicIp;}
if(ctype_digit($publicPort)&&(int)$publicPort>=1&&(int)$publicPort<=65535){$where.=' AND ? BETWEEN cm.port_start AND cm.port_end';$types.='i';$params[]=(int)$publicPort;}
if($status==='online')$where.=' AND r.acctstoptime IS NULL';
if($status==='offline')$where.=' AND r.acctstoptime IS NOT NULL';
$start=ipv6LogFilterDate($_GET['inicio']??'');$end=ipv6LogFilterDate($_GET['fim']??'');
if($start!==''&&$end!==''){$where.=' AND r.acctstarttime BETWEEN ? AND ?';$types.='ss';$params[]=$start;$params[]=$end;}
$wan = ipv6LogWanColumn($conn);
$sql="SELECT r.username,r.framedipaddress,r.acctstarttime,r.acctstoptime,r.nasipaddress,{$wan} AS wan_native,r.delegatedipv6prefix AS pd_native,h.ipv6,h.wan_ipv6 AS wan_observed,h.delegated_prefix AS pd_observed,h.radius_record_id,h.created_at AS ipv6_observed_at,h.callingstationid,cm.public_ip,cm.port_start,cm.port_end FROM radacct r ".ipv6LogHistoryJoin()." LEFT JOIN cgnat_mappings cm ON cm.private_ip=r.framedipaddress AND cm.profile_id=(CASE WHEN r.acctstoptime IS NULL THEN (SELECT p.id FROM cgnat_profiles p WHERE p.active=1 AND p.deleted_at IS NULL AND EXISTS (SELECT 1 FROM cgnat_mappings mx WHERE mx.profile_id=p.id AND mx.private_ip=r.framedipaddress) ORDER BY p.id DESC LIMIT 1) ELSE (SELECT v.profile_id FROM cgnat_profile_periods v WHERE v.superseded_at IS NULL AND v.started_at<r.acctstoptime AND (v.ended_at IS NULL OR v.ended_at>r.acctstarttime) AND EXISTS (SELECT 1 FROM cgnat_mappings mx WHERE mx.profile_id=v.profile_id AND mx.private_ip=r.framedipaddress) ORDER BY v.started_at DESC LIMIT 1) END) WHERE $where ORDER BY r.radacctid DESC LIMIT $limit";
$stmt=$conn->prepare($sql);
if(!$stmt){http_response_code(500);echo json_encode(['error'=>'Nao foi possivel consultar o log IPv6']);exit;}
if($types!==''){$refs=[$types];foreach($params as $k=>$v)$refs[]=&$params[$k];call_user_func_array([$stmt,'bind_param'],$refs);}
if(!$stmt->execute()){http_response_code(500);echo json_encode(['error'=>'Falha na consulta do log']);exit;}
$res=$stmt->get_result();$rows=[];
while($r=$res->fetch_assoc()){
    $r['wan_ipv6']=$r['wan_observed']?:$r['wan_native'];
    $r['pd_ipv6']=$r['radius_record_id']?$r['pd_observed']:($r['pd_native']?:$r['ipv6']);
    $r['ipv6']=$r['pd_ipv6']?:$r['wan_ipv6'];
    $seconds=$r['acctstarttime']?max(0,strtotime($r['acctstoptime']?:date('Y-m-d H:i:s'))-strtotime($r['acctstarttime'])):0;
    $r['duration']=sprintf('%02d:%02d:%02d',floor($seconds/3600),floor(($seconds%3600)/60),$seconds%60);
    $r['online']=empty($r['acctstoptime']);$rows[]=$r;
}
echo json_encode(['rows'=>$rows,'count'=>count($rows)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
