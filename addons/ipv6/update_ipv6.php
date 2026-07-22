<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/retention.lib.php';
require_once __DIR__ . '/migrations.lib.php';

// 🔒 BLOQUEIA acesso via navegador (GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    die("Acesso negado");
}

// 📡 CONEXÃO
$conn = new mysqli("127.0.0.1","root","vertrigo","mkradius");
ipv6RunMigrations($conn);
$TOKEN = ipv6GetSetting($conn, 'api_token', '');
ipv6RunMonthlyCleanupIfDue($conn);

// 📥 DADOS
$token  = $_POST['token'] ?? '';
$user   = trim($_POST['user'] ?? '');
$ip     = $_POST['ip'] ?? '';
$prefix = $_POST['prefix'] ?? '';

// 🔒 VALIDA TOKEN
if ($token !== $TOKEN) {
    http_response_code(403);
    die("Token inválido");
}

// 🔒 VALIDA DADOS
if (!$user || !$ip) {
    http_response_code(400);
    die("Dados inválidos");
}
# ===== BUSCA SESSÃO REAL (CORRIGIDO) =====
$stmt = $conn->prepare("
SELECT acctsessionid, callingstationid, delegatedipv6prefix
FROM radacct
WHERE username=? 
AND acctstoptime IS NULL
ORDER BY radacctid DESC
LIMIT 1
");
$stmt->bind_param("s",$user);
$stmt->execute();
$stmt->bind_result($session,$mac,$ipv6_db);

if(!$stmt->fetch()){
    $session="SEM_SESSAO";
    $mac="";
    $ipv6_db="";
}
$stmt->close();

# ===== DEFINE IPv6 (PRIORIDADE BANCO) =====
$ipv6 = $ipv6_db;

if(!$ipv6){
    $ipv6 = $_POST['ipv6'] ?? '';
}

# 🔵 NOVO: usa prefixo se não tiver IPv6
if(!$ipv6 && $prefix){
    $ipv6 = $prefix;
}

if(!$ipv6){
    die("SEM_IPV6");
}

# ===== ATUALIZA RADACCT =====
if($session!="SEM_SESSAO"){
    $u=$conn->prepare("
    UPDATE radacct 
    SET delegatedipv6prefix=?, ipv6_script=? 
    WHERE acctsessionid=?
    ");
    $u->bind_param("sss",$ipv6,$ipv6,$session);
    $u->execute();
}

# ===== EVITA DUPLICAR SESSÃO ATIVA =====
$check=$conn->prepare("
SELECT id FROM ipv6_history 
WHERE session_id=? AND ended_at IS NULL
LIMIT 1
");
$check->bind_param("s",$session);
$check->execute();
$check->store_result();

if($check->num_rows==0){

    $ins=$conn->prepare("
    INSERT INTO ipv6_history 
    (username,ipv6,session_id,framedipaddress,callingstationid)
    VALUES (?,?,?,?,?)
    ");
    $ins->bind_param("sssss",$user,$ipv6,$session,$ip,$mac);
    $ins->execute();
}

echo "OK";
