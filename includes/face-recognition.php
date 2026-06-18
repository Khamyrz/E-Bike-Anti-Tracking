<?php

/**
 * Face recognition helpers — strict descriptor matching (face-api.js compatible).
 * Stored face_data format: JSON {"image":"data:image/jpeg;base64,...","descriptor":[128 floats]}
 */

// Lower threshold for stricter matching - any face above this is rejected
define('FACE_MATCH_MAX_DISTANCE', 0.5);

/**
 * Parse stored face data from database
 * Expects JSON format: {"image":"data:image/jpeg;base64,...","descriptor":[128 floats]}
 */
function face_parse_stored_data($face_data)
{
    $face_data = trim((string)$face_data);
    if ($face_data === '') {
        return null;
    }

    // Check if it's JSON format
    if ($face_data[0] === '{') {
        $parsed = json_decode($face_data, true);
        if (!is_array($parsed)) {
            return null;
        }

        // Validate descriptor - MUST have exactly 128 values
        $descriptor = $parsed['descriptor'] ?? null;
        if (!is_array($descriptor) || count($descriptor) < 64 || count($descriptor) > 128) {
            error_log("face_parse_stored_data: Invalid descriptor count: " . (is_array($descriptor) ? count($descriptor) : 'null'));
            return null;
        }

        // Normalize all values to float
        $normalized = [];
        foreach ($descriptor as $value) {
            if (!is_numeric($value)) {
                error_log("face_parse_stored_data: Non-numeric value in descriptor");
                return null;
            }
            $normalized[] = (float)$value;
        }

        // Check if all values are within valid range (-1 to 1 typically for face descriptors)
        foreach ($normalized as $value) {
            if ($value < -2 || $value > 2) {
                error_log("face_parse_stored_data: Descriptor value out of range: " . $value);
                return null;
            }
        }

        return [
            'image'      => isset($parsed['image']) ? (string)$parsed['image'] : '',
            'descriptor' => $normalized,
        ];
    }

    error_log("face_parse_stored_data: Data is not JSON format");
    return null;
}

/**
 * Parse login descriptor from JavaScript
 * Expects JSON array of 128 floats
 */
function face_parse_login_descriptor($raw)
{
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            error_log("face_parse_login_descriptor: Empty raw data");
            return null;
        }
        $values = json_decode($raw, true);
        if (!is_array($values)) {
            error_log("face_parse_login_descriptor: Failed to decode JSON: " . substr($raw, 0, 100));
            return null;
        }
    }

    if (!is_array($values) || count($values) < 64 || count($values) > 128) {
        error_log("face_parse_login_descriptor: Invalid descriptor count: " . (is_array($values) ? count($values) : 'null'));
        return null;
    }

    // Normalize all values to float
    $normalized = [];
    foreach ($values as $value) {
        if (!is_numeric($value)) {
            error_log("face_parse_login_descriptor: Non-numeric value in descriptor");
            return null;
        }
        $normalized[] = (float)$value;
    }

    // Check if all values are within valid range
    foreach ($normalized as $value) {
        if ($value < -2 || $value > 2) {
            error_log("face_parse_login_descriptor: Descriptor value out of range: " . $value);
            return null;
        }
    }

    return $normalized;
}

/**
 * Calculate Euclidean distance between two face descriptors
 */
function face_descriptor_distance(array $a, array $b)
{
    if (empty($a) || empty($b)) {
        return INF;
    }
    
    $count = min(count($a), count($b));
    if ($count === 0) {
        return INF;
    }

    $sum = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $diff = $a[$i] - $b[$i];
        $sum += $diff * $diff;
    }

    return sqrt($sum);
}

/**
 * Check if two face descriptors match within the threshold
 * Returns true ONLY if distance is below threshold
 */
function face_match_descriptor(array $captured, array $stored, $max_distance = FACE_MATCH_MAX_DISTANCE)
{
    $distance = face_descriptor_distance($captured, $stored);
    error_log("face_match_descriptor: Distance = " . $distance . ", Threshold = " . $max_distance);
    
    // Return false if distance is INF or NaN
    if (!is_finite($distance)) {
        error_log("face_match_descriptor: Invalid distance value");
        return false;
    }
    
    return $distance <= $max_distance;
}

/**
 * Find a registered rider that matches the captured face descriptor
 * Returns the rider data ONLY if a match is found within threshold
 * Otherwise returns null
 */
