<?php

require_once __DIR__ . '/daily-rider-reset.php';

function zone_get_setting($conn, $key, $default = '')
{
    rider_reset_ensure_settings_table($conn);

    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? $row['setting_value'] : $default;
}

function zone_set_setting($conn, $key, $value)
{
    rider_reset_ensure_settings_table($conn);

    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function zone_load_settings($conn)
{
    $radius_km_raw = zone_get_setting($conn, 'zone_radius_km', '');

    if($radius_km_raw === '')
    {
        $radius_m_raw = zone_get_setting($conn, 'zone_radius_m', '');

        if($radius_m_raw !== '')
        {
            $radius_km = (float)$radius_m_raw / 1000;
        }
        else
        {
            $area_sqm = (float)zone_get_setting($conn, 'zone_area_sqm', '10000');
            $radius_km = sqrt(max($area_sqm, 1)) / 2 / 1000;
        }
    }
    else
    {
        $radius_km = (float)$radius_km_raw;
    }

    return [
        'center_lat' => (float)zone_get_setting($conn, 'zone_center_lat', '11.1714'),
        'center_lng' => (float)zone_get_setting($conn, 'zone_center_lng', '123.7486'),
        'radius_km'  => $radius_km,
    ];
}
