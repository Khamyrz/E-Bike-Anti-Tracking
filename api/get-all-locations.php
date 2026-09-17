<?php
/**
 * Get All Rider Locations for Map Display
 * UPDATED: Location preserved kahit offline (Gray marker)
 */

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";
require_once __DIR__ . "/../includes/geofence.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

function ensureRequiredColumns($conn) {
    $columns = [
        ['gps_logs', 'vibration', "ALTER TABLE gps_logs ADD COLUMN vibration TINYINT(1) NULL DEFAULT 0"],
        ['gps_logs', 'battery', "ALTER TABLE gps_logs ADD COLUMN battery VARCHAR(10) NULL DEFAULT '100'"],
        ['gps_logs', 'satellites', "ALTER TABLE gps_logs ADD COLUMN satellites INT NULL DEFAULT 0"],
        ['users', 'is_online', "ALTER TABLE users ADD COLUMN is_online TINYINT(1) DEFAULT 0"],
        ['users', 'last_online_at', "ALTER TABLE users ADD COLUMN last_online_at DATETIME NULL"],
        ['users', 'online_source', "ALTER TABLE users ADD COLUMN online_source VARCHAR(20) NULL"]
    ];
    
    foreach ($columns as $col) {
        $check = $conn->query("SHOW COLUMNS FROM {$col[0]} LIKE '{$col[1]}'");
        if ($check && $check->num_rows === 0) {
            try { $conn->query($col[2]); } catch (Exception $e) {}
        }
    }
}

ensureRequiredColumns($conn);
rider_online_ensure_columns($conn);
rider_auto_offline_cleanup($conn);

$query = "
    SELECT
        u.id, u.fullname, u.ebike_id, u.is_online, u.last_online_at, u.online_source,
        g.latitude, g.longitude, g.battery, g.speed, g.vibration, g.satellites,
        g.created_at AS last_signal,
        TIMESTAMPDIFF(SECOND, g.created_at, NOW()) AS signal_age_seconds
    FROM users u
    LEFT JOIN (
        SELECT g2.rider_id, g2.latitude, g2.longitude, g2.battery, 
               g2.speed, g2.vibration, g2.satellites, g2.created_at
        FROM gps_logs g2
        INNER JOIN (
            SELECT rider_id, MAX(id) as max_id FROM gps_logs GROUP BY rider_id
        ) latest ON g2.id = latest.max_id
    ) g ON g.rider_id = u.id
    WHERE u.role = 'rider' AND u.status = 'approved'
    ORDER BY u.is_online DESC, u.fullname ASC
";

$result = $conn->query($query);

if (!$result) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database query failed"]);
    exit();
}

$rows = [];

while ($row = $result->fetch_assoc()) {
    // ✅ 60 SECONDS THRESHOLD para sa online status
    $signalAge = $row['signal_age_seconds'] ?? 999;
    $hasFreshSignal = $signalAge < 60;
    
    // ✅ Online lang kung may fresh signal
    $online = rider_is_currently_online($row) && $hasFreshSignal;
    
    // ✅ Parse GPS data - LAGING kunin ang coordinates
    $lat = $row['latitude'] !== null ? (float)$row['latitude'] : null;
    $lng = $row['longitude'] !== null ? (float)$row['longitude'] : null;
    
    // ✅ MAY COORDINATES kung may data sa database (kahit luma)
    $hasCoordinates = ($lat !== null && $lng !== null && $lat != 0 && $lng != 0);
    
    // ✅ IPAKITA ANG LOCATION KAHIT OFFLINE
    // Ito ang pinakamahalagang pagbabago:
    // Dati: $hasLocation = $hasCoordinates && $hasFreshSignal (nawawala kapag offline)
    // Ngayon: $hasLocation = $hasCoordinates (laging nandyan kung may data)
    $hasLocation = $hasCoordinates;
    
    // ✅ Geofence lang kung fresh at may coordinates
    $geofence = ['active' => false, 'status' => 'none', 'inside' => true];
    if ($hasFreshSignal && $hasCoordinates) {
        try { 
            $geofence = geofence_evaluate($conn, $lat, $lng); 
        } catch (Exception $e) {}
    }

    $rows[] = [
        'id'                  => (int)$row['id'],
        'fullname'            => $row['fullname'],
        'ebike_id'            => $row['ebike_id'],
        'is_online'           => $online,
        'online_source'       => $online ? ($row['online_source'] ?? null) : null,
        'last_online_at'      => $row['last_online_at'],
        'battery'             => $row['battery'] !== null ? (string)$row['battery'] : null,
        'last_signal'         => $row['last_signal'],
        'signal_age_seconds'  => (int)$signalAge,
        'is_fresh'            => $hasFreshSignal,
        'status_label'        => $online ? 'Online (GPS)' : 'Offline',
        
        // ✅ GPS Data - LAGING ibigay kung may coordinates (kahit offline)
        'lat'                 => $hasCoordinates ? $lat : null,
        'lng'                 => $hasCoordinates ? $lng : null,
        'speed'               => $row['speed'] !== null ? (float)$row['speed'] : 0,
        'vibration'           => isset($row['vibration']) ? (bool)$row['vibration'] : false,
        'satellites'          => isset($row['satellites']) ? (int)$row['satellites'] : 0,
        'has_location'        => $hasCoordinates,  // ✅ True kahit offline
        'location_source'     => $hasCoordinates ? 'esp32' : null,
        'geofence'            => $geofence
    ];
}

echo json_encode($rows, JSON_PRETTY_PRINT);