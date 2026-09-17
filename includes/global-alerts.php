<?php
// includes/global-alerts.php
// Global alert system para sa lahat ng pages

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['admin'])) {
    return;
}

// Function para mag-check ng perimeter alerts
function getPerimeterAlerts($conn) {
    $map_alerts = [];
    $map_alert_count = 0;
    $perimeter_points = [];
    
    // Check kung may zone_settings table
    $check_table = $conn->query("SHOW TABLES LIKE 'zone_settings'");
    if ($check_table && $check_table->num_rows > 0) {
        $perimeter_query = $conn->query("SELECT setting_value FROM zone_settings WHERE setting_key = 'perimeter_points'");
        if ($perimeter_query !== false && $perimeter_query->num_rows > 0) {
            $row = $perimeter_query->fetch_assoc();
            if ($row && isset($row['setting_value']) && !empty($row['setting_value'])) {
                $perimeter_data = json_decode($row['setting_value'], true);
                if (is_array($perimeter_data) && count($perimeter_data) >= 3) {
                    $perimeter_points = $perimeter_data;
                }
            }
        }
    }
    
    // Check rider locations if perimeter exists
    if (count($perimeter_points) >= 3) {
        // Check kung may user_locations table
        $check_locations_table = $conn->query("SHOW TABLES LIKE 'user_locations'");
        
        if ($check_locations_table && $check_locations_table->num_rows > 0) {
            $rider_locations = $conn->query("
                SELECT u.id, u.fullname, u.ebike_id, ul.latitude, ul.longitude, ul.updated_at,
                       TIMESTAMPDIFF(SECOND, ul.updated_at, NOW()) as signal_age
                FROM users u
                LEFT JOIN user_locations ul ON u.id = ul.user_id
                WHERE u.role = 'rider' 
                AND u.status = 'approved'
                AND ul.latitude IS NOT NULL 
                AND ul.longitude IS NOT NULL
                AND ul.latitude != 0 
                AND ul.longitude != 0
            ");
        } else {
            // Alternative: baka nasa users table mismo ang location
            $rider_locations = $conn->query("
                SELECT id, fullname, ebike_id, latitude, longitude, updated_at,
                       TIMESTAMPDIFF(SECOND, updated_at, NOW()) as signal_age
                FROM users
                WHERE role = 'rider' 
                AND status = 'approved'
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                AND latitude != 0 
                AND longitude != 0
            ");
        }
        
        if ($rider_locations !== false && $rider_locations->num_rows > 0) {
            while ($location = $rider_locations->fetch_assoc()) {
                $lat = (float)$location['latitude'];
                $lng = (float)$location['longitude'];
                
                // Point-in-polygon check
                $is_inside = false;
                $points_count = count($perimeter_points);
                
                for ($i = 0, $j = $points_count - 1; $i < $points_count; $j = $i++) {
                    $lat_i = (float)$perimeter_points[$i]['lat'];
                    $lng_i = (float)$perimeter_points[$i]['lng'];
                    $lat_j = (float)$perimeter_points[$j]['lat'];
                    $lng_j = (float)$perimeter_points[$j]['lng'];
                    
                    if (($lng_i > $lng) != ($lng_j > $lng) &&
                        ($lat < ($lat_j - $lat_i) * ($lng - $lng_i) / ($lng_j - $lng_i) + $lat_i)) {
                        $is_inside = !$is_inside;
                    }
                }
                
                if (!$is_inside) {
                    $is_online = isset($location['signal_age']) ? $location['signal_age'] < 60 : false;
                    $map_alerts[] = [
                        'rider_id' => $location['id'],
                        'rider_name' => $location['fullname'],
                        'ebike_id' => $location['ebike_id'],
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'updated_at' => $location['updated_at'] ?? date('Y-m-d H:i:s'),
                        'is_online' => $is_online,
                        'signal_age' => $location['signal_age'] ?? 999
                    ];
                    $map_alert_count++;
                }
            }
        }
    }
    
    return [
        'alerts' => $map_alerts,
        'count' => $map_alert_count
    ];
}

// Function para mag-check ng rental alerts
function getRentalAlerts($conn, $rentalTimer) {
    $stolen_vehicles = $rentalTimer->getStolenRentals();
    $stolen_count = $stolen_vehicles->num_rows;
    
    $grace_count = 0;
    $grace_rentals = [];
    
    $active_rentals = $conn->query("
        SELECT rs.*, u.fullname, u.email, u.phone
        FROM rental_sessions rs
        JOIN users u ON u.id = rs.rider_id
        WHERE rs.status = 'active'
        ORDER BY rs.end_time ASC
    ");
    
    if ($active_rentals && $active_rentals->num_rows > 0) {
        while ($rental = $active_rentals->fetch_assoc()) {
            $status = $rentalTimer->getRentalStatus($rental['rider_id']);
            if ($status && $status['is_grace_period']) {
                $rental['timer'] = $status;
                $grace_rentals[] = $rental;
                $grace_count++;
            }
        }
    }
    
    return [
        'stolen_count' => $stolen_count,
        'stolen_vehicles' => $stolen_vehicles,
        'grace_count' => $grace_count,
        'grace_rentals' => $grace_rentals
    ];
}
?>