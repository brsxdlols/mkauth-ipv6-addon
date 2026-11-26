<?php
/* Shared log storage. It never allocates addresses, changes bindings or PPP sessions. */
function ipv6LogValid($value, $prefixRequired = false)
{
    $p = explode('/', trim((string)$value));
    if (count($p)>2 || ($prefixRequired && count($p)!==2)) return false;
    if (!filter_var($p[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;
    return count($p)===1 || (preg_match('/^(0|[1-9][0-9]{0,2})$/D',$p[1]) && (int)$p[1]<=128);
}

function ipv6LogHistoryJoin()
{
    return " LEFT JOIN ipv6_history h ON h.id=COALESCE(
        (SELECT MAX(hp.id) FROM ipv6_history hp WHERE hp.radius_record_id=r.radacctid AND hp.delegated_prefix<>''),
        (SELECT MAX(ha.id) FROM ipv6_history ha WHERE ha.radius_record_id=r.radacctid),
        (SELECT MAX(hl.id) FROM ipv6_history hl WHERE hl.radius_record_id IS NULL AND hl.session_id=r.acctsessionid AND hl.username=r.username AND hl.framedipaddress=r.framedipaddress)
    ) ";
}

function ipv6LogFilterDate($value)
{
    if(!is_string($value)||$value==='')return '';
    foreach(['Y-m-d\TH:i','Y-m-d\TH:i:s','Y-m-d H:i:s'] as $format){
        $date=DateTime::createFromFormat('!'.$format,$value);$errors=DateTime::getLastErrors();
        if($date&&(!$errors||(!$errors['warning_count']&&!$errors['error_count'])))return $date->format('Y-m-d H:i:s');
    }
    return '';
}

function ipv6LogSchema($db)
{
    $columns=['radius_record_id'=>'bigint NULL','wan_ipv6'=>'varchar(150) NULL','delegated_prefix'=>'varchar(150) NULL','collector_source'=>'varchar(16) NULL','last_observed_at'=>'datetime NULL','collector_key'=>'char(64) NULL'];
    $r=$db->query('SHOW COLUMNS FROM ipv6_history'); $present=[];
    if(!$r) throw new RuntimeException('Instale o banco do addon antes de receber eventos.');
    while($row=$r->fetch_assoc())$present[$row['Field']]=true;
    foreach($columns as $name=>$definition)if(!isset($present[$name])&&!$db->query('ALTER TABLE ipv6_history ADD COLUMN `'.$name.'` '.$definition))throw new RuntimeException('Falha ao preparar metadados do log.');
    foreach(['idx_collector_key'=>'UNIQUE KEY idx_collector_key (collector_key)','idx_radius_record'=>'KEY idx_radius_record (radius_record_id)'] as $name=>$definition){
        $r=$db->query("SHOW INDEX FROM ipv6_history WHERE Key_name='$name'");
        if(!$r->num_rows&&!$db->query('ALTER TABLE ipv6_history ADD '.$definition))throw new RuntimeException('Falha ao preparar indice do log.');
    }
}

function ipv6LogRadius($db,$user,$ip,$nas='',$radiusId=0,$sessionId='')
{
    $s=$db->prepare("SELECT radacctid,acctsessionid,username,framedipaddress,callingstationid,framedipv6address,nasipaddress FROM radacct WHERE username=? AND framedipaddress=? AND acctstoptime IS NULL AND (?='' OR nasipaddress=?) AND (?=0 OR radacctid=?) AND (?='' OR LOWER(acctsessionid)=?) ORDER BY radacctid DESC LIMIT 2");
    if(!$s)throw new RuntimeException('Falha ao consultar sessao RADIUS.');
    $s->bind_param('ssssiiss',$user,$ip,$nas,$nas,$radiusId,$radiusId,$sessionId,$sessionId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($rows)>1)throw new RuntimeException('SESSAO_AMBIGUA');
    return $rows[0]??null;
}

function ipv6LogStore($db,array $radius,array $prefixes,$postedWan='')
{
    $prefixes=array_values(array_unique(array_filter($prefixes,'strlen')));
    foreach($prefixes as $prefix)if(!ipv6LogValid($prefix,true))throw new InvalidArgumentException('Prefixo IPv6 invalido.');
    $wan=ipv6LogValid($radius['framedipv6address']??'')?$radius['framedipv6address']:(ipv6LogValid($postedWan)?$postedWan:'');
    if(!$prefixes&&$wan==='')return 'SEM_IPV6';
    $id=(int)$radius['radacctid'];$session=$radius['acctsessionid'];$user=$radius['username'];$ip=$radius['framedipaddress'];$mac=$radius['callingstationid'];
    $db->begin_transaction();
    try{
        foreach($prefixes?:[''] as $pd){
            $value=$pd!==''?$pd:$wan;$key=hash('sha256','radius|'.$id.'|'.$pd.'|'.$wan);
            $s=$db->prepare('SELECT id FROM ipv6_history WHERE username=? AND session_id=? AND framedipaddress=? AND ipv6=? AND collector_key IS NULL AND ended_at IS NULL LIMIT 1 FOR UPDATE');
            $s->bind_param('ssss',$user,$session,$ip,$value);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();
            if($old){
                $historyId=(int)$old['id'];
                $s=$db->prepare("UPDATE ipv6_history SET radius_record_id=?,wan_ipv6=?,delegated_prefix=?,collector_source='fetch',last_observed_at=NOW(),collector_key=? WHERE id=?");
                $s->bind_param('isssi',$id,$wan,$pd,$key,$historyId);
            }else{
                $s=$db->prepare("INSERT INTO ipv6_history (username,ipv6,session_id,framedipaddress,callingstationid,radius_record_id,wan_ipv6,delegated_prefix,collector_source,last_observed_at,collector_key) VALUES (?,?,?,?,?,?,?,?,'fetch',NOW(),?) ON DUPLICATE KEY UPDATE last_observed_at=NOW(),wan_ipv6=VALUES(wan_ipv6)");
                $s->bind_param('sssssisss',$user,$value,$session,$ip,$mac,$id,$wan,$pd,$key);
            }
            if(!$s->execute())throw new RuntimeException('Falha ao gravar IPv6.');$s->close();
        }
        if($prefixes){
            $first=$prefixes[0];$s=$db->prepare('UPDATE radacct SET delegatedipv6prefix=?,ipv6_script=? WHERE radacctid=? AND username=? AND framedipaddress=?');
            $s->bind_param('ssiss',$first,$first,$id,$user,$ip);if(!$s->execute())throw new RuntimeException('Falha ao vincular PD a sessao.');$s->close();
        }
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    return $prefixes?'OK':'OK_WAN';
}

function ipv6LogClose($db,$user=null,$ip=null)
{
    $sql="UPDATE ipv6_history h JOIN radacct r ON r.radacctid=h.radius_record_id SET h.ended_at=r.acctstoptime WHERE h.ended_at IS NULL AND r.acctstoptime IS NOT NULL";
    if($user!==null){$sql.=" AND h.username=? AND (?='' OR h.framedipaddress=?)";$s=$db->prepare($sql);$s->bind_param('sss',$user,$ip,$ip);}
    else $s=$db->prepare($sql);
    if(!$s||!$s->execute())throw new RuntimeException('Falha ao conciliar encerramentos.');
    $count=$s->affected_rows;$s->close();return $count;
}
