<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado');
}
require_once __DIR__ . '/migrations.lib.php';
$conn = new mysqli('127.0.0.1', 'root', 'vertrigo', 'mkradius');
ipv6RunMigrations($conn);
echo "Banco do addon IPv6 verificado com sucesso.\n";

