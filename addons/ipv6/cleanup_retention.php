<?php

require_once __DIR__ . '/retention.lib.php';

$conn = new mysqli("127.0.0.1", "root", "vertrigo", "mkradius");
if ($conn->connect_errno) {
    fwrite(STDERR, "Erro ao conectar no banco: " . $conn->connect_error . PHP_EOL);
    exit(1);
}

$months = ipv6GetRetentionMonths($conn);
$deleted = ipv6CleanupHistory($conn, $months);

echo "Retencao: {$months} meses. Registros removidos: {$deleted}" . PHP_EOL;
