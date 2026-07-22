<?php

function ipv6ColumnExists($conn, $table, $column)
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count > 0;
}

function ipv6RunMigrations($conn)
{
    if (!$conn || $conn->connect_errno) {
        throw new RuntimeException('Nao foi possivel conectar ao banco do MK-Auth.');
    }

    $conn->set_charset('latin1');

    if (!ipv6ColumnExists($conn, 'radacct', 'delegatedipv6prefix')) {
        if (!$conn->query("ALTER TABLE radacct ADD COLUMN delegatedipv6prefix varchar(150) DEFAULT NULL")) {
            throw new RuntimeException('Falha ao criar radacct.delegatedipv6prefix: ' . $conn->error);
        }
    }
    if (!ipv6ColumnExists($conn, 'radacct', 'ipv6_script')) {
        if (!$conn->query("ALTER TABLE radacct ADD COLUMN ipv6_script varchar(150) DEFAULT NULL")) {
            throw new RuntimeException('Falha ao criar radacct.ipv6_script: ' . $conn->error);
        }
    }

    $queries = array(
        "CREATE TABLE IF NOT EXISTS ipv6_history (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(100) DEFAULT NULL,
            ipv6 varchar(150) DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            framedipaddress varchar(50) DEFAULT NULL,
            callingstationid varchar(50) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            ended_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ipv6_session (session_id),
            KEY idx_created (created_at),
            KEY idx_ipv6_user_created (username, created_at),
            KEY idx_ipv6_private_ip_created (framedipaddress, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        "CREATE TABLE IF NOT EXISTS ipv6_settings (
            name varchar(50) NOT NULL,
            value varchar(255) DEFAULT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1",
        "CREATE TABLE IF NOT EXISTS ipv6_schema_migrations (
            version int(11) NOT NULL,
            applied_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
    );

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Falha na instalacao automatica do banco: ' . $conn->error);
        }
    }

    $conn->query("INSERT IGNORE INTO ipv6_schema_migrations (version) VALUES (1)");
    $token = bin2hex(random_bytes(24));
    $stmt = $conn->prepare("INSERT IGNORE INTO ipv6_settings (name, value) VALUES ('api_token', ?)");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
}

