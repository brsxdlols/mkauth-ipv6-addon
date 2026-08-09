<?php

function cgnatIpToInt($ip)
{
    $value = ip2long(trim($ip));
    return $value === false ? null : (int) sprintf('%u', $value);
}

function cgnatIntToIp($value)
{
    if ($value > 2147483647) $value -= 4294967296;
    return long2ip($value);
}

function cgnatNetwork($cidr)
{
    if (!preg_match('/^([^\/]+)\/(\d|[12]\d|3[0-2])$/', trim($cidr), $m)) return null;
    $ip = cgnatIpToInt($m[1]);
    if ($ip === null) return null;
    $prefix = (int) $m[2];
    $size = 2 ** (32 - $prefix);
    $network = intdiv($ip, $size) * $size;
    return array('input'=>$ip, 'ip'=>cgnatIntToIp($network), 'prefix'=>$prefix, 'size'=>$size, 'start'=>$network, 'end'=>$network+$size-1, 'cidr'=>cgnatIntToIp($network).'/'.$prefix);
}

function cgnatIsPowerOfTwo($value)
{
    return $value > 0 && (($value & ($value - 1)) === 0);
}

function cgnatLog2($value)
{
    $bits = 0;
    while ($value > 1) { $value = intdiv($value, 2); $bits++; }
    return $bits;
}

