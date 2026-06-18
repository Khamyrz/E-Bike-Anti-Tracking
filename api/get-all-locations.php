<?php

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";

header('Content-Type: application/json');

rider_online_ensure_columns($conn);

$query = "
SELECT
    u.id,
    u.fullname,
    u.ebike_id,
    u.is_online,
    u.last_online_at,
    u.online_source,
    g.battery,
    g.created_at AS last_signal
FROM users u
LEFT JOIN gps_logs g
    ON g.id = (
        SELECT g2.id
        FROM gps_logs g2
        WHERE g2.rider_id = u.id
        ORDER BY g2.created_at DESC, g2.id DESC
        LIMIT 1
    )
WHERE u.role = 'rider'
  AND u.status = 'approved'
ORDER BY u.fullname ASC
";

$result = $conn->query($query);
$rows = [];

while ($row = $result->fetch_assoc()) {
    $online = rider_is_currently_online($row);
    $rows[] = [
        'id'             => (int)$row['id'],
        'fullname'       => $row['fullname'],
        'ebike_id'       => $row['ebike_id'],
        'is_online'      => $online,
        'online_source'  => $online ? ($row['online_source'] ?? null) : null,
        'last_online_at' => $row['last_online_at'],
        'battery'        => $row['battery'],
        'last_signal'    => $row['last_signal'],
        'status_label'   => rider_online_status_label(array_merge($row, ['is_online' => $online ? 1 : 0])),
    ];
}

echo json_encode($rows);
