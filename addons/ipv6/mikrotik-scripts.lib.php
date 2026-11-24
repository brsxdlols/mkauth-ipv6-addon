<?php
function ipv6RosQuote($value)
{
    return '"'.strtr((string)$value, ['\\'=>'\\\\','"'=>'\\"','$'=>'\\$',"\r"=>'\\r',"\n"=>'\\n']).'"';
}

function ipv6FetchScripts($base,$token)
{
    $encoder=<<<'ROS'
    :local encode do={
        :local value [:tostr $1]
        :local out ""
        :for i from=0 to=([:len $value]-1) do={
            :local c [:pick $value $i ($i+1)]
            :if ($c="%") do={:set c "%25"} else={
            :if ($c="&") do={:set c "%26"} else={
            :if ($c="+") do={:set c "%2B"} else={
            :if ($c="=") do={:set c "%3D"} else={
            :if ($c=" ") do={:set c "%20"} else={
            :if ($c="\r") do={:set c "%0D"} else={
            :if ($c="\n") do={:set c "%0A"} else={
            :if ($c="\t") do={:set c "%09"}
            }}}}}}}
            :set out ($out.$c)
        }
        :return $out
    }
ROS;
    $context=<<<'ROS'
    :local quote do={
        :local value [:tostr $1]
        :local out "\""
        :for i from=0 to=([:len $value]-1) do={
            :local c [:pick $value $i ($i+1)]
            :if (($c="\\") || ($c="\"") || ($c="\$")) do={:set c ("\\".$c)}
            :if ($c="\r") do={:set c "\\r"}
            :if ($c="\n") do={:set c "\\n"}
            :set out ($out.$c)
        }
        :return ($out."\"")
    }
    :local context (":local pppUser ".[$quote [:tostr $user]]."; :local ipv4 ".[$quote [:tostr $"remote-address"]]."; :local ifaceKey ".[$quote [:tostr $interface]]."; ")
ROS;
    $worker=<<<'ROS'
{
    :local url __URL__
    :local token __TOKEN__
__ENCODER__
    :local lastSent ""
    :local radiusId ""
    :local hasWanRecord false
    :local pdSeen false
    :local requestFailed false
    :local confirmed false
    :local ended false
    :local attempt 0
    :while (($attempt<12) && (!$confirmed) && (!$ended)) do={
        :set attempt ($attempt+1)
        :if ($attempt=1) do={:delay 2s} else={:delay 5s}
        :local ifaceName ""
        :do {:set ifaceName [/interface get $ifaceKey name]} on-error={:return}
        :if ($ifaceName="") do={:return}
        :local prefixes ""
        :foreach srvId in=[/ipv6 dhcp-server find where interface=$ifaceName] do={
            :local srvName [/ipv6 dhcp-server get $srvId name]
            :foreach b in=[/ipv6 dhcp-server binding find where server=$srvName] do={
                :if ([:tostr [/ipv6 dhcp-server binding get $b status]]="bound") do={
                    :local delegated [:tostr [/ipv6 dhcp-server binding get $b address]]
                    :local slash [:find $delegated "/"]
                    :if ([:typeof $slash]="num") do={
                        :local bits [:pick $delegated ($slash+1) [:len $delegated]]
                        :local n [:tonum $bits]
                        :if (([:typeof $n]="num") && ($n>=0) && ($n<=128) && ([:tostr $n]=$bits)) do={
                            :if ([:typeof [:find (",".$prefixes.",") (",".$delegated.",")]]!="num") do={
                                :if ($prefixes="") do={:set prefixes $delegated} else={:set prefixes ($prefixes.",".$delegated)}
                            }
                        }
                    }
                }
            }
        }
        :local signature $prefixes
        :if ($prefixes!="") do={:set pdSeen true}
        :if ($signature="") do={:set signature "WAN_ONLY"}
        :if ($signature!=$lastSent) do={
            :local data ("v=2&user=".[$encode $pppUser]."&ip=".[$encode $ipv4]."&prefixes=".[$encode $prefixes]."&radius_id=".$radiusId."&token=".[$encode $token])
            :do {
                :local result [/tool fetch url=$url http-method=post http-header-field="Content-Type: application/x-www-form-urlencoded" http-data=$data output=user as-value idle-timeout=5s]
                :local reply ($result->"data")
                :local split [:find $reply "|"]
                :if ([:typeof $split]="num") do={
                    :local id [:pick $reply ($split+1) [:len $reply]]
                    :if ([:typeof [:tonum $id]]="num") do={:set radiusId $id}
                    :set reply [:pick $reply 0 $split]
                }
                :if ($reply="SESSION_ENDED") do={:set ended true}
                :if ($reply="OK") do={:set confirmed true;:set lastSent $signature;:log warning ("IPv6 API: registro confirmado no MK-Auth; usuario=".$pppUser."; PD/LAN=".$prefixes)}
                :if ($reply="OK_WAN") do={:set hasWanRecord true;:set lastSent $signature}
                :if (($reply="SEM_IPV6") && ($radiusId!="")) do={:set lastSent $signature}
                :if (($reply!="OK_WAN") && ($reply!="RADIUS_PENDING") && ($reply!="SEM_IPV6")) do={:set requestFailed true}
            } on-error={:set requestFailed true}
        }
    }
    :if ((!$confirmed) && (!$ended)) do={
    :if ($pdSeen && $requestFailed) do={:log error ("IPv6 Log: PD identificado, mas envio Fetch sem confirmacao: ".$pppUser)} else={
    :if ($hasWanRecord) do={
        :log warning ("IPv6 Log: WAN registrada pelo RADIUS; PD/LAN nao observado nas tentativas de coleta: ".$pppUser)
    } else={
        :if ($requestFailed) do={:log error ("IPv6 Log: envio Fetch sem confirmacao; verificar resposta da requisicao: ".$pppUser)} else={:log warning ("IPv6 Log: aguardando sessao RADIUS ou dados IPv6; coleta nao confirmada: ".$pppUser)}
    }
    }
    }
}
ROS;
    $worker=str_replace(['__URL__','__TOKEN__','__ENCODER__'],[ipv6RosQuote($base.'/update_ipv6.php'),ipv6RosQuote($token),$encoder],$worker);
    $down=<<<'ROS'
{
    :local url __URL__
    :local token __TOKEN__
__ENCODER__
    :do {
        :local result [/tool fetch url=($url."?user=".[$encode $pppUser]."&ip=".[$encode $ipv4]."&token=".[$encode $token]) http-method=get output=user as-value idle-timeout=5s]
        :if (($result->"data")!="OK") do={:log error ("IPv6 Log: resposta inesperada ao conciliar desconexao: ".$pppUser)}
    } on-error={:log error ("IPv6 Log: Fetch de desconexao falhou; conciliacao RADIUS permanece agendada: ".$pppUser)}
}
ROS;
    $down=str_replace(['__URL__','__TOKEN__','__ENCODER__'],[ipv6RosQuote($base.'/disconnect.php'),ipv6RosQuote($token),$encoder],$down);
    return ["{\n".$context."\n    :execute script=(\$context.".ipv6RosQuote($worker).")\n}","{\n    :local pppUser [:tostr \$user]\n    :if (\$pppUser=\"\") do={:return}\n".$context."\n    # IPV6_LOG_OPTIONAL_CLEANUP\n    :execute script=(\$context.".ipv6RosQuote($down).")\n}"];
}
