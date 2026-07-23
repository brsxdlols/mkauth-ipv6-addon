<?php
// ===== MKAUTH =====
$addonsClass = file_exists(__DIR__ . '/addons.class.php')
    ? __DIR__ . '/addons.class.php'
    : dirname(__DIR__) . '/addons.class.php';
include($addonsClass);
require_once __DIR__ . '/migrations.lib.php';
require_once __DIR__ . '/retention.lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('mka');
    session_start();
}
if (!isset($_SESSION['mka_logado']) && !isset($_SESSION['MKA_Logado'])) {
    exit('Acesso negado... <a href="/admin/login.php">Fazer Login</a>');
}

$manifestTitle = $Manifest->{'name'} ?? 'Painel IPv4 e IPv6';
$manifestVersion = $Manifest->{'version'} ?? '1.0';

// ===== BANCO =====
$conn = new mysqli("127.0.0.1","root","vertrigo","mkradius");
ipv6RunMigrations($conn);

$retentionMonths = ipv6GetRetentionMonths($conn);
$scheduledCleanup = ipv6RunMonthlyCleanupIfDue($conn);
$configMessage = '';
$showConfig = isset($_GET['config']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_retencao'])) {
    $retentionMonths = ipv6SaveRetentionMonths($conn, $_POST['retention_months'] ?? 12);
    $configMessage = 'Configuracao salva. Agendamento interno mensal ativo.';

    if (($_POST['executar_limpeza'] ?? 'nao') === 'sim') {
        $deleted = ipv6CleanupHistory($conn, $retentionMonths);
        $configMessage .= ' Limpeza executada: ' . $deleted . ' registro(s) removido(s).';
        ipv6SaveSetting($conn, 'last_cleanup_month', date('Y-m'));
        ipv6SaveSetting($conn, 'last_cleanup_at', date('Y-m-d H:i:s'));
    }

    $showConfig = true;
} elseif ($scheduledCleanup['ran']) {
    $configMessage = 'Limpeza mensal automatica executada: ' . $scheduledCleanup['deleted'] . ' registro(s) removido(s).';
}

// Página atual (pega da URL: ?page=2)
// Se não existir, começa na página 1
$page = max(1, intval($_GET['page'] ?? 1));

// Quantidade de registros por página (30, 50 ou 100)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;

// Segurança: só permite esses valores
if(!in_array($limit,[30,50,100])) $limit = 30;

// Calcula de onde começar no banco
// Exemplo: página 2 com 30 registros → começa do 30
$offset = ($page - 1) * $limit;



// ===== EXPORT CSV =====
if(isset($_GET['export'])){

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=ipv6.csv');

    $out = fopen('php://output','w');

    fputcsv($out, ['Usuario','IPv4','Inicio']);

    $res = $conn->query("SELECT username, framedipaddress, acctstarttime FROM radacct ORDER BY radacctid DESC LIMIT 200");

    while($r = $res->fetch_assoc()){
        fputcsv($out, $r);
    }

    exit;
}

$busca  = $_GET['busca'] ?? '';
$busca = $conn->real_escape_string($busca);
$inicio = $_GET['inicio'] ?? '';
$fim    = $_GET['fim'] ?? '';
$status = in_array(($_GET['status'] ?? ''), array('online','offline'), true) ? $_GET['status'] : '';
$page   = max(1, intval($_GET['page'] ?? 1));

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
if(!in_array($limit, [30,50,100])) $limit = 30;

$offset = ($page-1)*$limit;

$modoResumo = empty($busca) && empty($inicio) && empty($fim) && empty($status);

// ===== SQL =====
function buildSQL($busca,$inicio,$fim,$modoResumo,$status=''){

    $joinIPv6 = "
    LEFT JOIN (
        SELECT h1.*
        FROM ipv6_history h1
        INNER JOIN (
            SELECT session_id, MAX(id) as max_id
            FROM ipv6_history
            GROUP BY session_id
        ) h2 
        ON h1.session_id = h2.session_id 
        AND h1.id = h2.max_id
    ) h ON h.session_id = r.acctsessionid
    ";

    if($modoResumo){
        $sql = "
        SELECT r.username,r.framedipaddress,r.acctstarttime,r.acctstoptime,h.ipv6,h.callingstationid
        FROM radacct r
        $joinIPv6
        WHERE r.radacctid IN (
            SELECT MAX(r2.radacctid)
            FROM radacct r2
            GROUP BY r2.username
        )";
    } else {
        $sql = "
        SELECT r.username,r.framedipaddress,r.acctstarttime,r.acctstoptime,h.ipv6,h.callingstationid
        FROM radacct r
        $joinIPv6
        WHERE 1=1";
    }

    if($busca){
        $sql .= " AND (
            r.username LIKE '%$busca%' OR
            r.framedipaddress LIKE '%$busca%' OR
            h.ipv6 LIKE '%$busca%'
        )";
    }

    if($inicio && $fim){
        $sql .= " AND r.acctstarttime BETWEEN '$inicio' AND '$fim'";
    }

    if($status === 'online') $sql .= " AND r.acctstoptime IS NULL";
    if($status === 'offline') $sql .= " AND r.acctstoptime IS NOT NULL";

    $sql .= " AND r.username NOT REGEXP '^[0-9A-F:]{17}$'";

    return $sql;
}

