<?php
require_once __DIR__ . '/bootstrap.lib.php';
require_once __DIR__ . '/migrations.lib.php';
header('Content-Type: application/json; charset=utf-8');
ipv6StartMkAuthSession();
if (!ipv6HasMkAuthSession()) { http_response_code(403); echo json_encode(array('error'=>'Acesso negado')); exit; }
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius');
ipv6RunMigrations($conn);
$q=trim($_GET['q']??''); $status=$_GET['status']??''; $limit=(int)($_GET['limit']??30);
if (!in_array($limit,array(30,50,100),true)) $limit=30;
$where="r.username NOT REGEXP '^[0-9A-F:]{17}$'"; $types=''; $params=array();
if ($q!=='') { $where.=" AND (r.username LIKE ? OR r.framedipaddress LIKE ? OR h.ipv6 LIKE ? OR h.callingstationid LIKE ? OR cm.public_ip LIKE ?".(ctype_digit($q)?" OR ".((int)$q)." BETWEEN cm.port_start AND cm.port_end":"").")"; $like='%'.$q.'%'; $types='sssss'; $params=array($like,$like,$like,$like,$like); }
if ($status==='online') $where.=' AND r.acctstoptime IS NULL';
if ($status==='offline') $where.=' AND r.acctstoptime IS NOT NULL';
$sql="SELECT r.username,r.framedipaddress,r.acctstarttime,r.acctstoptime,h.ipv6,h.callingstationid,cm.public_ip,cm.port_start,cm.port_end FROM radacct r LEFT JOIN (SELECT h1.* FROM ipv6_history h1 INNER JOIN (SELECT session_id,MAX(id) max_id FROM ipv6_history GROUP BY session_id) h2 ON h1.id=h2.max_id) h ON h.session_id=r.acctsessionid LEFT JOIN cgnat_mappings cm ON cm.private_ip=r.framedipaddress AND cm.profile_id=(SELECT v.profile_id FROM cgnat_profile_periods v WHERE v.started_at<=COALESCE(r.acctstarttime,NOW()) AND (v.ended_at IS NULL OR v.ended_at>COALESCE(r.acctstarttime,NOW())) ORDER BY v.started_at DESC LIMIT 1) WHERE $where ORDER BY r.radacctid DESC LIMIT $limit";
$stmt=$conn->prepare($sql); if (!$stmt) { http_response_code(500); echo json_encode(array('error'=>$conn->error)); exit; }
if ($types!=='') $stmt->bind_param('sssss',$like,$like,$like,$like,$like); $stmt->execute(); $res=$stmt->get_result(); $rows=array();
while($r=$res->fetch_assoc()){ $end=$r['acctstoptime']; $start=$r['acctstarttime']; $seconds=$start?max(0,strtotime($end?:date('Y-m-d H:i:s'))-strtotime($start)):0; $r['duration']=sprintf('%02d:%02d:%02d',floor($seconds/3600),floor(($seconds%3600)/60),$seconds%60); $r['online']=empty($end); $rows[]=$r; }
echo json_encode(array('rows'=>$rows,'count'=>count($rows)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