function cgnatGenerate($o)
{
    $public = cgnatNetwork(isset($o['public']) ? $o['public'] : '');
    if (!$public || $public['input'] !== $public['start']) throw new InvalidArgumentException('Informe um bloco publico CIDR valido e alinhado, por exemplo 45.228.151.112/29.');

    $portChoices = array(16128=>4, 8064=>8, 4032=>16, 2016=>32, 1008=>64, 504=>128, 252=>256);
    $requestedPorts = isset($o['ports_choice']) ? (int)$o['ports_choice'] : 0;
    $ratio = isset($portChoices[$requestedPorts]) ? $portChoices[$requestedPorts] : (isset($o['ratio']) ? (int) $o['ratio'] : 32);
    $allowed = array(4, 8, 16, 32, 64, 128, 256);
    if (!in_array($ratio, $allowed, true) || !cgnatIsPowerOfTwo($ratio)) throw new InvalidArgumentException('Escolha uma relacao valida de clientes por IP publico.');

    $privateStart = cgnatIpToInt(isset($o['private_start']) ? $o['private_start'] : '');
    if ($privateStart === null) throw new InvalidArgumentException('Informe o IP inicial privado, sem barra.');
    $privateCount = $public['size'] * $ratio;
    if ($privateCount > 4294967296 || $privateStart + $privateCount - 1 > 4294967295) throw new InvalidArgumentException('O bloco privado calculado ultrapassa o limite IPv4.');
    $privatePrefix = $public['prefix'] - cgnatLog2($ratio);
    if ($privatePrefix < 0) throw new InvalidArgumentException('O bloco publico e a relacao escolhida resultam em um bloco privado grande demais.');
    if (($privateStart % $privateCount) !== 0) {
        $suggested = intdiv($privateStart, $privateCount) * $privateCount;
        throw new InvalidArgumentException('O IP privado inicial nao esta alinhado ao bloco /'.$privatePrefix.' calculado. Use '.cgnatIntToIp($suggested).'.');
    }
    $sharedStart = cgnatIpToInt('100.64.0.0');
    $sharedEnd = cgnatIpToInt('100.127.255.255');
    if ($privateStart < $sharedStart || $privateStart + $privateCount - 1 > $sharedEnd) throw new InvalidArgumentException('O bloco privado calculado deve permanecer dentro da faixa CGNAT 100.64.0.0/10.');

    /* 1024-65535: 64.512 portas utilizaveis. Divisao inteira, sem sobreposicao. */
    $firstPort = 1024;
    $availablePorts = 65535 - $firstPort + 1;
    $ports = intdiv($availablePorts, $ratio);
    $lastMappedPort = $firstPort + ($ports * $ratio) - 1;

    $private = array(
        'cidr'=>cgnatIntToIp($privateStart).'/'.$privatePrefix,
        'start'=>$privateStart,
        'end'=>$privateStart+$privateCount-1,
        'size'=>$privateCount,
        'prefix'=>$privatePrefix
    );
    $name = strtoupper(preg_replace('/[^a-zA-Z0-9_-]+/', '-', !empty($o['name']) ? $o['name'] : '001'));
    $name = trim($name, '-_');
    if ($name === '') $name = '001';
    $chain = substr('CGNAT-'.$name, 0, 28);
    $comment = 'VPSCLOUD-'.$chain;
    $wan = trim(isset($o['interface']) ? $o['interface'] : '');
    $interfaceMode = (isset($o['interface_mode']) && $o['interface_mode'] === 'list') ? 'out-interface-list' : 'out-interface';
    $protocols = (isset($o['protocol']) && $o['protocol'] === 'tcp') ? array('tcp') : array('tcp', 'udp');
    $action = (isset($o['type']) && $o['type'] === 'src-nat') ? 'src-nat' : 'netmap';
    $ignore = trim(isset($o['exception_list']) ? $o['exception_list'] : (isset($o['ignore']) ? $o['ignore'] : ''));
    $ignoreMatch = '';
    if ($ignore !== '') $ignoreMatch = strpos($ignore, '/') !== false ? ' dst-address=!'.$ignore : ' dst-address-list=!"'.$ignore.'"';

    $lines = array(
        '######################################',
        '# SCRIPT CGNAT - MK-AUTH IPV6 ADDON',
        '# NOME: '.strtoupper($name),
        '# CONVERSAO: '.$private['cidr'].' > '.$public['cidr'],
        '# RELACAO: 1 PUBLICO PARA '.$ratio.' PRIVADOS',
        '# PORTAS: '.$ports.' POR CLIENTE ('.$firstPort.'-'.$lastMappedPort.')',
        '# GERACAO SEM SOBREPOSICAO DE PORTAS',
        '######################################', '',
        '/ip firewall nat remove [find comment="'.$comment.'"];'
    );
    if (!empty($o['fasttrack'])) $lines[] = '/ip firewall filter remove [find comment="'.$comment.'-fasttrack"];';
    if (!empty($o['blackhole'])) $lines[] = '/ip route remove [find comment="'.$comment.'"];';
    $lines[] = '';
    if (!empty($o['blackhole'])) {
        if (isset($o['routeros']) && $o['routeros'] === '7') $lines[] = '/ip route add blackhole dst-address='.$public['cidr'].' distance=254 comment="'.$comment.'";';
        else $lines[] = '/ip route add dst-address='.$public['cidr'].' type=blackhole distance=254 comment="'.$comment.'";';
        $lines[] = '';
    }
    if (!empty($o['fasttrack'])) {
        $lines[] = '/ip firewall filter;';
        $fast='add action=fasttrack-connection chain=forward connection-state=established,related';
        if (isset($o['routeros']) && $o['routeros'] === '7') $fast.=' hw-offload=yes';
        $lines[]=$fast.' comment="'.$comment.'-fasttrack";';
        $lines[]='add action=accept chain=forward connection-state=established,related comment="'.$comment.'-fasttrack";';
        $lines[]='';
    }
    $lines[] = '/ip firewall nat;';
    $lines[] = 'add action=jump chain=srcnat src-address='.$private['cidr'].$ignoreMatch.' jump-target="'.$chain.'" comment="'.$comment.'";';
    $lines[] = '';

    $ranges = array();
    for ($client=0; $client<$ratio; $client++) {
        $groupStart = $privateStart + ($client * $public['size']);
        $groupCidr = cgnatIntToIp($groupStart).'/'.$public['prefix'];
        $portStart = $firstPort + ($client * $ports);
        $portEnd = $portStart + $ports - 1;
        $ranges[] = array($portStart, $portEnd);
        foreach ($protocols as $proto) {
            $line = 'add action='.$action.' chain="'.$chain.'"';
            if ($wan !== '') $line .= ' '.$interfaceMode.'="'.$wan.'"';
            $line .= ' protocol='.$proto.' src-address='.$groupCidr.' to-addresses='.$public['cidr'].' to-ports='.$portStart.'-'.$portEnd.' comment="'.$comment.'";';
            $lines[] = $line;
        }
        if (!empty($o['nat_others'])) {
            $line = 'add action='.$action.' chain="'.$chain.'"';
            if ($wan !== '') $line .= ' '.$interfaceMode.'="'.$wan.'"';
            $line .= ' src-address='.$groupCidr.' to-addresses='.$public['cidr'].' comment="'.$comment.'";';
            $lines[] = $line;
        }
    }

    return array(
        'script'=>implode("\n", $lines)."\n",
        'public_count'=>$public['size'], 'public_cidr'=>$public['cidr'], 'public_start'=>$public['start'], 'private_count'=>$privateCount,
        'private_cidr'=>$private['cidr'], 'clients_per_public'=>$ratio,
        'ports'=>$ports, 'first_port'=>$firstPort, 'last_port'=>$lastMappedPort,
        'ranges'=>$ranges, 'rules'=>count($lines)
    );
}

