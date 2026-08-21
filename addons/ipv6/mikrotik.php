<?php
require_once __DIR__.'/bootstrap.lib.php';
require_once __DIR__.'/migrations.lib.php';
require_once __DIR__.'/retention.lib.php';
ipv6RequireMkAuthLogin();
$Manifest=ipv6LoadAddonManifest();
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius'); ipv6RunMigrations($conn);
$manifestTitle=$Manifest->{'name'}??'Painel IPv4 e IPv6';
$manifestVersion=$Manifest->{'version'}??'1.0';
$token=ipv6GetSetting($conn,'api_token','123456');
$version=(($_GET['version']??'7')==='6')?'6':'7';
$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$base=$scheme.'://'.($_SERVER['HTTP_HOST']??'seu-dominio.com.br').'/admin/addons/ipv6';
$onUp='{
    :local url "'.$base.'/update_ipv6.php"
    :local token "'.$token.'"
    :local pppUser [:tostr $user]
    :local ifaceId $interface
    :local ifaceName [/interface get $ifaceId name]
    :local ipv4 [:tostr $"remote-address"]
    :local ipv6 ""
    :local prefix ""
    :local tentativas 0
    :while ($tentativas < 8) do={
        :delay 1500ms
        :foreach i in=[/ipv6 address find where interface=$ifaceId] do={
            :local addr [:tostr [/ipv6 address get $i address]]
            :if (([:len $addr] > 0) && ([:pick $addr 0 4] != "fe80") && ([:pick $addr 0 4] != "fc00") && ([:pick $addr 0 2] != "fd")) do={ :set ipv6 $addr }
        }
        # Primeiro le o PD /64 entregue pelo DHCPv6 ao usuario.
        :foreach b in=[/ipv6 dhcp-server binding find] do={
            :local srv [:tostr [/ipv6 dhcp-server binding get $b server]]
            :local delegated [:tostr [/ipv6 dhcp-server binding get $b address]]
            :if (([:find $srv $pppUser] != nil) && ([:find $delegated "/64"] != nil)) do={ :set prefix $delegated }
        }
        # Fallback para pools dinamicos, sem confundir o remoto /60 com o PD.
        :if ($prefix = "") do={
            :foreach p in=[/ipv6 pool used find] do={
                :local used [:tostr [/ipv6 pool used get $p prefix]]
                :if (([:tostr [/ipv6 pool used get $p info]] = $pppUser) && ([:find $used "/64"] != nil)) do={ :set prefix $used }
            }
        }
        :if (($ipv6 != "") && ($prefix != "")) do={ :set tentativas 8 } else={ :set tentativas ($tentativas + 1) }
    }
    :if ($prefix = "") do={ :log warning ("IPv6 API: prefixo nao localizado no MikroTik; consultando cadastro do MK-Auth para " . $pppUser) }
    :local data ("ipv6=".$ipv6."&prefix=".$prefix."&user=".$pppUser."&ip=".$ipv4."&token=".$token)
    :do { /tool fetch url=$url http-method=post http-header-field="Content-Type: application/x-www-form-urlencoded" http-data=$data keep-result=no } on-error={ :log error ("IPv6 API: erro ao enviar ".$pppUser) }
}';
$onDown='{
    :local url "'.$base.'/disconnect.php"
    :local token "'.$token.'"
    :local pppUser [:tostr $user]
    :if ($pppUser = "") do={ :return }
    :do { /tool fetch url=($url."?user=".$pppUser."&token=".$token) http-method=get keep-result=no } on-error={ :log error ("IPv6 API: erro ao desconectar ".$pppUser) }
}';
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="../../estilos/font-awesome.css" rel="stylesheet"><link href="addon-theme.css?v=20260809" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script>
<style>html,body{overflow-x:hidden}.v6-wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#f8fbff;color:#19324d;padding:20px;border:1px solid #bfdbef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.v6-wrap h2,.v6-wrap h3{color:#102a43}.v6-nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.v6-nav a,.v6-version a{padding:10px 14px;border-radius:7px;background:#e1f1fb;color:#173b57;text-decoration:none;border:1px solid #b8dcef;font-weight:600}.v6-nav a.active,.v6-version a.active{background:#38a9dc;color:#fff}.v6-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;margin-top:12px}.v6-card{background:#fff;border:1px solid #bdd8ea;border-radius:10px;padding:14px;min-width:0;overflow:hidden}.v6-card textarea{display:block;width:100%;height:460px;max-width:100%;resize:vertical;background:#0c1830;color:#d1fae5;border:1px solid #456078;border-radius:7px;padding:12px;font:12px monospace;box-sizing:border-box;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;overflow-x:hidden;overflow-y:auto}.v6-copy{background:#18a86b;color:#fff;border:0;border-radius:6px;padding:9px 14px;cursor:pointer}.v6-note{background:#fff7dc;color:#6b4b00;border:1px solid #e9c75d;padding:12px;border-radius:7px;margin:14px 0}@media(max-width:850px){.v6-wrap{width:calc(100vw - 20px);padding:12px}.v6-grid{grid-template-columns:1fr}}</style></head><body>
<?php ob_start(); include('../../topo.php'); echo ipv6CleanMkAuthDiagnostic(ob_get_clean()); ?><div class="container"><div class="v6-wrap"><h2>Scripts de coleta MikroTik</h2><div class="v6-nav"><a href="ipv6.php">Painel e logs</a><a class="active" href="mikrotik.php">Scripts MikroTik</a><a href="cgnat.php">CGNAT</a><a href="cgnat_history.php">Historico de CGNAT</a><a href="import.php">Importar mapeamento</a></div>
<div class="v6-version"><strong>RouterOS:</strong> <a class="<?=($version==='6'?'active':'')?>" href="?version=6">Vers&atilde;o 6</a> <a class="<?=($version==='7'?'active':'')?>" href="?version=7">Vers&atilde;o 7</a></div><div class="v6-note">Cole no PPP Profile. O On Down novo envia token e permite desligar o modo legado depois da atualiza&ccedil;&atilde;o dos roteadores.</div>
<div class="v6-grid"><div class="v6-card"><h3>On Up &mdash; RouterOS <?=$version?></h3><button class="v6-copy" onclick="copyScript('up',this)">Copiar On Up</button><textarea id="up" wrap="soft" readonly><?=htmlspecialchars($onUp,ENT_QUOTES,'UTF-8')?></textarea></div><div class="v6-card"><h3>On Down &mdash; RouterOS <?=$version?></h3><button class="v6-copy" onclick="copyScript('down',this)">Copiar On Down</button><textarea id="down" wrap="soft" readonly><?=htmlspecialchars($onDown,ENT_QUOTES,'UTF-8')?></textarea></div></div></div></div>
<?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php if (is_file('../../rodape.php')) { include('../../rodape.php'); } ?><script>function copyScript(id,b){var e=document.getElementById(id);navigator.clipboard.writeText(e.value).then(function(){var x=b.textContent;b.textContent='Copiado!';setTimeout(function(){b.textContent=x},1500)})}</script></body></html>

