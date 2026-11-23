<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/retention.lib.php';
require_once __DIR__ . '/migrations.lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    die('Acesso negado');
}

$conn = new mysqli('127.0.0.1', 'root', 'vertrigo', 'mkradius');
ipv6RunMigrations($conn);
$expectedToken = ipv6GetSetting($conn, 'api_token', '');
ipv6RunMonthlyCleanupIfDue($conn);

$token = $_POST['token'] ?? '';
$user = trim($_POST['user'] ?? '');
$ip = trim($_POST['ip'] ?? '');
$postedPrefix = trim($_POST['prefix'] ?? '');
$postedIpv6 = trim($_POST['ipv6'] ?? '');

if (!hash_equals((string)$expectedToken, (string)$token)) {
    http_response_code(403);
    die('Token invalido');
}
if ($user === '' || $ip === '') {
    http_response_code(400);
    die('Dados invalidos');
}

$stmt = $conn->prepare("
SELECT acctsessionid, callingstationid, delegatedipv6prefix
FROM radacct
WHERE username=? AND acctstoptime IS NULL
ORDER BY radacctid DESC
LIMIT 1
");
$stmt->bind_param('s', $user);
$stmt->execute();
$stmt->bind_result($session, $mac, $radiusPrefix);
if (!$stmt->fetch()) {
    $session = 'SEM_SESSAO';
    $mac = '';
    $radiusPrefix = '';
}
$stmt->close();

// MK-Auth fixed PD takes precedence. Dynamic installations keep using MikroTik.
$fixedPrefix = '';
$fixed = $conn->prepare("SELECT pool6 FROM sis_cliente WHERE login=? LIMIT 1");
$fixed->bind_param('s', $user);
$fixed->execute();
$fixed->bind_result($fixedPrefix);
$fixed->fetch();
$fixed->close();
$fixedPrefix = trim((string)$fixedPrefix);

function ipv6IsDelegatedPrefix($value) {
    $parts = explode('/', trim((string)$value));
    if (count($parts) !== 2 || !preg_match('/^(0|[1-9][0-9]{0,2})$/D', $parts[1]) || (int)$parts[1] > 128) {
        return false;
    }
    return filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

if (ipv6IsDelegatedPrefix($fixedPrefix)) {
    $selectedPrefix = $fixedPrefix;
} elseif (ipv6IsDelegatedPrefix($postedPrefix)) {
    $selectedPrefix = $postedPrefix;
} elseif (ipv6IsDelegatedPrefix($radiusPrefix)) {
    $selectedPrefix = trim((string)$radiusPrefix);
} else {
    $selectedPrefix = '';
}

if ($selectedPrefix === '') {
    // Ausencia de IPv6 nao e falha de transporte ou autenticacao.
    http_response_code(200);
    die('SEM_IPV6');
}

if ($session !== 'SEM_SESSAO') {
    $update = $conn->prepare("
    UPDATE radacct
    SET delegatedipv6prefix=?, ipv6_script=?
    WHERE acctsessionid=?
    ");
    $update->bind_param('sss', $selectedPrefix, $selectedPrefix, $session);
    $update->execute();
    $update->close();
}

$check = $conn->prepare("
SELECT id
FROM ipv6_history
WHERE session_id=? AND ended_at IS NULL
LIMIT 1
");
$check->bind_param('s', $session);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $insert = $conn->prepare("
    INSERT INTO ipv6_history
    (username,ipv6,session_id,framedipaddress,callingstationid)
    VALUES (?,?,?,?,?)
    ");
    $insert->bind_param('sssss', $user, $selectedPrefix, $session, $ip, $mac);
    $insert->execute();
    $insert->close();
} else {
    $updateHistory = $conn->prepare("
    UPDATE ipv6_history
    SET ipv6=?, framedipaddress=?, callingstationid=?
    WHERE session_id=? AND ended_at IS NULL
    ");
    $updateHistory->bind_param('ssss', $selectedPrefix, $ip, $mac, $session);
    $updateHistory->execute();
    $updateHistory->close();
}
$check->close();

echo 'OK';

