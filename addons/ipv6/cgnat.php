<?php
require_once __DIR__.'/bootstrap.lib.php';
require_once __DIR__.'/migrations.lib.php';
require_once __DIR__.'/retention.lib.php';
require_once __DIR__.'/cgnat.lib.php';
ipv6RequireMkAuthLogin();
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius');
ipv6RunMigrations($conn);

$defaults=array('name'=>'CGNAT','public'=>'','private_start'=>'100.64.0.0','ratio'=>'32','type'=>'netmap','protocol'=>'tcp_udp','interface'=>'','ignore'=>'','routeros'=>'7','blackhole'=>'0','nat_others'=>'1','linked'=>'1');
$o=$defaults;
foreach($o as $key=>$value){$o[$key]=isset($_POST[$key])?trim((string)$_POST[$key]):ipv6GetSetting($conn,'cgnat_'.$key,$value);}
foreach(array('blackhole','nat_others','linked') as $check)$o[$check]=isset($_POST['generate'])?(isset($_POST[$check])?'1':'0'):$o[$check];
$result=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $result=cgnatGenerate($o);
  foreach($o as $key=>$value)ipv6SaveSetting($conn,'cgnat_'.$key,$value);
  if(isset($_POST['download'])){
   $filename=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($o['name'])).'.rsc';
   header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo $result['script'];exit;
  }
 }catch(Exception $e){$error=$e->getMessage();}
}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="../../estilos/font-awesome.css" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script>
<style>html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#0f172a;color:#e2e8f0;padding:18px;border-radius:10px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#1e293b;color:#e2e8f0;text-decoration:none}.nav a.active{background:#2563eb}.card{background:#111c31;border:1px solid #334155;border-radius:9px;padding:17px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field label{display:block;font-weight:600;margin-bottom:6px}.field input,.field select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #475569;border-radius:6px;background:#f8fafc;color:#0f172a}.hint{color:#94a3b8;margin:8px 0 0}.advanced{margin-top:16px;border:1px solid #334155;border-radius:8px;padding:12px}.advanced summary{cursor:pointer;color:#7dd3fc;font-weight:600}.checks{display:flex;gap:22px;flex-wrap:wrap;margin:18px 0}.btn{border:0;border-radius:7px;padding:11px 18px;color:#fff;background:#2563eb;cursor:pointer}.btn.download{background:#22c55e}.alert{padding:12px;border-radius:7px;margin:12px 0;background:#491b1b;border:1px solid #ef4444}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.metric{background:#17243a;border:1px solid #334155;padding:13px;border-radius:8px;text-align:center}.metric strong{display:block;font-size:20px;color:#7dd3fc}.script{width:100%;height:440px;box-sizing:border-box;background:#020617;color:#d1fae5;border:1px solid #475569;border-radius:8px;padding:13px;font:12px monospace;white-space:pre-wrap;overflow-wrap:anywhere;overflow-x:hidden}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}@media(max-width:950px){.grid{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr 1fr}}@media(max-width:620px){.wrap{width:calc(100vw - 18px)}.grid{grid-template-columns:1fr}.summary{grid-template-columns:1fr}}</style></head><body>
<?php include('../../topo.php'); ?><div class="container"><main class="wrap"><h2>Gerador CGNAT para MikroTik</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a class="active" href="cgnat.php">CGNAT</a><a href="import.php">Importar mapeamento</a></div>
<?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?>
<section class="card"><form method="post"><div class="grid">
<div class="field"><label>IP privado inicial (sem /)</label><input name="private_start" placeholder="100.64.0.0" value="<?=h($o['private_start'])?>" required></div>
<div class="field"><label>Bloco IPv4 publico</label><input name="public" placeholder="45.228.151.112/29" value="<?=h($o['public'])?>" required></div>
<div class="field"><label>1 IP publico para quantos privados?</label><select name="ratio" required><?php foreach(array(4=>'~16.128 portas',8=>'~8.064 portas',16=>'~4.032 portas',32=>'~2.016 portas',64=>'~1.008 portas',128=>'~504 portas (nao recomendado)',256=>'~252 portas (nao recomendado)') as $ratio=>$label):?><option value="<?=$ratio?>" <?=((int)$o['ratio']===$ratio)?'selected':''?>><?=$ratio?> clientes [<?=$label?>]</option><?php endforeach;?></select></div>
</div><p class="hint">O addon calcula automaticamente o bloco privado, a primeira porta e todas as faixas. Nenhuma faixa e digitada manualmente.</p>
<details class="advanced"><summary>Opcoes avancadas (opcionais)</summary><div class="grid" style="margin-top:14px">
<div class="field"><label>Nome do CGNAT / chain</label><input name="name" maxlength="16" value="<?=h($o['name'])?>"></div>
<div class="field"><label>Tipo do CGNAT</label><select name="type"><option value="netmap" <?=$o['type']==='netmap'?'selected':''?>>NETMAP</option><option value="src-nat" <?=$o['type']==='src-nat'?'selected':''?>>SRC-NAT</option></select></div>
<div class="field"><label>Protocolos</label><select name="protocol"><option value="tcp_udp" <?=$o['protocol']==='tcp_udp'?'selected':''?>>TCP + UDP (recomendado)</option><option value="tcp" <?=$o['protocol']==='tcp'?'selected':''?>>Somente TCP</option></select></div>
<div class="field"><label>Interface de saida WAN</label><input name="interface" placeholder="VL-200-LINK-CUIABA" value="<?=h($o['interface'])?>"></div>
<div class="field"><label>Destino que nao deve usar NAT</label><input name="ignore" placeholder="10.0.0.0/8 ou LISTA_SERVIDORES" value="<?=h($o['ignore'])?>"></div>
<div class="field"><label>RouterOS</label><select name="routeros"><option value="6" <?=$o['routeros']==='6'?'selected':''?>>Versao 6</option><option value="7" <?=$o['routeros']==='7'?'selected':''?>>Versao 7</option></select></div>
</div><div class="checks"><label><input type="checkbox" name="linked" <?=$o['linked']==='1'?'checked':''?>> Vincular configuracao aos logs</label><label><input type="checkbox" name="blackhole" <?=$o['blackhole']==='1'?'checked':''?>> Criar blackhole</label><label><input type="checkbox" name="nat_others" <?=$o['nat_others']==='1'?'checked':''?>> NAT para outros protocolos</label></div></details>
<button class="btn" style="margin-top:16px" name="generate" value="1">Calcular, validar e gerar script</button></form></section>
<?php if($result):?><section class="card" style="margin-top:16px"><div class="summary"><div class="metric"><strong><?=$result['public_count']?></strong>IPs publicos</div><div class="metric"><strong><?=$result['private_count']?></strong>IPs privados<br><?=h($result['private_cidr'])?></div><div class="metric"><strong><?=$result['clients_per_public']?></strong>Clientes por IP</div><div class="metric"><strong><?=$result['ports']?></strong>Portas por cliente</div><div class="metric"><strong><?=$result['first_port']?>-<?=$result['last_port']?></strong>Faixa utilizada</div></div><textarea id="script" class="script" readonly><?=h($result['script'])?></textarea><div class="actions"><button class="btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('script').value)">Copiar script</button><form method="post"><?php foreach($o as $key=>$value):?><input type="hidden" name="<?=h($key)?>" value="<?=h($value)?>"><?php endforeach;?><input type="hidden" name="generate" value="1"><button class="btn download" name="download" value="1">Baixar .RSC</button></form></div></section><?php endif;?>
</main></div><?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
