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

    $ratio = isset($o['ratio']) ? (int) $o['ratio'] : 32;
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
    $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', !empty($o['name']) ? $o['name'] : 'cgnat'));
    $name = trim($name, '-_');
    if ($name === '') $name = 'cgnat';
    $chain = substr('cgnat-'.$name, 0, 28);
    $comment = 'mkauth-ipv6-addon:'.$name;
    $wan = trim(isset($o['interface']) ? $o['interface'] : '');
    $protocols = (isset($o['protocol']) && $o['protocol'] === 'tcp') ? array('tcp') : array('tcp', 'udp');
    $action = (isset($o['type']) && $o['type'] === 'src-nat') ? 'src-nat' : 'netmap';
    $ignore = trim(isset($o['ignore']) ? $o['ignore'] : '');
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
    if (!empty($o['blackhole'])) $lines[] = '/ip route remove [find comment="'.$comment.'"];';
    $lines[] = '';
    if (!empty($o['blackhole'])) {
        if (isset($o['routeros']) && $o['routeros'] === '7') $lines[] = '/ip route add blackhole dst-address='.$public['cidr'].' distance=254 comment="'.$comment.'";';
        else $lines[] = '/ip route add dst-address='.$public['cidr'].' type=blackhole distance=254 comment="'.$comment.'";';
        $lines[] = '';
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
            if ($wan !== '') $line .= ' out-interface="'.$wan.'"';
            $line .= ' protocol='.$proto.' src-address='.$groupCidr.' to-addresses='.$public['cidr'].' to-ports='.$portStart.'-'.$portEnd.' comment="'.$comment.'";';
            $lines[] = $line;
        }
        if (!empty($o['nat_others'])) {
            $line = 'add action='.$action.' chain="'.$chain.'"';
            if ($wan !== '') $line .= ' out-interface="'.$wan.'"';
            $line .= ' src-address='.$groupCidr.' to-addresses='.$public['cidr'].' comment="'.$comment.'";';
            $lines[] = $line;
        }
    }

    return array(
        'script'=>implode("\n", $lines)."\n",
        'public_count'=>$public['size'], 'private_count'=>$privateCount,
        'private_cidr'=>$private['cidr'], 'clients_per_public'=>$ratio,
        'ports'=>$ports, 'first_port'=>$firstPort, 'last_port'=>$lastMappedPort,
        'ranges'=>$ranges, 'rules'=>count($lines)
    );
}
