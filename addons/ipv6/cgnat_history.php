<?php
require_once __DIR__.'/bootstrap.lib.php';
require_once __DIR__.'/migrations.lib.php';
ipv6RequireMkAuthLogin();
$conn=new mysqli('127.0.0.1','root','vertrigo','mkradius');
ipv6RunMigrations($conn);
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $id=(int)($_POST['profile_id']??0);
  if($id<1)throw new RuntimeException('Perfil CGNAT invalido.');
  if(isset($_POST['activate'])){
   $conn->begin_transaction();
   $conn->query('UPDATE cgnat_profile_periods SET ended_at=NOW() WHERE ended_at IS NULL');
   $conn->query('UPDATE cgnat_profiles SET active=0');
   if(!$conn->query('UPDATE cgnat_profiles SET active=1 WHERE id='.$id) || $conn->affected_rows!==1)throw new RuntimeException('Perfil nao encontrado.');
   if(!$conn->query('INSERT INTO cgnat_profile_periods (profile_id,started_at) VALUES ('.$id.',NOW())'))throw new RuntimeException($conn->error);
   $conn->commit();$message='CGNAT #'.$id.' definido como padrao atual dos clientes.';
  }elseif(isset($_POST['delete'])){
   $check=$conn->query('SELECT active FROM cgnat_profiles WHERE id='.$id)->fetch_assoc();
   if(!$check)throw new RuntimeException('Perfil nao encontrado.');
   if((int)$check['active']===1)throw new RuntimeException('Marque outro CGNAT como padrao antes de excluir o perfil atual.');
   $conn->begin_transaction();
   $conn->query('DELETE FROM cgnat_profile_periods WHERE profile_id='.$id);
   $conn->query('DELETE FROM cgnat_mappings WHERE profile_id='.$id);
   $conn->query('DELETE FROM cgnat_profiles WHERE id='.$id);
   $conn->commit();$message='CGNAT #'.$id.' e seus mapeamentos foram excluidos.';
  }
 }catch(Exception $e){$conn->rollback();$error=$e->getMessage();}
}
$profiles=array();
$sql="SELECT p.*,COUNT(DISTINCT m.id) mapping_count,MIN(m.private_ip) first_private,MAX(m.private_ip) last_private,MIN(v.started_at) first_started,MAX(CASE WHEN v.ended_at IS NULL THEN v.started_at END) current_started,MAX(v.ended_at) last_ended FROM cgnat_profiles p LEFT JOIN cgnat_mappings m ON m.profile_id=p.id LEFT JOIN cgnat_profile_periods v ON v.profile_id=p.id GROUP BY p.id ORDER BY p.created_at DESC,p.id DESC";
$rows=$conn->query($sql);if($rows)while($row=$rows->fetch_assoc())$profiles[]=$row;
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="../../estilos/font-awesome.css" rel="stylesheet"><link href="addon-theme.css?v=20260809" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script>
<style>html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#f8fbff;color:#19324d;padding:20px;border:1px solid #bfdbef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#e1f1fb;color:#173b57;text-decoration:none;border:1px solid #b8dcef;font-weight:600}.nav a.active{background:#38a9dc;color:#fff}.card{background:#fff;border:1px solid #bdd8ea;border-radius:10px;padding:18px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th{background:#19324d;color:#fff;padding:11px;text-align:left}td{padding:10px;border-bottom:1px solid #d8e6ef;vertical-align:middle}tbody tr:nth-child(odd){background:#f4f9fc}.badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#e5edf3;color:#526d82;font-weight:700}.badge.active{background:#d9f8e9;color:#147348}.actions{display:flex;gap:8px;flex-wrap:wrap}.actions form{margin:0}.btn{border:0;border-radius:7px;padding:9px 12px;color:#fff;background:#208fc5;cursor:pointer;font-weight:700}.btn.danger{background:#d94b4b}.btn[disabled]{opacity:.45;cursor:not-allowed}.alert,.ok{padding:12px;border-radius:7px;margin:12px 0}.alert{background:#fff1f1;border:1px solid #e56262;color:#842929}.ok{background:#e9fff4;border:1px solid #35b77b;color:#155f42}.empty{text-align:center;padding:35px;color:#526d82}@media(max-width:700px){.wrap{width:calc(100vw - 18px)}}</style></head><body>
<?php include('../../topo.php'); ?><div class="container"><main class="wrap"><h2>Historico de CGNAT gerados</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a href="cgnat.php">CGNAT</a><a class="active" href="cgnat_history.php">Historico de CGNAT</a><a href="import.php">Importar mapeamento</a></div>
<?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?><?php if($message):?><div class="ok"><?=h($message)?></div><?php endif;?>
<section class="card"><p>O perfil marcado como <strong>Padrao atual</strong> e usado nas novas conexoes. Os periodos de vigencia ficam registrados para que conexoes antigas continuem sendo cruzadas com o CGNAT correto.</p><div class="table-wrap"><table><thead><tr><th>Gerado em</th><th>CGNAT</th><th>Rede privada</th><th>Rede publica</th><th>Portas</th><th>Clientes/IP</th><th>Total clientes</th><th>Vigencia</th><th>Status</th><th>Acoes</th></tr></thead><tbody>
<?php if(!$profiles):?><tr><td colspan="10" class="empty">Nenhum mapeamento CGNAT foi salvo ainda.</td></tr><?php endif;?>
<?php foreach($profiles as $p):?><tr><td><?=h(date('d/m/Y H:i',strtotime($p['created_at'])))?></td><td><strong><?=h(strtoupper($p['name']))?></strong><br>#<?=$p['id']?> · <?=h($p['source']==='generated'?'Gerado':'Importado')?></td><td><?=h($p['private_cidr'])?><br><small><?=number_format((int)$p['mapping_count'],0,',','.')?> IPs mapeados</small></td><td><?=h($p['public_cidr'])?></td><td><?=number_format((int)$p['ports_per_client'],0,',','.')?><br><small><?=$p['first_port']?>-<?=$p['last_port']?></small></td><td><?=$p['clients_per_public']?></td><td><?=number_format((int)$p['mapping_count'],0,',','.')?></td><td><?php if($p['first_started']):?>Desde <?=h(date('d/m/Y H:i',strtotime($p['first_started'])))?><?php endif;?><?php if($p['active']&&$p['current_started']):?><br><strong>Atual desde <?=h(date('d/m/Y H:i',strtotime($p['current_started'])))?></strong><?php elseif($p['last_ended']):?><br>Ate <?=h(date('d/m/Y H:i',strtotime($p['last_ended'])))?><?php endif;?></td><td><span class="badge <?=$p['active']?'active':''?>"><?=$p['active']?'Padrao atual':'Historico'?></span></td><td><div class="actions"><form method="post" onsubmit="return confirm('ATENCAO: alterar o CGNAT padrao muda o cruzamento das NOVAS conexoes. As conexoes antigas permanecerao ligadas ao periodo anterior. Confirma a alteracao?')"><input type="hidden" name="profile_id" value="<?=$p['id']?>"><button class="btn" name="activate" value="1" <?=$p['active']?'disabled':''?>>Marcar como padrao</button></form><form method="post" onsubmit="return confirm('Excluir este CGNAT, seus periodos e todos os mapeamentos? Conexoes historicas desse periodo deixarao de ter cruzamento CGNAT. Confirma?')"><input type="hidden" name="profile_id" value="<?=$p['id']?>"><button class="btn danger" name="delete" value="1" <?=$p['active']?'disabled title="Defina outro perfil como padrao antes de excluir"':''?>>Excluir</button></form></div></td></tr><?php endforeach;?>
</tbody></table></div></section></main></div><?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
