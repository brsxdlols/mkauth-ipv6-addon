<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado');
}
require_once __DIR__ . '/migrations.lib.php';
$conn = new mysqli('127.0.0.1', 'root', 'vertrigo', 'mkradius');
ipv6RunMigrations($conn);

if (ipv6ColumnExists($conn, 'sis_cliente', 'pool6')) {
    $conn->query("
        UPDATE radacct r
        JOIN sis_cliente c ON c.login=r.username
        SET r.delegatedipv6prefix=TRIM(c.pool6),
            r.ipv6_script=TRIM(c.pool6)
        WHERE r.acctstoptime IS NULL
          AND c.pool6 IS NOT NULL
          AND TRIM(c.pool6)<>''
          AND LOWER(TRIM(c.pool6))<>'nenhum'
    ");
    $fixedUpdated = $conn->affected_rows;

    $conn->query("
        UPDATE ipv6_history h
        JOIN radacct r ON r.acctsessionid=h.session_id AND r.acctstoptime IS NULL
        JOIN sis_cliente c ON c.login=r.username
        SET h.ipv6=TRIM(c.pool6),
            h.framedipaddress=r.framedipaddress,
            h.callingstationid=r.callingstationid
        WHERE h.ended_at IS NULL
          AND c.pool6 IS NOT NULL
          AND TRIM(c.pool6)<>''
          AND LOWER(TRIM(c.pool6))<>'nenhum'
    ");
    echo "PD fixo sincronizado em {$fixedUpdated} sessao(oes) ativa(s).\n";
}

echo "Banco do addon IPv6 verificado com sucesso.\n";
