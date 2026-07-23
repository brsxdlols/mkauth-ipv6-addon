<?php
$addonsClass=file_exists(__DIR__.'/addons.class.php')?__DIR__.'/addons.class.php':dirname(__DIR__).'/addons.class.php';
include($addonsClass);
require_once __DIR__.'/migrations.lib.php';
if(session_status()===PHP_SESSION_NONE){session_name('mka');session_start();}
if(!isset($_SESSION['mka_logado'])&&!isset($_SESSION['MKA_Logado']))exit('Acesso negado');
$manifestTitle=$Manifest->{'name'}??'Painel IPv4 e IPv6';
$manifestVersion=$Manifest->{'version'}??'1.0';
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius');
ipv6RunMigrations($conn);
$fields=array(
 'cgnat_mode'=>'linked','cgnat_private_network'=>'100.64.0.0','cgnat_public_network'=>'',
 'cgnat_ratio'=>'32','cgnat_routeros'=>'7','cgnat_interface'=>'pppoe-out1',
 'cgnat_address_list'=>'CGNAT-CLIENTES','cgnat_blackhole'=>'1','cgnat_log'=>'0'
);
$saved='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $private=trim($_POST['private_network']??'');$public=trim($_POST['public_network']??'');
 if(strpos($private,'/')!==false)$error='Informe somente o IP privado inicial, sem /prefixo.';
 elseif(!filter_var($private,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))$error='IP privado inicial invalido.';
 elseif(!preg_match('/^((?:\d{1,3}\.){3}\d{1,3})\/(\d|[12]\d|3[0-2])$/',$public,$m)||!filter_var($m[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))$error='Prefixo publico invalido.';
 if(!$error){
  $values=array(
   'cgnat_mode'=>(($_POST['mode']??'linked')==='only'?'only':'linked'),
   'cgnat_private_network'=>$private,'cgnat_public_network'=>$public,
   'cgnat_ratio'=>(string)(int)($_POST['ratio']??32),
   'cgnat_routeros'=>(($_POST['routeros']??'7')==='6'?'6':'7'),
   'cgnat_interface'=>trim($_POST['interface']??''),
   'cgnat_address_list'=>trim($_POST['address_list']??''),
   'cgnat_blackhole'=>isset($_POST['blackhole'])?'1':'0',
   'cgnat_log'=>isset($_POST['rule_log'])?'1':'0'
  );
  foreach($values as $k=>$v)ipv6SaveSetting($conn,$k,$v);
  $saved='Configuracao salva. Use Gerar script para revisar antes de aplicar no MikroTik.';
 }
}
foreach($fields as $k=>$default)$$k=ipv6GetSetting($conn,$k,$default);
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet">
<style>
html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#1e293b;color:#e2e8f0;text-decoration:none;border:1px solid #26364d}.nav a.active{background:#2563eb}.card{background:#111c31;border:1px solid #334155;border-radius:9px;padding:16px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field label{display:block;font-weight:bold;margin-bottom:5px}.field input,.field select{width:100%;padding:10px;box-sizing:border-box;border:1px solid #475569;border-radius:6px;background:#f8fafc;color:#0f172a}.choices{display:flex;gap:18px;flex-wrap:wrap;margin:0 0 18px}.checks{display:flex;gap:24px;flex-wrap:wrap;margin-top:16px}.save{margin-top:16px;background:#22c55e;color:#fff;border:0;padding:10px 18px;border-radius:7px;cursor:pointer}.ok,.err,.calc,.note{padding:12px;margin:12px 0;border-radius:7px}.ok{background:#16351f;border:1px solid #22c55e}.err{background:#491b1b;border:1px solid #ef4444}.calc{background:#10243d;border:1px solid #38bdf8}.note{background:#422006;border:1px solid #d97706}@media(max-width:750px){.wrap{width:calc(100vw - 20px)}.grid{grid-template-columns:1fr}}
</style></head><body><?php include('../../topo.php'); ?><div class="container"><div class="wrap"><h2>Gerador CGNAT</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a class="active" href="cgnat.php">CGNAT</a><a href="import.php">Importar mapeamento</a></div>
<?php if($saved):?><div class="ok"><?=htmlspecialchars($saved)?></div><?php endif;?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="card"><form method="post"><div class="choices"><strong>Finalidade:</strong><label><input type="radio" name="mode" value="linked" <?=($cgnat_mode==='linked'?'checked':'')?>> Gerar CGNAT e vincular com os logs</label><label><input type="radio" name="mode" value="only" <?=($cgnat_mode==='only'?'checked':'')?>> Gerar apenas o script CGNAT</label></div>
<div class="grid">
<div class="field"><label>IP privado inicial (sem /)</label><input id="private_network" name="private_network" value="<?=htmlspecialchars($cgnat_private_network)?>" placeholder="100.64.0.0"></div>
<div class="field"><label>Prefixo publico</label><input id="public_network" name="public_network" value="<?=htmlspecialchars($cgnat_public_network)?>" placeholder="200.200.200.0/24"></div>
<div class="field"><label>Privados por IP publico</label><select id="ratio" name="ratio"><?php foreach(array(4,8,16,32,64,128) as $n):?><option value="<?=$n?>" <?=((int)$cgnat_ratio===$n?'selected':'')?>><?=$n?> clientes</option><?php endforeach;?></select></div>
<div class="field"><label>RouterOS</label><select name="routeros"><option value="6" <?=($cgnat_routeros==='6'?'selected':'')?>>Versao 6</option><option value="7" <?=($cgnat_routeros==='7'?'selected':'')?>>Versao 7</option></select></div>
<div class="field"><label>Interface de saida WAN</label><input name="interface" value="<?=htmlspecialchars($cgnat_interface)?>" placeholder="pppoe-out1 ou sfp-sfpplus1"></div>
<div class="field"><label>Nome da address-list</label><input name="address_list" value="<?=htmlspecialchars($cgnat_address_list)?>" placeholder="CGNAT-CLIENTES"></div>
</div><div class="checks"><label><input type="checkbox" name="blackhole" value="1" <?=($cgnat_blackhole==='1'?'checked':'')?>> Criar rota blackhole</label><label><input type="checkbox" name="rule_log" value="1" <?=($cgnat_log==='1'?'checked':'')?>> Ativar log nas regras</label></div>
<div id="calculation" class="calc">Preencha os campos para calcular.</div><button class="save">Salvar e preparar script</button></form><div class="note">Nenhuma regra e aplicada automaticamente. O script sera exibido para revisao e copia antes de enviar ao MikroTik.</div></div></div></div>
<script>function ipToInt(ip){var p=ip.split('.');if(p.length!==4)return null;var n=0;for(var i=0;i<4;i++){var x=Number(p[i]);if(!Number.isInteger(x)||x<0||x>255)return null;n=n*256+x}return n}function intToIp(n){return[Math.floor(n/16777216)%256,Math.floor(n/65536)%256,Math.floor(n/256)%256,n%256].join('.')}function calculate(){var priv=document.getElementById('private_network').value.trim(),pub=document.getElementById('public_network').value.trim(),ratio=Number(document.getElementById('ratio').value),box=document.getElementById('calculation'),m=pub.match(/^(.+)\/(\d{1,2})$/),start=ipToInt(priv);if(!m||start===null||ipToInt(m[1])===null||Number(m[2])>32){box.textContent='Informe o IP privado inicial sem / e o prefixo publico com /.';return}var count=Math.pow(2,32-Number(m[2])),clients=count*ratio,end=start+clients-1;if(end>4294967295){box.textContent='O range ultrapassa o limite IPv4.';return}box.innerHTML='<strong>Calculo:</strong> '+count.toLocaleString('pt-BR')+' IPs publicos x '+ratio+' = <strong>'+clients.toLocaleString('pt-BR')+' IPs privados</strong><br>Range: <strong>'+priv+' ate '+intToIp(end)+'</strong>'}['private_network','public_network','ratio'].forEach(function(id){document.getElementById(id).addEventListener('input',calculate)});calculate();</script>
<?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
