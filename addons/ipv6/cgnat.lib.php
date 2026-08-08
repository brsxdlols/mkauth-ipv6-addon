<?php

function cgnatIpToInt($ip)
{
    $value = ip2long($ip);
    return $value === false ? null : (int) sprintf('%u', $value);
}

function cgnatIntToIp($value)
{
    if ($value > 2147483647) {
        $value -= 4294967296;
    }
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
    return array('ip' => cgnatIntToIp($network), 'prefix' => $prefix, 'size' => $size, 'start' => $network, 'cidr' => cgnatIntToIp($network).'/'.$prefix);
}

function cgnatGenerate($o)
{
    $public = cgnatNetwork($o['public']);
    $private = cgnatNetwork($o['private']);
    if (!$public || !$private) throw new InvalidArgumentException('Informe os blocos publico e privado em CIDR.');
    $publicCount = $public['size'];
    $privateCount = $private['size'];
    if ($privateCount % $publicCount !== 0) throw new InvalidArgumentException('O bloco privado precisa ser divisivel pela quantidade de IPs publicos.');
    $clientsPerPublic = intdiv($privateCount, $publicCount);
    if (($clientsPerPublic & ($clientsPerPublic - 1)) !== 0) throw new InvalidArgumentException('Clientes por IP publico precisa resultar em potencia de 2.');
    $ports = (int) $o['ports'];
    if ($ports < 256 || $ports > 16000) throw new InvalidArgumentException('Use entre 256 e 16000 portas por cliente/grupo.');
    $firstPort = (int) $o['first_port'];
    if ($firstPort < 1 || $firstPort + ($clientsPerPublic * $ports) - 1 > 65535) throw new InvalidArgumentException('A faixa de portas ultrapassa 65535 para cada IP publico.');
    $groupPrefix = $public['prefix'];
    $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $o['name'] ?: 'cgnat'));
    $chain = substr('cgnat-'.$name, 0, 28);
    $comment = 'mkauth-ipv6-addon:'.$name;
    $wan = trim($o['interface']);
    $protocols = $o['protocol'] === 'tcp_udp' ? array('tcp', 'udp') : array('tcp');
    $lines = array(
        '######################################',
        '# SCRIPT CGNAT - MK-AUTH IPV6 ADDON',
        '# NOME: '.strtoupper($name),
        '# CONVERSAO: '.$private['cidr'].' > '.$public['cidr'],
        '# TIPO: '.strtoupper($o['type']),
        '# PORTAS: '.$ports.' POR CLIENTE/GRUPO',
        '######################################', '',
        '/ip firewall nat remove [find comment="'.$comment.'"];'
    );
    if (!empty($o['blackhole'])) $lines[] = '/ip route remove [find comment="'.$comment.'"];';
    $lines[] = '';
    if (!empty($o['blackhole'])) $lines[] = '/ip route add dst-address='.$public['cidr'].' type=blackhole distance=254 comment="'.$comment.'";';
    $lines[] = '/ip firewall nat;';
    $lines[] = 'add action=jump chain=srcnat src-address='.$private['cidr'].' jump-target="'.$chain.'" comment="'.$comment.'";';

    $chunkSize = min(64, $privateCount);
    $chunkPrefix = 32 - (int) round(log($chunkSize, 2));
    $chunkCount = (int) ceil($privateCount / $chunkSize);
    for ($c=0; $c<$chunkCount; $c++) {
        $chunkStart = $private['start'] + ($c * $chunkSize);
        $lines[] = 'add action=jump chain="'.$chain.'" src-address='.cgnatIntToIp($chunkStart).'/'.$chunkPrefix.' jump-target="'.$chain.'-c'.($c+1).'" comment="'.$comment.'";';
    }
    $lines[] = '';
    for ($client=0; $client<$clientsPerPublic; $client++) {
            $privateStart = $private['start'] + ($client * $publicCount);
            $privateCidr = cgnatIntToIp($privateStart).'/'.$groupPrefix;
            $publicTarget = $public['cidr'];
            $chunk = intdiv(($privateStart - $private['start']), $chunkSize) + 1;
            $portStart = $firstPort + ($client * $ports);
            $portEnd = $portStart + $ports - 1;
            foreach ($protocols as $proto) {
                $line = 'add action='.$o['type'].' chain="'.$chain.'-c'.$chunk.'"';
                if ($wan !== '') $line .= ' out-interface="'.$wan.'"';
                $line .= ' protocol='.$proto.' src-address='.$privateCidr.' to-addresses='.$publicTarget.' to-ports='.$portStart.'-'.$portEnd.' comment="'.$comment.'";';
                $lines[] = $line;
            }
            if (!empty($o['nat_others'])) {
                $line = 'add action='.$o['type'].' chain="'.$chain.'-c'.$chunk.'"';
                if ($wan !== '') $line .= ' out-interface="'.$wan.'"';
                $line .= ' src-address='.$privateCidr.' to-addresses='.$publicTarget.' comment="'.$comment.'";';
                $lines[] = $line;
            }
    }
    return array('script' => implode("\n", $lines)."\n", 'public_count'=>$publicCount, 'private_count'=>$privateCount, 'clients_per_public'=>$clientsPerPublic, 'ports'=>$ports, 'rules'=>count($lines));
}