function calcDuracao($ini,$fim){
    if(!$ini) return "";
    $fim = $fim ?: date('Y-m-d H:i:s');
    return gmdate("H:i:s", strtotime($fim) - strtotime($ini));
}
// TOTAL DE REGISTROS (para paginação)
$totalRegistros = $conn->query("
    SELECT COUNT(*) t FROM radacct
")->fetch_assoc()['t'];
$totalPaginas = ceil($totalRegistros / $limit);
// TOTAL ONLINE (para mostrar no topo)
$totalOnline = $conn->query("
    SELECT COUNT(*) t FROM radacct WHERE acctstoptime IS NULL
")->fetch_assoc()['t'];
$historyStats = ipv6HistoryStats($conn, $retentionMonths);
?>

<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="iso-8859-1">

<link href="../../estilos/mk-auth.css" rel="stylesheet" />
<link href="../../estilos/font-awesome.css" rel="stylesheet" />
<link href="../../estilos/bi-icons.css" rel="stylesheet" />

<script src="../../scripts/jquery.js"></script>
<script src="../../scripts/mk-auth.js"></script>
<script>
jQuery(function($){
    var timer=null, $input=$('input[name="busca"]'), $status=$('select[name="status"]'), $limit=$('select[name="limit"]'), $body=$('#ipv6-results'), $form=$('.ipv6-filters'), $info=$('#live-search-info');
    if(!$input.length||!$body.length)return;
    function safe(value){return $('<div>').text(value==null?'':value).html();}
    function searchNow(){
        clearTimeout(timer); $info.text('Buscando...');
        timer=setTimeout(function(){
            $.ajax({url:'search.php',dataType:'json',cache:false,data:{q:$input.val(),status:$status.val(),limit:$limit.val()}})
            .done(function(data){
                if(!data||!$.isArray(data.rows)){ $info.text('Resposta invalida da busca.'); return; }
                var html=''; $.each(data.rows,function(_,r){html+='<tr><td><span class="'+(r.online?'online':'offline')+'">&#9679; '+(r.online?'Online':'Offline')+'</span></td><td>'+safe(r.username)+'</td><td>'+safe(r.ipv6||'—')+'</td><td>'+safe(r.framedipaddress||'—')+'</td><td>'+safe(r.callingstationid||'—')+'</td><td>'+safe(r.acctstarttime||'')+'</td><td>'+safe(r.acctstoptime||'')+'</td><td>'+safe(r.duration)+'</td><td><a href="?busca='+encodeURIComponent(r.username)+'">&#128269;</a></td></tr>';});
                $body.html(html||'<tr><td colspan="9">Nenhum registro encontrado.</td></tr>'); $info.text(data.count+' registro(s) exibido(s).');
            }).fail(function(xhr){$info.text('Falha na busca (HTTP '+xhr.status+').');});
        },250);
    }
    $input.on('input',searchNow); $status.on('change',searchNow); $limit.on('change',searchNow); $form.on('submit',function(e){e.preventDefault();searchNow();});
});
</script>

<style>
.ipv6-panel {
    background:#0f172a;
    color:#e2e8f0;
    padding:20px;
    border-radius:10px;
}

.ipv6-panel table {
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

/* CABEÇALHO DA TABELA */
.ipv6-panel th {
    background:#1e293b;
    color:#f8fafc;
    padding:12px;
    font-weight:600;
    text-align:left;
}

.ipv6-panel td {
    padding:10px;
    border-bottom:1px solid #334155;
}

.ipv6-panel tr:hover {
    background:#17243a;
}

.ipv6-panel input {
    padding:8px;
    margin-right:10px;
}

.ipv6-panel button {
    padding:8px 12px;
    background:#22c55e;
    color:#fff;
    border:none;
}

.ipv6-panel a {
    color:#38bdf8;
    margin-left:10px;
}

.online { color:#22c55e; }
.offline { color:#ef4444; }
.ipv6-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.ipv6-config-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:36px;
    height:36px;
    border-radius:6px;
    background:#1e293b;
    color:#fff !important;
    text-decoration:none;
    margin-left:0 !important;
}
.ipv6-config {
    margin:14px 0 18px;
    padding:14px;
    background:#111c31;
    border:1px solid #334155;
    border-radius:8px;
}
.ipv6-config-row {
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
}
.ipv6-config label {
    margin-right:6px;
}
.ipv6-config select {
    padding:8px;
}
.ipv6-alert {
    margin:0 0 12px;
    padding:10px;
    background:#16351f;
    border:1px solid #22c55e;
    color:#dcfce7;
    border-radius:6px;
}
.ipv6-muted {
    color:#94a3b8;
    font-size:13px;
    margin-top:10px;
}
.content-wrapper{width:calc(100vw - 48px);max-width:1800px;margin-left:50%;transform:translateX(-50%)}.ipv6-nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0 18px}.ipv6-nav a{margin:0;padding:10px 14px;border-radius:7px;background:#1e293b;color:#e2e8f0;text-decoration:none;border:1px solid #26364d}.ipv6-nav a:nth-child(2){background:#223149}.ipv6-nav a:nth-child(3){background:#1b2b42}.ipv6-nav a:nth-child(4){background:#25344b}.ipv6-nav a.active{background:#2563eb;color:#fff;border-color:#3b82f6}.ipv6-coming{margin:12px 0;padding:16px;border:1px solid #334155;background:#111c31;border-radius:8px}.ipv6-filters{display:grid;grid-template-columns:90px minmax(250px,1fr) 150px minmax(190px,1fr) minmax(190px,1fr) auto;gap:9px;align-items:center;background:#111c31;border:1px solid #334155;padding:12px;border-radius:9px}.ipv6-filters input,.ipv6-filters select{width:100%;margin:0;box-sizing:border-box;border:1px solid #475569;background:#f8fafc;color:#0f172a}.ipv6-config{background:#111c31;border-color:#334155}.ipv6-muted{color:#94a3b8}.ipv6-config-btn{background:#1e293b;color:#e2e8f0!important}@media(max-width:900px){.content-wrapper{width:calc(100vw - 20px)}.ipv6-filters{grid-template-columns:1fr 1fr}.ipv6-filters button{width:100%}}
</style>

</head>

<body>

<?php include('../../topo.php'); ?>

<div class="container">

<nav class="breadcrumb">
<ul>
<li class="is-active">
</li>
</ul>
</nav>

<div class="content-wrapper">
<div class="ipv6-panel">

<div class="ipv6-head">
    <div>
        <h2>🌐 Painel IPv4 e IPv6</h2>
        <strong>Total online:</strong> <?=$totalOnline?>
    </div>
    <a class="ipv6-config-btn" href="?config=1" title="Configurar retencao">&#9881;</a>
</div>

<div class="ipv6-nav">
    <a class="active" href="ipv6.php">Painel e logs</a>
    <a href="mikrotik.php">Scripts MikroTik</a>
    <a href="cgnat.php">CGNAT</a>
    <a href="import.php">Importar mapeamento</a>
</div>
<?php if (($_GET['module'] ?? '') === 'cgnat'): ?><div class="ipv6-coming"><strong>Modulo CGNAT</strong><br>O gerador opcional e a correlacao por IP publico + porta + horario serao adicionados aqui. O historico IPv4/IPv6 continuara independente.</div><?php endif; ?>

<?php if ($configMessage): ?>
<div class="ipv6-alert"><?=$configMessage?></div>
<?php endif; ?>

<?php if ($showConfig): ?>
<div class="ipv6-config">
    <form method="POST">
        <div class="ipv6-config-row">
            <label for="retention_months">Retencao:</label>
            <select name="retention_months" id="retention_months">
                <option value="6" <?=($retentionMonths==6?'selected':'')?>>6 meses</option>
                <option value="12" <?=($retentionMonths==12?'selected':'')?>>1 ano</option>
                <option value="24" <?=($retentionMonths==24?'selected':'')?>>2 anos</option>
            </select>

            <label for="executar_limpeza">Executar limpeza agora:</label>
            <select name="executar_limpeza" id="executar_limpeza">
                <option value="nao">Nao</option>
                <option value="sim">Sim</option>
            </select>

            <button type="submit" name="salvar_retencao" value="1">Gravar</button>
            <a href="?">Fechar</a>
        </div>
        <div class="ipv6-muted">
            Registros no historico: <?=$historyStats['total']?>.
            Fora da retencao atual: <?=$historyStats['expired']?>.
            A limpeza mensal roda automaticamente uma vez por mes quando o addon for acessado ou receber atualizacao.
        </div>
    </form>
</div>
<?php endif; ?>

<form method="GET" class="ipv6-filters">
<select name="limit">
    <option value="30" <?=($limit==30?'selected':'')?>>30</option>
    <option value="50" <?=($limit==50?'selected':'')?>>50</option>
    <option value="100" <?=($limit==100?'selected':'')?>>100</option>
</select>

<input name="busca" value="<?=htmlspecialchars($_GET['busca'] ?? '',ENT_QUOTES,'ISO-8859-1')?>" placeholder="Usuario, IPv4, IPv6 ou MAC...">
<select name="status"><option value="">Todos status</option><option value="online" <?=($status==='online'?'selected':'')?>>Online</option><option value="offline" <?=($status==='offline'?'selected':'')?>>Offline</option></select>
<input type="datetime-local" name="inicio" value="<?=$inicio?>">
<input type="datetime-local" name="fim" value="<?=$fim?>">
<button>Buscar</button>
</form>
<div style="margin-top:10px"><a href="?">Limpar filtros</a><a href="?export=1">Exportar CSV</a></div>
<div id="live-search-info" class="ipv6-muted" aria-live="polite">Digite para filtrar automaticamente.</div>

<table>
<tr>
<th>Status</th>
<th>Usuário</th>
<th>IPv6</th>
<th>IPv4</th>
<th>MAC</th>
<th>Início</th>
<th>Fim</th>
<th>Duração</th>
<th>Ação</th>
</tr>
<tbody id="ipv6-results">

<?php
$sql = buildSQL($busca,$inicio,$fim,$modoResumo,$status)." ORDER BY r.radacctid DESC LIMIT $limit OFFSET $offset";
$res=$conn->query($sql);

while($r=$res->fetch_assoc()){
$status = $r['acctstoptime'] 
    ? "<span class='offline'>● Offline</span>"
    : "<span class='online'>● Online</span>";

echo "<tr>
<td>$status</td>
<td>{$r['username']}</td>
<td>".($r['ipv6'] ?: '—')."</td>
<td>{$r['framedipaddress']}</td>
<td>".($r['callingstationid'] ?: '—')."</td>
<td>{$r['acctstarttime']}</td>
<td>{$r['acctstoptime']}</td>
<td>".calcDuracao($r['acctstarttime'],$r['acctstoptime'])."</td>
<td><a href='?busca={$r['username']}'>🔍</a></td>
</tr>";
}
?>
</tbody>
</table>
<div style="margin-top:20px;">

<?php
// BOTÃO "ANTERIOR"
// Só aparece se não estiver na primeira página
if($page > 1):
?>
    <a href="?page=<?=$page-1?>&limit=<?=$limit?>">
        ⬅ Anterior
    </a>
<?php endif; ?>

<!-- Mostra número da página atual -->
<span style="margin:0 10px;">
    Página <?=$page?> de <?=$totalPaginas?>
</span>

<?php
// BOTÃO "PRÓXIMA"
// Só aparece se ainda tiver mais registros
if($page * $limit < $totalRegistros):
?>


<a href="?page=<?=$page+1?>&limit=<?=$limit?>&busca=<?=$busca?>&inicio=<?=$inicio?>&fim=<?=$fim?>">
Próxima ➡
</a>

<?php endif; ?>

</div>
</div>
</div>

<script src="live-search.js?v=20260722-2"></script>
<?php include('../../baixo.php'); ?>
<script src="../../menu.js.php"></script>
<?php include('../../rodape.php'); ?>

<script>
(function(){
var input=document.querySelector('input[name="busca"]'), status=document.querySelector('select[name="status"]'), limit=document.querySelector('select[name="limit"]'), body=document.getElementById('ipv6-results'), timer;
if(!input||!body)return;
function esc(v){var d=document.createElement('div');d.textContent=v==null?'':v;return d.innerHTML}
function load(){clearTimeout(timer);timer=setTimeout(function(){var p=new URLSearchParams({q:input.value,status:status.value,limit:limit.value});fetch('search.php?'+p.toString(),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(data){if(!data.rows)return;body.innerHTML=data.rows.map(function(r){return '<tr><td><span class="'+(r.online?'online':'offline')+'">&#9679; '+(r.online?'Online':'Offline')+'</span></td><td>'+esc(r.username)+'</td><td>'+esc(r.ipv6||'—')+'</td><td>'+esc(r.framedipaddress||'—')+'</td><td>'+esc(r.callingstationid||'—')+'</td><td>'+esc(r.acctstarttime||'')+'</td><td>'+esc(r.acctstoptime||'')+'</td><td>'+esc(r.duration)+'</td><td><a href="?busca='+encodeURIComponent(r.username)+'">&#128269;</a></td></tr>'}).join('')||'<tr><td colspan="9">Nenhum registro encontrado.</td></tr>'}).catch(function(){})},250)}
input.addEventListener('input',load);status.addEventListener('change',load);limit.addEventListener('change',load);
})();
</script>

</body>
</html>