function cgnatMappingRows($result)
{
    $rows = array();
    for ($i=0; $i<$result['private_count']; $i++) {
        $group = intdiv($i, $result['public_count']);
        $rows[] = array(
            'public_ip'=>cgnatIntToIp($result['public_start'] + ($i % $result['public_count'])),
            'port_start'=>$result['ranges'][$group][0],
            'port_end'=>$result['ranges'][$group][1],
            'private_ip'=>cgnatIntToIp(cgnatIpToInt(explode('/', $result['private_cidr'])[0]) + $i)
        );
    }
    return $rows;
}

function cgnatArchiveOpenPeriods($conn)
{
    $sql="INSERT IGNORE INTO cgnat_connection_archive (profile_id,period_id,radacctid,username,private_ip,public_ip,port_start,port_end,connection_start,connection_end,effective_start,effective_end)
        SELECT v.profile_id,v.id,r.radacctid,r.username,r.framedipaddress,m.public_ip,m.port_start,m.port_end,r.acctstarttime,r.acctstoptime,
        IF(r.acctstarttime>v.started_at,r.acctstarttime,v.started_at),NOW()
        FROM cgnat_profile_periods v
        INNER JOIN radacct r ON r.acctstarttime<NOW() AND (r.acctstoptime IS NULL OR r.acctstoptime>v.started_at)
        LEFT JOIN cgnat_mappings m ON m.profile_id=v.profile_id AND m.private_ip=r.framedipaddress
        WHERE v.ended_at IS NULL";
    if (!$conn->query($sql)) throw new RuntimeException('Falha ao arquivar conexoes do periodo CGNAT: '.$conn->error);
    if (!$conn->query("UPDATE cgnat_profile_periods SET ended_at=NOW() WHERE ended_at IS NULL")) throw new RuntimeException($conn->error);
}

function cgnatActivateProfile($conn, $profileId)
{
    $profileId = (int)$profileId;
    if ($profileId < 1) throw new InvalidArgumentException('Perfil CGNAT invalido.');
    $conn->begin_transaction();
    try {
        cgnatArchiveOpenPeriods($conn);
        $conn->query("UPDATE cgnat_profiles SET active=0 WHERE active=1");
        if (!$conn->query("UPDATE cgnat_profiles SET active=1 WHERE id=".$profileId) || $conn->affected_rows !== 1) throw new RuntimeException('Perfil CGNAT nao encontrado.');
        if (!$conn->query("INSERT INTO cgnat_profile_periods (profile_id,started_at) VALUES (".$profileId.",NOW())")) throw new RuntimeException($conn->error);
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback(); throw $e;
    }
}

