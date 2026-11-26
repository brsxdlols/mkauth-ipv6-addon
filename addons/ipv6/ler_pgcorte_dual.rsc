{
    # Comentarios locais. Nao cria nem remove entradas; RADIUS controla o bloqueio.
    :if ([:len [/system script job find where script=[:jobname]]]>1) do={:return 0}
    :local users [:toarray ""]
    :local ipv4Users [:toarray ""]
    :local prefixUsers [:toarray ""]
    :local interfaceUsers [:toarray ""]
    :local serverUsers [:toarray ""]
    :local updated 0
    :foreach session in=[/ppp active print as-value] do={
        :local login [:tostr ($session->"name")]
        :local clientIp [:tostr ($session->"address")]
        :set ($users->$login) true
        :if ([:typeof ($ipv4Users->$clientIp)]="nothing") do={
            :set ($ipv4Users->$clientIp) $login
        } else={ :set ($ipv4Users->$clientIp) "" }
    }
    :foreach iface in=[/interface pppoe-server print as-value] do={
        :local ifaceName [:tostr ($iface->"name")]
        :local login [:tostr ($iface->"user")]
        :set ($interfaceUsers->$ifaceName) $login
    }
    :foreach used in=[/ipv6 pool used print as-value] do={
        :local targetPrefix [:tostr ($used->"prefix")]
        :local login [:tostr ($used->"info")]
        :if (($users->$login)=true) do={
            :if ([:typeof ($prefixUsers->$targetPrefix)]="nothing") do={
                :set ($prefixUsers->$targetPrefix) $login
            } else={ :if (($prefixUsers->$targetPrefix)!=$login) do={ :set ($prefixUsers->$targetPrefix) "" } }
        }
    }
    :foreach srv in=[/ipv6 dhcp-server print as-value] do={
        :local serverName [:tostr ($srv->"name")]
        :local ifaceName [:tostr ($srv->"interface")]
        :set ($serverUsers->$serverName) ($interfaceUsers->$ifaceName)
    }
    :foreach binding in=[/ipv6 dhcp-server binding print as-value where status="bound"] do={
        :local targetPrefix [:tostr ($binding->"address")]
        :local serverName [:tostr ($binding->"server")]
        :local login ($serverUsers->$serverName)
        :if (([:len $login]>0) && (($users->$login)=true)) do={
            :if ([:typeof ($prefixUsers->$targetPrefix)]="nothing") do={
                :set ($prefixUsers->$targetPrefix) $login
            } else={ :if (($prefixUsers->$targetPrefix)!=$login) do={ :set ($prefixUsers->$targetPrefix) "" } }
        }
    }
    :foreach entry in=[/ip firewall address-list find where list="pgcorte"] do={
        :do {
            :local clientIp [:tostr [/ip firewall address-list get $entry address]]
            :local login ($ipv4Users->$clientIp)
            :local label [:tostr [/ip firewall address-list get $entry comment]]
            :if (([:len $login]>0) && (($label="") || ([:pick $label 0 10]="api_corte_"))) do={
                :local expected ("api_corte_".$login)
                :if ($label!=$expected) do={ /ip firewall address-list set $entry comment=$expected; :set updated ($updated+1) }
            }
        } on-error={}
    }
    :foreach entry in=[/ipv6 firewall address-list find where list="pgcorte"] do={
        :do {
            :local targetPrefix [:tostr [/ipv6 firewall address-list get $entry address]]
            :local login ($prefixUsers->$targetPrefix)
            :local label [:tostr [/ipv6 firewall address-list get $entry comment]]
            :if (([:len $login]>0) && (($label="") || ([:pick $label 0 10]="api_corte_"))) do={
                :local expected ("api_corte_".$login)
                :if ($label!=$expected) do={ /ipv6 firewall address-list set $entry comment=$expected; :set updated ($updated+1) }
            }
        } on-error={}
    }
    :put ("PGCORTE_DUAL_COMMENTS_UPDATED=".$updated)
}
