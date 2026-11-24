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
require_once __DIR__.'/mikrotik-scripts.lib.php';
list($onUp,$onDown)=ipv6FetchScripts($base,$token);
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="../../estilos/font-awesome.css" rel="stylesheet"><link href="addon-theme.css?v=20260809" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script>
<style>html{overflow-x:clip;overflow-y:auto}body{overflow:visible;height:auto;max-height:none}.v6-wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#f8fbff;color:#19324d;padding:20px;border:1px solid #bfdbef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.v6-wrap h2,.v6-wrap h3{color:#102a43}.v6-nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.v6-nav a,.v6-version a{padding:10px 14px;border-radius:7px;background:#e1f1fb;color:#173b57;text-decoration:none;border:1px solid #b8dcef;font-weight:600}.v6-nav a.active,.v6-version a.active{background:#38a9dc;color:#fff}.v6-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;margin-top:12px}.v6-card{background:#fff;border:1px solid #bdd8ea;border-radius:10px;padding:14px;min-width:0;overflow:hidden}.v6-card textarea{display:block;width:100%;height:auto;min-height:180px;max-width:100%;resize:vertical;background:#0c1830;color:#d1fae5;border:1px solid #456078;border-radius:7px;padding:12px;font:12px monospace;box-sizing:border-box;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;overflow-x:hidden;overflow-y:hidden}.v6-copy{background:#18a86b;color:#fff;border:0;border-radius:6px;padding:9px 14px;cursor:pointer}.v6-note{background:#fff7dc;color:#6b4b00;border:1px solid #e9c75d;padding:12px;border-radius:7px;margin:14px 0}@media(max-width:850px){.v6-wrap{width:calc(100vw - 20px);padding:12px}.v6-grid{grid-template-columns:1fr}}</style><style>.v6-card .v6-copy{display:inline-flex;margin:8px 0 14px}.v6-card textarea{display:block}.v6-version{margin-bottom:12px}</style></head><body>
<?php ob_start(); include('../../topo.php'); echo ipv6CleanMkAuthDiagnostic(ob_get_clean()); ?><div class="container"><div class="v6-wrap"><h2>Scripts de coleta MikroTik</h2><div class="v6-nav"><a href="ipv6.php">Painel e logs</a><a class="active" href="mikrotik.php">Scripts MikroTik</a><a href="cgnat.php">CGNAT</a><a href="cgnat_history.php">Historico de CGNAT</a><a href="#" onclick="alert('Em constru&ccedil;&atilde;o');return false" aria-disabled="true">Importar mapeamento</a></div>
<div class="v6-version"><strong>RouterOS:</strong> <a class="<?=($version==='6'?'active':'')?>" href="?version=6">Vers&atilde;o 6</a> <a class="<?=($version==='7'?'active':'')?>" href="?version=7">Vers&atilde;o 7</a></div><div class="v6-note">Cole no PPP Profile. O On Down novo envia token e permite desligar o modo legado depois da atualiza&ccedil;&atilde;o dos roteadores.</div>
<div class="v6-grid"><div class="v6-card"><h3>On Up &mdash; RouterOS <?=$version?></h3><button class="v6-copy" onclick="copyScript('up',this)">Copiar On Up</button><textarea id="up" wrap="soft" readonly><?=htmlspecialchars($onUp,ENT_QUOTES,'UTF-8')?></textarea></div><div class="v6-card"><h3>On Down &mdash; RouterOS <?=$version?></h3><div class="v6-cleanup" style="margin:10px 0 14px"><label><input id="cleanupV6" type="checkbox" role="switch" onchange="updateDownScript(false)"> Limpar IPv6 da address-list ao desconectar</label><div style="margin-top:8px"><label for="cleanupList">Nome da address-list IPv6</label><input id="cleanupList" type="text" maxlength="100" placeholder="Nome exato da lista" disabled oninput="updateDownScript(false)" style="display:block;width:100%;box-sizing:border-box;margin-top:5px;padding:8px"><small>Opcional. Remove apenas desta lista as entradas com coment&aacute;rio igual ao login PPPoE.</small></div></div><button class="v6-copy" onclick="copyScript('down',this)">Copiar On Down</button><textarea id="down" wrap="soft" readonly><?=htmlspecialchars($onDown,ENT_QUOTES,'UTF-8')?></textarea></div></div></div></div>
<?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php if (is_file('../../rodape.php')) { include('../../rodape.php'); } ?><script>function fitScripts(){
 ['up','down'].forEach(function(id){var e=document.getElementById(id);e.style.height='auto';e.style.height=(e.scrollHeight+2)+'px';});
}
function routerQuote(value){return '"'+value.replace(/\\/g,'\\\\').replace(/"/g,'\\"').replace(/\$/g,'\\$')+'"';}
function updateDownScript(showError){
 var enabled=document.getElementById('cleanupV6').checked,name=document.getElementById('cleanupList'),e=document.getElementById('down');
 name.disabled=!enabled;name.required=enabled;
 var value=name.value.trim(),valid=!enabled||(value.length>0&&!/[\x00-\x1f\x7f]/.test(value));
 name.setCustomValidity(valid?'':'Informe o nome exato da address-list IPv6.');
 e.value=e.defaultValue;
 if(enabled&&valid){
  var rule='    :do { /ipv6 firewall address-list remove [find where list='+routerQuote(value)+' comment=$pppUser] } on-error={ :log warning ("IPv6 API: limpeza da address-list nao confirmada para ".$pppUser) }\n';
  e.value=e.value.replace('    # IPV6_LOG_OPTIONAL_CLEANUP',rule+'    # IPV6_LOG_OPTIONAL_CLEANUP');
 }
 if(!valid&&showError){name.reportValidity();name.focus();}
 fitScripts();return valid;
}
fitScripts();window.addEventListener('resize',fitScripts);
async function copyScript(id,b){
 if(id==='down'&&!updateDownScript(true)){return;}
 var e=document.getElementById(id),original=b.textContent,ok=false;
 if(window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText){
  try{await navigator.clipboard.writeText(e.value);ok=true}catch(err){}
 }
 if(!ok){
  var x=window.scrollX,y=window.scrollY;
  e.focus({preventScroll:true});e.select();e.setSelectionRange(0,e.value.length);
  try{ok=document.execCommand('copy')}catch(err){}
  window.scrollTo(x,y);
 }
 if(ok){b.textContent='Copiado!';setTimeout(function(){b.textContent=original},1500)}
 else{e.focus({preventScroll:true});e.select();e.setSelectionRange(0,e.value.length);alert('O navegador bloqueou a copia automatica. O script esta selecionado: pressione Ctrl+C para copiar.');}
}</script></body></html>



