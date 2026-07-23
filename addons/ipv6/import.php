<?php
$addonsClass=file_exists(__DIR__.'/addons.class.php')?__DIR__.'/addons.class.php':dirname(__DIR__).'/addons.class.php';
include($addonsClass);
if(session_status()===PHP_SESSION_NONE){session_name('mka');session_start();}
if(!isset($_SESSION['mka_logado'])&&!isset($_SESSION['MKA_Logado']))exit('Acesso negado');
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
<!DOCTYPE html><html lang="pt-BR" class="has-navbar-fixed-top"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="../../estilos/mk-auth.css" rel="stylesheet"><style>
html,body{overflow-x:hidden}.wrap{position:relative;left:50%;transform:translateX(-50%);width:calc(100vw - 32px);max-width:1800px;box-sizing:border-box;margin:20px 0;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 22px}.nav a{padding:10px 14px;border-radius:7px;background:#1e293b;color:#e2e8f0;text-decoration:none;border:1px solid #26364d}.nav a.active{background:#2563eb}.card{background:#111c31;border:1px solid #334155;border-radius:9px;padding:18px}.drop{border:2px dashed #38bdf8;border-radius:10px;padding:28px;text-align:center}.drop input{display:block;margin:15px auto}.button{background:#22c55e;color:#fff;border:0;border-radius:7px;padding:10px 18px}.ok,.err{padding:12px;margin-bottom:12px;border-radius:7px}.ok{background:#16351f;border:1px solid #22c55e}.err{background:#491b1b;border:1px solid #ef4444}table{width:100%;margin-top:18px;background:#0f172a}td{border:1px solid #334155;padding:8px;word-break:break-word}@media(max-width:750px){.wrap{width:calc(100vw - 20px)}}
</style></head><body><?php include('../../topo.php'); ?><div class="container"><div class="wrap"><h2>Importar mapeamento CGNAT</h2><div class="nav"><a href="ipv6.php">Painel e logs</a><a href="mikrotik.php">Scripts MikroTik</a><a href="cgnat.php">CGNAT</a><a class="active" href="import.php">Importar mapeamento</a></div>
<?php if($message):?><div class="ok"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="card"><form method="post" enctype="multipart/form-data"><div class="drop"><strong>Selecione o mapa de IP e portas</strong><p>CSV, TXT, Excel, PDF ou Word, ate 20 MB.</p><input type="file" name="mapping" accept=".csv,.txt,.xlsx,.xls,.pdf,.doc,.docx" required><button class="button">Enviar e validar arquivo</button></div></form>
<?php if($preview):?><table><tbody><?php foreach($preview as $row):?><tr><?php foreach($row as $cell):?><td><?=htmlspecialchars($cell,ENT_QUOTES,'UTF-8')?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?></div></div></div>
<?php include('../../baixo.php'); ?><script src="../../menu.js.php"></script><?php include('../../rodape.php'); ?></body></html>
