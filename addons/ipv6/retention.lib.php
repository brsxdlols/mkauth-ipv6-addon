<?php

function ipv6EnsureSettingsTable($conn)
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS ipv6_settings (
            name varchar(50) NOT NULL,
            value varchar(255) DEFAULT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1
    ");
}

function ipv6GetRetentionMonths($conn)
{
    ipv6EnsureSettingsTable($conn);

    $months = 12;
    $res = $conn->query("SELECT value FROM ipv6_settings WHERE name='retention_months' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $months = (int)$row['value'];
    }

    return in_array($months, array(6, 12, 24), true) ? $months : 12;
}

function ipv6SaveRetentionMonths($conn, $months)
{
    ipv6EnsureSettingsTable($conn);

    $months = (int)$months;
    if (!in_array($months, array(6, 12, 24), true)) {
        $months = 12;
    }

    $stmt = $conn->prepare("
        REPLACE INTO ipv6_settings (name, value)
        VALUES ('retention_months', ?)
    ");
    $value = (string)$months;
    $stmt->bind_param("s", $value);
    $stmt->execute();

    return $months;
}

function ipv6GetSetting($conn, $name, $default = '')
{
    ipv6EnsureSettingsTable($conn);

    $stmt = $conn->prepare("SELECT value FROM ipv6_settings WHERE name=? LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $stmt->bind_result($value);

    if ($stmt->fetch()) {
        $stmt->close();
        return $value;
    }

    $stmt->close();
    return $default;
}

function ipv6SaveSetting($conn, $name, $value)
{
    ipv6EnsureSettingsTable($conn);

    $stmt = $conn->prepare("
        REPLACE INTO ipv6_settings (name, value)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ss", $name, $value);
    $stmt->execute();
    $stmt->close();
}

function ipv6CleanupHistory($conn, $months)
{
    $months = (int)$months;
    if (!in_array($months, array(6, 12, 24), true)) {
        $months = 12;
    }

    $totalDeleted = 0;

    do {
        $stmt = $conn->prepare("
            DELETE FROM ipv6_history
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)
            LIMIT 5000
        ");
        $stmt->bind_param("i", $months);
        $stmt->execute();
        $deleted = max(0, (int)$stmt->affected_rows);
        $stmt->close();
        $totalDeleted += $deleted;
    } while ($deleted === 5000);

    return $totalDeleted;
}

function ipv6RunMonthlyCleanupIfDue($conn)
{
    $currentMonth = date('Y-m');
    $lastMonth = ipv6GetSetting($conn, 'last_cleanup_month', '');

    if ($lastMonth === $currentMonth) {
        return array('ran' => false, 'deleted' => 0);
    }

    $months = ipv6GetRetentionMonths($conn);
    $deleted = ipv6CleanupHistory($conn, $months);

    ipv6SaveSetting($conn, 'last_cleanup_month', $currentMonth);
    ipv6SaveSetting($conn, 'last_cleanup_at', date('Y-m-d H:i:s'));

    return array('ran' => true, 'deleted' => $deleted);
}

function ipv6HistoryStats($conn, $months)
{
    $months = (int)$months;
    if (!in_array($months, array(6, 12, 24), true)) {
        $months = 12;
    }

    $stats = array('total' => 0, 'expired' => 0);

    $res = $conn->query("SELECT COUNT(*) t FROM ipv6_history");
    if ($res && $row = $res->fetch_assoc()) {
        $stats['total'] = (int)$row['t'];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) t
        FROM ipv6_history
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)
    ");
    $stmt->bind_param("i", $months);
    $stmt->execute();
    $stmt->bind_result($expired);
    if ($stmt->fetch()) {
        $stats['expired'] = (int)$expired;
    }
    $stmt->close();

    return $stats;
}

function ipv6InstallMonthlyCron()
{
    if (!function_exists('shell_exec')) {
        return 'Nao foi possivel configurar o cron: shell_exec esta desabilitado.';
    }

    $script = __DIR__ . '/cleanup_retention.php';
    $php = trim((string)@shell_exec('command -v php 2>/dev/null'));
    if (!$php) {
        $php = PHP_BINARY ? PHP_BINARY : '/usr/bin/php';
    }

    $marker = '# ipv6_history_retention';
    $line = '0 3 1 * * ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 ' . $marker;
    $current = (string)@shell_exec('crontab -l 2>/dev/null');
    $lines = preg_split('/\r\n|\r|\n/', $current);
    $newLines = array();

    foreach ($lines as $item) {
        if (trim($item) === '' || strpos($item, $marker) !== false) {
            continue;
        }
        $newLines[] = $item;
    }

    $newLines[] = $line;
    $tmp = tempnam(sys_get_temp_dir(), 'ipv6_cron_');
    file_put_contents($tmp, implode(PHP_EOL, $newLines) . PHP_EOL);
    $out = (string)@shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    if (trim($out) !== '') {
        return 'Cron nao confirmado automaticamente: ' . trim($out);
    }

    return 'Cron mensal configurado para todo dia 01 as 03:00.';
}