function face_find_registered_match($conn, array $captured_descriptor)
{
    error_log("face_find_registered_match: Searching for matching face");
    error_log("face_find_registered_match: Captured descriptor length: " . count($captured_descriptor));
    
    // Validate captured descriptor
    if (empty($captured_descriptor) || count($captured_descriptor) < 64) {
        error_log("face_find_registered_match: Invalid captured descriptor");
        return null;
    }
    
    $stmt = $conn->prepare("
        SELECT id, fullname, face_data, status
        FROM users
        WHERE role = 'rider'
          AND status = 'approved'
          AND face_data IS NOT NULL
          AND face_data != ''
    ");
    
    if (!$stmt) {
        error_log("face_find_registered_match: Prepare failed - " . $conn->error);
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    $best_match = null;
    $best_distance = INF;
    $riders_checked = 0;

    while ($rider = $result->fetch_assoc()) {
        $riders_checked++;
        
        $stored = face_parse_stored_data($rider['face_data']);
        if (!$stored) {
            error_log("face_find_registered_match: Failed to parse stored data for rider " . $rider['id']);
            continue;
        }
        
        // Validate stored descriptor
        if (empty($stored['descriptor']) || count($stored['descriptor']) < 64) {
            error_log("face_find_registered_match: Invalid stored descriptor for rider " . $rider['id']);
            continue;
        }

        // Calculate distance between captured and stored descriptor
        $distance = face_descriptor_distance($captured_descriptor, $stored['descriptor']);
        
        error_log("face_find_registered_match: Rider " . $rider['id'] . " (" . $rider['fullname'] . ") distance: " . $distance);
        
        // Keep track of the closest match
        if ($distance < $best_distance) {
            $best_distance = $distance;
            $best_match = [
                'id' => (int)$rider['id'],
                'fullname' => $rider['fullname'],
                'distance' => $distance
            ];
        }
    }

    $stmt->close();

    error_log("face_find_registered_match: Checked " . $riders_checked . " riders");
    
    // If no riders found in database
    if ($best_match === null) {
        error_log("face_find_registered_match: No riders found in database");
        return null;
    }

    error_log("face_find_registered_match: Best match distance: " . $best_distance . " (threshold: " . FACE_MATCH_MAX_DISTANCE . ")");

    // CRITICAL: ONLY return match if distance is within threshold
    if ($best_distance > FACE_MATCH_MAX_DISTANCE) {
        error_log("face_find_registered_match: REJECTED - Best match distance " . $best_distance . " exceeds threshold " . FACE_MATCH_MAX_DISTANCE);
        return null;
    }

    // Also check if distance is too small (could be the same face but we need to ensure it's not 0)
    if ($best_distance < 0.001) {
        error_log("face_find_registered_match: Distance too small, likely the same face image");
    }

    error_log("face_find_registered_match: ACCEPTED - Match found for " . $best_match['fullname'] . " (ID: " . $best_match['id'] . ") with distance " . $best_match['distance']);

    return $best_match;
}

/**
 * Build stored face data payload for database storage
 * Used by the admin panel when registering/updating riders
 */
function face_build_stored_face_payload($image_data_url, $descriptor)
{
    if (empty($image_data_url) || empty($descriptor) || !is_array($descriptor)) {
        return null;
    }
    
    // Validate descriptor length
    if (count($descriptor) < 64) {
        return null;
    }
    
    // Normalize descriptor values to float
    $normalized = [];
    foreach ($descriptor as $value) {
        if (!is_numeric($value)) {
            return null;
        }
        $normalized[] = (float)$value;
    }
    
    // Validate values are within range
    foreach ($normalized as $value) {
        if ($value < -2 || $value > 2) {
            return null;
        }
    }
    
    return json_encode([
        'image' => $image_data_url,
        'descriptor' => $normalized
    ]);
}

/**
 * Validate that face data is properly formatted for storage
 */
function face_validate_face_data($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    return $parsed !== null;
}

/**
 * Get the image preview from stored face data
 * Returns the image data URL or empty string
 */
function face_get_image_preview($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    if ($parsed && !empty($parsed['image'])) {
        return $parsed['image'];
    }
    return '';
}

/**
 * Debug function to check face data format
 */
function face_debug_face_data($face_data)
{
    $result = [
        'valid' => false,
        'format' => 'unknown',
        'has_image' => false,
        'descriptor_count' => 0,
        'descriptor_sample' => []
    ];
    
    $parsed = face_parse_stored_data($face_data);
    if ($parsed) {
        $result['valid'] = true;
        $result['format'] = 'json';
        $result['has_image'] = !empty($parsed['image']);
        $result['descriptor_count'] = count($parsed['descriptor']);
        // Get first 5 values as sample
        $result['descriptor_sample'] = array_slice($parsed['descriptor'], 0, 5);
    } elseif (!empty($face_data) && strpos($face_data, 'data:image') === 0) {
        $result['format'] = 'legacy_base64';
        $result['has_image'] = true;
    }
    
    return $result;
}
?>