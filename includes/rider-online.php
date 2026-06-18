<?php

/**
 * Rider online status — driven by facial login and SIM800L heartbeats.
 */

define('RIDER_ONLINE_STALE_SECONDS', 180);

function rider_online_ensure_columns($conn)
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'is_online'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'last_online_at' => "DATETIME NULL DEFAULT NULL",
        'online_source'  => "VARCHAR(16) NULL DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN `$column` $definition");
        }
    }

    $done = true;
}

function rider_set_online($conn, $rider_id, $source)
{
    rider_online_ensure_columns($conn);

    $rider_id = (int)$rider_id;
    $source   = in_array($source, ['login', 'sim800l', 'session'], true) ? $source : 'session';

    $stmt = $conn->prepare("
        UPDATE users
        SET is_online = 1,
            last_online_at = NOW(),
            online_source = ?
        WHERE id = ?
          AND role = 'rider'
          AND status = 'approved'
    ");
    $stmt->bind_param('si', $source, $rider_id);
    $stmt->execute();
    $stmt->close();
}

function rider_set_offline($conn, $rider_id)
{
    rider_online_ensure_columns($conn);

    $rider_id = (int)$rider_id;
    $stmt = $conn->prepare("
        UPDATE users
        SET is_online = 0,
            online_source = NULL
        WHERE id = ?
          AND role = 'rider'
    ");
    $stmt->bind_param('i', $rider_id);
    $stmt->execute();
    $stmt->close();
}

function rider_is_currently_online($row)
{
    if (empty($row['is_online']) || empty($row['last_online_at'])) {
        return false;
    }

    $last = strtotime((string)$row['last_online_at']);
    if ($last === false) {
        return false;
    }

    return (time() - $last) <= RIDER_ONLINE_STALE_SECONDS;
}

function rider_online_status_label(array $row)
{
    if (!rider_is_currently_online($row)) {
        return 'Offline';
    }

    $source = $row['online_source'] ?? '';
    if ($source === 'sim800l') {
        return 'Online (SIM800L)';
    }
    if ($source === 'login') {
        return 'Online (Logged in)';
    }

    return 'Online';
}