function cgnatSaveProfile($conn, $result, $o, $activate=true)
{
    $rows = cgnatMappingRows($result);
    $name = trim(isset($o['name']) ? $o['name'] : '001');
    if ($name === '') $name = '001';
    $conn->begin_transaction();
    try {
        if ($activate) {
            cgnatArchiveOpenPeriods($conn);
            $conn->query("UPDATE cgnat_profiles SET active=0 WHERE active=1");
        }
        $activeValue=$activate?1:0;
        $stmt = $conn->prepare("INSERT INTO cgnat_profiles (name,private_cidr,public_cidr,clients_per_public,ports_per_client,first_port,last_port,source,active) VALUES (?,?,?,?,?,?,?,'generated',?)");
        $stmt->bind_param('sssiiiii', $name, $result['private_cidr'], $result['public_cidr'], $result['clients_per_public'], $result['ports'], $result['first_port'], $result['last_port'], $activeValue);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $profileId = $stmt->insert_id; $stmt->close();
        if ($activate && !$conn->query("INSERT INTO cgnat_profile_periods (profile_id,started_at) VALUES (".(int)$profileId.",NOW())")) throw new RuntimeException($conn->error);
        $map = $conn->prepare("INSERT INTO cgnat_mappings (profile_id,private_ip,public_ip,port_start,port_end) VALUES (?,?,?,?,?)");
        foreach ($rows as $row) {
            $map->bind_param('issii', $profileId, $row['private_ip'], $row['public_ip'], $row['port_start'], $row['port_end']);
            if (!$map->execute()) throw new RuntimeException($map->error);
        }
        $map->close(); $conn->commit();
        return $profileId;
    } catch (Exception $e) {
        $conn->rollback(); throw $e;
    }
}

function cgnatPdfText($text)
{
    $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string)$text);
    return str_replace(array('\\','(',')'), array('\\\\','\\(','\\)'), $text);
}

function cgnatBuildPdf($result, $o)
{
    $rows = cgnatMappingRows($result); $perPage = 38;
    $pages = array_chunk($rows, $perPage); $objects = array();
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $kids = array(); $next = 5;
    foreach ($pages as $pageIndex=>$pageRows) {
        $pageObj=$next++; $streamObj=$next++; $kids[]=$pageObj.' 0 R';
        $content="0.10 0.14 0.20 rg 0 806 595 36 re f\n";
        $content.="BT /F2 18 Tf 0.20 0.70 0.95 rg 32 816 Td (MAPEAMENTO DAS PORTAS) Tj ET\n";
        $subtitle='CGNAT-'.strtoupper(trim(!empty($o['name'])?$o['name']:'001')).' | '.$result['private_cidr'].' > '.$result['public_cidr'].' | '.date('d/m/Y H:i');
        $content.="BT /F1 8 Tf 0.25 0.30 0.38 rg 32 790 Td (".cgnatPdfText($subtitle).") Tj ET\n";
        $content.="0.12 0.16 0.22 rg 28 760 539 22 re f\n";
        $content.="BT /F2 9 Tf 1 1 1 rg 36 768 Td (IP PUBLICO) Tj 190 0 Td (RANGE DE PORTAS) Tj 190 0 Td (IP PRIVADO) Tj ET\n";
        $y=744;
        foreach($pageRows as $index=>$row){
            if($index%2===0)$content.="0.94 0.96 0.98 rg 28 ".($y-4)." 539 18 re f\n";
            $content.="BT /F1 8 Tf 0.08 0.12 0.18 rg 36 ".$y." Td (".cgnatPdfText($row['public_ip']).") Tj 190 0 Td (".$row['port_start'].' a '.$row['port_end'].") Tj 190 0 Td (".cgnatPdfText($row['private_ip']).") Tj ET\n";
            $y-=18;
        }
        $footer='Pagina '.($pageIndex+1).' de '.count($pages).' | '.$result['ports'].' portas por cliente | Gerado pelo MK-Auth IPv6 Addon';
        $content.="BT /F1 7 Tf 0.35 0.40 0.48 rg 32 24 Td (".cgnatPdfText($footer).") Tj ET\n";
        $objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamObj.' 0 R >>';
        $objects[$streamObj]='<< /Length '.strlen($content).' >>'."\nstream\n".$content."endstream";
    }
    $objects[2]='<< /Type /Pages /Count '.count($kids).' /Kids ['.implode(' ',$kids).'] >>';
    ksort($objects); $pdf="%PDF-1.4\n%CGNAT\n"; $offsets=array(0=>0);
    foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$body."\nendobj\n";}
    $xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',isset($offsets[$i])?$offsets[$i]:0)."\n";
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    return $pdf;
}

