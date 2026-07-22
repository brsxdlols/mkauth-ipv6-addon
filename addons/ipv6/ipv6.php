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
$page   = max(1, intval($_GET['page'] ?? 1));

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
if(!in_array($limit, [30,50,100])) $limit = 30;

$offset = ($page-1)*$limit;

$modoResumo = empty($busca) && empty($inicio) && empty($fim);

// ===== SQL =====
function buildSQL($busca,$inicio,$fim,$modoResumo){

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
    background:#1e293b;   /* fundo escuro */
    color:#ffffff;        /* 🔥 texto branco */
    padding:12px;
    font-weight:600;
    text-align:left;
}

.ipv6-panel td {
    padding:10px;
    border-bottom:1px solid #334155;
}

.ipv6-panel tr:hover {
    background:#1e293b;
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

<form method="GET">
<select name="limit">
    <option value="30" <?=($limit==30?'selected':'')?>>30</option>
    <option value="50" <?=($limit==50?'selected':'')?>>50</option>
    <option value="100" <?=($limit==100?'selected':'')?>>100</option>
</select>

<input name="busca" placeholder="Buscar usuário, IPv4 ou IPv6..." style="width:250px;">
<input type="datetime-local" name="inicio" value="<?=$inicio?>">
<input type="datetime-local" name="fim" value="<?=$fim?>">
<button>Buscar</button>
<a href="?">Limpar</a>
<a href="?export=1">CSV</a>
</form>

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

<?php
$sql = buildSQL($busca,$inicio,$fim,$modoResumo)." ORDER BY r.radacctid DESC LIMIT $limit OFFSET $offset";
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

<?php include('../../baixo.php'); ?>
<script src="../../menu.js.php"></script>
<?php include('../../rodape.php'); ?>

</body>
</html>
