<?php

/**
 * Legacy daily reset helpers — no longer runs automatically on page load.
 * Rider cleanup is controlled by the Auto-Delete Schedule in admin/riders.php.
 */

define('RIDER_RESET_TIMEZONE', 'Asia/Manila');
define('RIDER_RESET_HOUR', 23);
define('RIDER_RESET_MINUTE', 0);

function rider_reset_ensure_settings_table($conn)
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function rider_reset_get_last_run($conn)
{
    rider_reset_ensure_settings_table($conn);

    $stmt = $conn->prepare("
        SELECT setting_value FROM system_settings WHERE setting_key = 'last_rider_reset_at' LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(empty($row['setting_value']))
    {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['setting_value'], new DateTimeZone(RIDER_RESET_TIMEZONE));
    return $dt ?: null;
}

function rider_reset_set_last_run($conn, DateTime $when)
{
    rider_reset_ensure_settings_table($conn);

    $value = $when->format('Y-m-d H:i:s');
    $stmt  = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('last_rider_reset_at', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
}

function rider_reset_today_cutoff(DateTime $now)
{
    $cutoff = clone $now;
    $cutoff->setTime(RIDER_RESET_HOUR, RIDER_RESET_MINUTE, 0);
    return $cutoff;
}

function rider_reset_is_due(DateTime $now, $last_run)
{
    $cutoff = rider_reset_today_cutoff($now);

    if($now < $cutoff)
    {
        return false;
    }

    if($last_run === null)
    {
        return true;
    }

    return $last_run < $cutoff;
}

function run_daily_rider_reset($conn)
{
    $conn->query("DELETE FROM gps_logs");
    $conn->query("DELETE FROM users WHERE role = 'rider'");

    $now = new DateTime('now', new DateTimeZone(RIDER_RESET_TIMEZONE));
    rider_reset_set_last_run($conn, $now);

    return [
        'reset_at' => $now->format('Y-m-d H:i:s'),
        'message'  => 'All riders cleared. E-Bike IDs will restart at 001 for tomorrow.',
    ];
}

function maybe_run_daily_rider_reset($conn)
{
    return null;
}

function rider_reset_next_scheduled(DateTime $now = null)
{
    if($now === null)
    {
        $now = new DateTime('now', new DateTimeZone(RIDER_RESET_TIMEZONE));
    }

    $next = rider_reset_today_cutoff($now);

    if($now >= $next)
    {
        $next->modify('+1 day');
    }

    return $next;
}
