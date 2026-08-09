<?php
require_once __DIR__.'/bootstrap.lib.php';
ipv6RequireMkAuthLogin();
$Manifest=ipv6LoadAddonManifest();
$manifestTitle=$Manifest->{'name'}??'Painel IPv4 e IPv6';
$manifestVersion=$Manifest->{'version'}??'1.0';
$message='';$error='';$preview=array();
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_FILES['mapping'])){
 $file=$_FILES['mapping'];$ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
 $allowed=array('csv','txt','xlsx','xls','pdf','doc','docx');
 if($file['error']!==UPLOAD_ERR_OK)$error='Falha ao receber o arquivo.';
 elseif(!in_array($ext,$allowed,true))$error='Formato nao aceito.';
 elseif($file['size']>20*1024*1024)$error='O arquivo excede 20 MB.';
 else{
  $dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0750,true);
  $name=date('Ymd-His').'-'.preg_replace('/[^a-zA-Z0-9._-]/','_',basename($file['name']));
  if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))$error='Nao foi possivel salvar o arquivo.';
  else{
   $message='Arquivo recebido: '.$name.'.';
   if($ext==='csv'||$ext==='txt'){
    $h=fopen($dir.'/'.$name,'r');$delimiter=$ext==='csv'?';':',';
    while($h&&count($preview)<8&&($row=fgetcsv($h,0,$delimiter))!==false)$preview[]=$row;
    if($h)fclose($h);$message.=' Pre-visualizacao concluida; confirme as colunas antes da sincronizacao.';
   }else $message.=' Arquivo guardado para extracao e validacao antes da sincronizacao.';
  }
 }
}
?>
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><link href="addon-theme.css?v=20260809" rel="stylesheet"><script src="../../scripts/jquery.js"></script><script src="../../scripts/mk-auth.js"></script><style>
html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#f8fbff;color:#19324d;padding:20px;border:1px solid #bfdbef;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.wrap h2{color:#102a43}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#e1f1fb;color:#173b57;text-decoration:none;border:1px solid #b8dcef;font-weight:600}.nav a.active{background:#38a9dc;color:#fff}.card{background:#fff;border:1px solid #bdd8ea;border-radius:10px;padding:18px}.drop{border:2px dashed #38a9dc;background:#f4faff;border-radius:10px;padding:32px;text-align:center;color:#244761}.drop input{display:block;margin:15px auto;color:#19324d}.button{background:#208fc5;color:#fff;border:0;border-radius:7px;padding:10px 18px;font-weight:700}.ok,.err{padding:12px;margin-bottom:12px;border-radius:7px}.ok{background:#e9fff4;border:1px solid #35b77b;color:#155f42}.err{background:#fff1f1;border:1px solid #e56262;color:#842929}table{width:100%;margin-top:18px;background:#fff;border-collapse:collapse}td{border:1px solid #d8e6ef;padding:8px;word-break:break-word;color:#19324d}@media(max-width:750px){.wrap{width:calc(100vw - 20px)}}
</style></head><body><?php include('../../topo.php'); ?><div class="container"><div class="wrap"><h2>Importar mapeamento CGNAT</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a href="cgnat.php">CGNAT</a><a href="cgnat_history.php">Historico de CGNAT</a><a class="active" href="import.php">Importar mapeamento</a></div>
<?php if($message):?><div class="ok"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="card"><form method="post" enctype="multipart/form-data"><div class="drop"><strong>Selecione o mapa de IP e portas</strong><p>CSV, TXT, Excel, PDF ou Word, ate 20 MB.</p><input type="file" name="mapping" accept=".csv,.txt,.xlsx,.xls,.pdf,.doc,.docx" required><button class="button">Enviar e validar arquivo</button></div></form>
<?php if($preview):?><table><tbody><?php foreach($preview as $row):?><tr><?php foreach($row as $cell):?><td><?=htmlspecialchars($cell,ENT_QUOTES,'UTF-8')?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?></div></div></div>
<?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
