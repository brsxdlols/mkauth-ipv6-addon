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
$result=null;$error='';$message='';$mappingRows=array();
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $result=cgnatGenerate($o);
  foreach($o as $key=>$value)ipv6SaveSetting($conn,'cgnat_'.$key,$value);
  if(isset($_POST['download'])){
   $filename=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($o['name'])).'.rsc';
   header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo $result['script'];exit;
  }
  if(isset($_POST['download_pdf'])){
   $filename=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($o['name'])).'-mapeamento.pdf';
   header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');echo cgnatBuildPdf($result,$o);exit;
  }
  if(isset($_POST['save_mapping'])){
   $profileId=cgnatSaveProfile($conn,$result,$o);
   $message='Mapeamento salvo e ativado no painel. Identificacao #'.$profileId.'.';
  }
  $mappingRows=cgnatMappingRows($result);
 }catch(Exception $e){$error=$e->getMessage();}
}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="../../estilos/font-awesome.css" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script>
<style>html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#f8fbff;color:#19324d;padding:20px;border:1px solid #bfdbef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.wrap h2{color:#102a43}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#e1f1fb;color:#173b57;text-decoration:none;border:1px solid #b8dcef;font-weight:600}.nav a.active{background:#38a9dc;color:#fff}.card{background:#fff;border:1px solid #bdd8ea;border-radius:10px;padding:18px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field label{display:block;font-weight:700;margin-bottom:6px;color:#244761}.field input,.field select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #8bbbd5;border-radius:7px;background:#fff;color:#102a43}.hint{color:#486b82;margin:10px 0 0}.advanced{margin-top:16px;border:1px solid #b8d8e9;border-radius:8px;padding:13px;background:#f4faff}.advanced summary{cursor:pointer;color:#1677a6;font-weight:700}.checks{display:flex;gap:22px;flex-wrap:wrap;margin:18px 0;color:#244761}.btn{border:0;border-radius:7px;padding:11px 18px;color:#fff;background:#208fc5;cursor:pointer;font-weight:700}.btn.download{background:#18a86b}.btn.secondary{background:#526d82}.alert,.ok{padding:12px;border-radius:7px;margin:12px 0}.alert{background:#fff1f1;border:1px solid #e56262;color:#842929}.ok{background:#e9fff4;border:1px solid #35b77b;color:#155f42}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.metric{background:#eaf6fd;border:1px solid #b7dcef;padding:13px;border-radius:8px;text-align:center;color:#486b82}.metric strong{display:block;font-size:20px;color:#1677a6}.script{width:100%;height:340px;box-sizing:border-box;background:#0c1830;color:#d1fae5;border:1px solid #456078;border-radius:8px;padding:13px;font:12px monospace;white-space:pre-wrap;overflow-wrap:anywhere;overflow-x:hidden}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.mapping{margin-top:18px}.mapping table{width:100%;border-collapse:collapse;background:#fff}.mapping th{background:#19324d;color:#fff;padding:10px;text-align:left}.mapping td{padding:8px 10px;border-bottom:1px solid #d8e6ef}.mapping tbody tr:nth-child(odd){background:#f0f5f8}.mapping td:last-child{color:#d62828;font-weight:700}.print-title{font-size:28px;color:#19324d;margin:8px 0}.print-meta{color:#526d82;margin-bottom:16px}@media(max-width:950px){.grid{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr 1fr}}@media(max-width:620px){.wrap{width:calc(100vw - 18px)}.grid{grid-template-columns:1fr}.summary{grid-template-columns:1fr}}@media print{body>*{display:none!important}.mapping-print{display:block!important;position:absolute;inset:0}.mapping-actions{display:none}.mapping table{font-size:10px}}</style></head><body>
<?php include('../../topo.php'); ?><div class="container"><main class="wrap"><h2>Gerador CGNAT para MikroTik</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a class="active" href="cgnat.php">CGNAT</a><a href="import.php">Importar mapeamento</a></div>
<?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?>
<?php if($message):?><div class="ok"><?=h($message)?></div><?php endif;?>
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
<?php if($result):?><section class="card" style="margin-top:16px"><div class="summary"><div class="metric"><strong><?=$result['public_count']?></strong>IPs publicos</div><div class="metric"><strong><?=$result['private_count']?></strong>IPs privados<br><?=h($result['private_cidr'])?></div><div class="metric"><strong><?=$result['clients_per_public']?></strong>Clientes por IP</div><div class="metric"><strong><?=$result['ports']?></strong>Portas por cliente</div><div class="metric"><strong><?=$result['first_port']?>-<?=$result['last_port']?></strong>Faixa utilizada</div></div><textarea id="script" class="script" readonly><?=h($result['script'])?></textarea><div class="actions"><button class="btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('script').value)">Copiar script</button><form method="post"><?php foreach($o as $key=>$value):?><input type="hidden" name="<?=h($key)?>" value="<?=h($value)?>"><?php endforeach;?><input type="hidden" name="generate" value="1"><button class="btn download" name="download" value="1">Baixar .RSC</button><button class="btn download" name="download_pdf" value="1">Baixar PDF</button><button class="btn" name="save_mapping" value="1">Salvar e usar no painel</button></form></div></section>
<section class="card mapping mapping-print"><div class="print-title">MAPEAMENTO DAS PORTAS</div><div class="print-meta"><?=h(strtoupper($o['name']))?> | <?=h($result['private_cidr'])?> &gt; <?=h($result['public_cidr'])?> | <?=date('d/m/Y H:i')?></div><div class="actions mapping-actions"><button class="btn secondary" type="button" onclick="window.print()">Imprimir / salvar pela pagina</button><button class="btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mapping-copy').innerText)">Copiar tabela</button></div><table id="mapping-copy"><thead><tr><th>IP Publico</th><th>Range de Portas</th><th>IP Privado</th></tr></thead><tbody><?php foreach($mappingRows as $row):?><tr><td><?=h($row['public_ip'])?></td><td><?=$row['port_start']?> a <?=$row['port_end']?></td><td><?=h($row['private_ip'])?></td></tr><?php endforeach;?></tbody></table></section><?php endif;?>
</main></div><?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
