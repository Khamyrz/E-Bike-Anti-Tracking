<?php
/**
 * Geofence / Operational Perimeter Functions
 * Used for ESP32 GPS tracking and boundary enforcement
 */

require_once __DIR__ . '/zone-settings.php';

// Default warning distance in meters
define('GEOFENCE_WARNING_METERS', 150);

/**
 * Get perimeter points from database
 */
function geofence_get_perimeter($conn)
{
    $json = zone_get_setting($conn, 'perimeter_points', '');
    if ($json === '' || $json === null) {
        return [];
    }

    $points = json_decode($json, true);
    if (!is_array($points)) {
        return [];
    }
    
    // Validate point structure
    $validPoints = [];
    foreach ($points as $point) {
        if (isset($point['lat']) && isset($point['lng']) && 
            is_numeric($point['lat']) && is_numeric($point['lng'])) {
            $validPoints[] = [
                'lat' => (float)$point['lat'],
                'lng' => (float)$point['lng']
            ];
        }
    }
    
    return $validPoints;
}

/**
 * Check if a perimeter exists
 */
function geofence_has_perimeter($conn)
{
    $points = geofence_get_perimeter($conn);
    return count($points) >= 3;
}

/**
 * Calculate distance between two points using Haversine formula
 */
function geofence_haversine_m($lat1, $lng1, $lat2, $lng2)
{
    $earth = 6371000; // Earth's radius in meters
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth * $c;
}

/**
 * Check if a point is inside a polygon using ray casting algorithm
 */
function geofence_point_in_polygon($lat, $lng, array $points)
{
    $n = count($points);
    if ($n < 3) {
        return true; // No valid perimeter
    }

    $inside = false;
    $j = $n - 1;

    for ($i = 0; $i < $n; $i++) {
        $yi = (float)$points[$i]['lat'];
        $xi = (float)$points[$i]['lng'];
        $yj = (float)$points[$j]['lat'];
        $xj = (float)$points[$j]['lng'];

        if ((($yi > $lat) !== ($yj > $lat)) &&
            ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
            $inside = !$inside;
        }
        $j = $i;
    }

    return $inside;
}

/**
 * Calculate minimum distance from a point to polygon boundary
 */
function geofence_distance_to_boundary_m($lat, $lng, array $points)
{
    $count = count($points);
    if ($count < 2) {
        return null;
    }

    $min = null;

    for ($i = 0; $i < $count; $i++) {
        $a = $points[$i];
        $b = $points[($i + 1) % $count];
        
        $dist = geofence_distance_point_to_segment_m(
            $lat, $lng,
            (float)$a['lat'], (float)$a['lng'],
            (float)$b['lat'], (float)$b['lng']
        );

        if ($min === null || $dist < $min) {
            $min = $dist;
        }
    }

    return $min;
}

/**
 * Calculate distance from point to line segment
 */
function geofence_distance_point_to_segment_m($lat, $lng, $lat1, $lng1, $lat2, $lng2)
{
    $dx = $lng2 - $lng1;
    $dy = $lat2 - $lat1;

    // If segment is a point
    if ($dx == 0.0 && $dy == 0.0) {
        return geofence_haversine_m($lat, $lng, $lat1, $lng1);
    }

    // Calculate projection parameter
    $t = (($lng - $lng1) * $dx + ($lat - $lat1) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0, min(1, $t));

    // Projection point
    $projLat = $lat1 + $t * $dy;
    $projLng = $lng1 + $t * $dx;

    return geofence_haversine_m($lat, $lng, $projLat, $projLng);
}

/**
 * Evaluate geofence status for a given location
 */
function geofence_evaluate($conn, $lat, $lng)
{
    $points = geofence_get_perimeter($conn);

    // Default response when no perimeter exists
    if (count($points) < 3) {
        return [
            'active' => false,
            'status' => 'none',
            'inside' => true,
            'distance_to_boundary_m' => null,
            'warning_meters' => GEOFENCE_WARNING_METERS,
            'point_count' => 0
        ];
    }

    // Validate coordinates
    if (!is_numeric($lat) || !is_numeric($lng) || 
        $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return [
            'active' => true,
            'status' => 'invalid',
            'inside' => false,
            'distance_to_boundary_m' => null,
            'warning_meters' => GEOFENCE_WARNING_METERS,
            'point_count' => count($points)
        ];
    }

    $inside = geofence_point_in_polygon($lat, $lng, $points);
    $distance = geofence_distance_to_boundary_m($lat, $lng, $points);

    // Determine status
    if (!$inside) {
        $status = 'outside';
    } elseif ($distance !== null && $distance <= GEOFENCE_WARNING_METERS) {
        $status = 'warning';
    } else {
        $status = 'inside';
    }

    return [
        'active' => true,
        'status' => $status,
        'inside' => $inside,
        'distance_to_boundary_m' => $distance !== null ? round($distance, 1) : null,
        'warning_meters' => GEOFENCE_WARNING_METERS,
        'point_count' => count($points)
    ];
}

/**
 * Process location update with geofence checking and notifications
 */
function geofence_process_location($conn, $rider_id, $lat, $lng, $rider_name = '')
{
    $eval = geofence_evaluate($conn, $lat, $lng);
    
    if (!$eval['active'] || $eval['status'] === 'invalid') {
        return $eval;
    }

    // Track state changes
    $key = 'geofence_state_' . (int)$rider_id;
    $prev_json = zone_get_setting($conn, $key, '');
    $prev = $prev_json ? json_decode($prev_json, true) : null;
    $prev_status = is_array($prev) ? ($prev['status'] ?? 'inside') : 'inside';

    // Check if status changed
    if ($eval['status'] !== $prev_status) {
        $name = $rider_name !== '' ? $rider_name : ('Rider #' . $rider_id);

        // Log geofence events (you can add notification logic here)
        $log_message = date('Y-m-d H:i:s') . " - Rider: $name - Status: {$eval['status']} - Location: $lat, $lng";
        error_log("Geofence Event: $log_message");

        // Store current state
        zone_set_setting($conn, $key, json_encode([
            'status' => $eval['status'],
            'at' => date('Y-m-d H:i:s'),
            'lat' => $lat,
            'lng' => $lng
        ]));
    }

    return $eval;
}

/**
 * Get geofence status label
 */
function geofence_status_label($status)
{
    switch ($status) {
        case 'inside':
            return '✅ Inside Zone';
        case 'warning':
            return '⚠️ Near Boundary';
        case 'outside':
            return '🚫 Outside Zone';
        case 'none':
            return 'No Perimeter Set';
        default:
            return 'Unknown';
    }
}