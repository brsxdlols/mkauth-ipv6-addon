{
    # Comentarios locais. Nao cria nem remove entradas; RADIUS controla o bloqueio.
    :if (([:len [/system script job find where script="ler_pgcorte_dual"]]+[:len [/system script job find where script="ler_pgcorte"]])>1) do={:return 0}
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
    :do {
        :local identifyV6 [:parse "{\n    :local prefixUsers [:toarray \"\"]\n    :local interfaceUsers [:toarray \"\"]\n    :local serverUsers [:toarray \"\"]\n    :local updated 0\n    :foreach iface in=[/interface pppoe-server print as-value] do={\n        :local ifaceName [:tostr (\$iface->\"name\")]\n        :local login [:tostr (\$iface->\"user\")]\n        :set (\$interfaceUsers->\$ifaceName) \$login\n    }\n    :foreach used in=[/ipv6 pool used print as-value] do={\n        :local targetPrefix [:tostr (\$used->\"prefix\")]\n        :local login [:tostr (\$used->\"info\")]\n        :if ((\$users->\$login)=true) do={\n            :if ([:typeof (\$prefixUsers->\$targetPrefix)]=\"nothing\") do={\n                :set (\$prefixUsers->\$targetPrefix) \$login\n            } else={ :if ((\$prefixUsers->\$targetPrefix)!=\$login) do={ :set (\$prefixUsers->\$targetPrefix) \"\" } }\n        }\n    }\n    :foreach srv in=[/ipv6 dhcp-server print as-value] do={\n        :local serverName [:tostr (\$srv->\"name\")]\n        :local ifaceName [:tostr (\$srv->\"interface\")]\n        :set (\$serverUsers->\$serverName) (\$interfaceUsers->\$ifaceName)\n    }\n    :foreach binding in=[/ipv6 dhcp-server binding print as-value where status=\"bound\"] do={\n        :local targetPrefix [:tostr (\$binding->\"address\")]\n        :local serverName [:tostr (\$binding->\"server\")]\n        :local login (\$serverUsers->\$serverName)\n        :if (([:len \$login]>0) && ((\$users->\$login)=true)) do={\n            :if ([:typeof (\$prefixUsers->\$targetPrefix)]=\"nothing\") do={\n                :set (\$prefixUsers->\$targetPrefix) \$login\n            } else={ :if ((\$prefixUsers->\$targetPrefix)!=\$login) do={ :set (\$prefixUsers->\$targetPrefix) \"\" } }\n        }\n    }\n    :foreach entry in=[/ipv6 firewall address-list find where list=\"pgcorte\"] do={\n        :do {\n            :local targetPrefix [:tostr [/ipv6 firewall address-list get \$entry address]]\n            :local login (\$prefixUsers->\$targetPrefix)\n            :local label [:tostr [/ipv6 firewall address-list get \$entry comment]]\n            :if (([:len \$login]>0) && ((\$label=\"\") || ([:pick \$label 0 10]=\"api_corte_\"))) do={\n                :local expected (\"api_corte_\".\$login)\n                :if (\$label!=\$expected) do={ /ipv6 firewall address-list set \$entry comment=\$expected; :set updated (\$updated+1) }\n            }\n        } on-error={}\n    }\n    :return \$updated\n}"]
        :set updated ($updated+[$identifyV6 users=$users])
    } on-error={}
    :put ("PGCORTE_DUAL_COMMENTS_UPDATED=".$updated)
}
