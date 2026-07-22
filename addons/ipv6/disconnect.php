<?php
require_once __DIR__ . '/migrations.lib.php';
require_once __DIR__ . '/retention.lib.php';
$conn = new mysqli("127.0.0.1","root","vertrigo","mkradius");
ipv6RunMigrations($conn);

$user = $_GET['user'] ?? '';
$token = $_GET['token'] ?? '';
$expectedToken = ipv6GetSetting($conn, 'api_token', '');
$allowLegacy = ipv6GetSetting($conn, 'allow_legacy_disconnect', '1') === '1';
if ((!$token && !$allowLegacy) || ($token && (!$expectedToken || !hash_equals($expectedToken, $token)))) {
    http_response_code(403);
    exit('Token invalido');
}
if(!$user) {
    http_response_code(400);
    exit('Usuario ausente');
}

# ===== pega sessão ativa real =====
$stmt = $conn->prepare("
SELECT acctsessionid 
FROM radacct
WHERE username=? 
AND acctstoptime IS NULL
ORDER BY radacctid DESC
LIMIT 1
");
$stmt->bind_param("s",$user);
$stmt->execute();
$stmt->bind_result($session);
$stmt->fetch();
$stmt->close();

# ===== se encontrou sessão =====
if($session){

    $upd = $conn->prepare("
    UPDATE ipv6_history 
    SET ended_at=NOW()
    WHERE session_id=? AND ended_at IS NULL
    ");
    $upd->bind_param("s",$session);
    $upd->execute();

}

echo "OK";
