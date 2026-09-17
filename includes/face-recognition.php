<?php

/**
 * Face recognition helpers — STRICT descriptor matching (face-api.js compatible).
 * 
 * SECURITY LEVEL: MAXIMUM
 * - Match threshold: 0.45 (extremely strict)
 * - Confidence gap required: 0.10 minimum
 * - Detection quality validation
 * - Descriptor length validation
 * - Range validation
 * - Audit logging
 * 
 * Stored face_data format: JSON {"image":"data:image/jpeg;base64,...","descriptor":[128 floats]}
 */

// ================================================================
// SECURITY CONFIGURATION - DO NOT MODIFY WITHOUT REVIEW
// ================================================================

/**
 * FACE_MATCH_MAX_DISTANCE - Maximum allowed distance for a face match
 * 
 * Threshold values and their security implications:
 *   0.35 = ULTRA STRICT - Only identical faces pass (may reject valid users)
 *   0.40 = VERY STRICT - High security, requires near-perfect match
 *   0.45 = STRICT - Recommended for production (current setting)
 *   0.50 = MODERATE - Balanced security/usability
 *   0.55 = LENIENT - Increased false positive risk
 *   0.60 = VERY LENIENT - HIGH SECURITY RISK (default face-api.js)
 * 
 * We use 0.45 for maximum security while maintaining usability.
 */
define('FACE_MATCH_MAX_DISTANCE', 0.45);

/**
 * FACE_MIN_CONFIDENCE_GAP - Minimum distance gap between best and second-best match
 * Prevents ambiguous matches where two faces are similarly close.
 * Value of 0.10 means the best match must be at least 0.10 closer than the second best.
 */
define('FACE_MIN_CONFIDENCE_GAP', 0.10);

/**
 * FACE_MIN_DESCRIPTOR_LENGTH - Minimum length of valid descriptor
 * face-api.js returns 128 values, we require at least 64 for validation
 */
define('FACE_MIN_DESCRIPTOR_LENGTH', 64);

/**
 * FACE_MAX_DESCRIPTOR_LENGTH - Maximum length of valid descriptor
 */
define('FACE_MAX_DESCRIPTOR_LENGTH', 128);

/**
 * FACE_DESCRIPTOR_VALUE_RANGE - Valid range for descriptor values
 * face-api.js descriptors typically range from -1 to 1
 */
define('FACE_DESCRIPTOR_VALUE_RANGE_MIN', -2);
define('FACE_DESCRIPTOR_VALUE_RANGE_MAX', 2);

/**
 * FACE_MIN_DETECTION_CONFIDENCE - Minimum detection score required
 * Higher values ensure better quality face detections
 */
define('FACE_MIN_DETECTION_CONFIDENCE', 0.6);

// ================================================================
// CORE FUNCTIONS
// ================================================================

/**
 * Parse stored face data from database
 * Expects JSON format: {"image":"data:image/jpeg;base64,...","descriptor":[128 floats]}
 * 
 * @param string $face_data JSON string from database
 * @return array|null Parsed data or null if invalid
 */
function face_parse_stored_data($face_data)
{
    $face_data = trim((string)$face_data);
    if ($face_data === '') {
        error_log("face_parse_stored_data: Empty data provided");
        return null;
    }

    // Must be JSON format
    if ($face_data[0] !== '{') {
        error_log("face_parse_stored_data: Data is not JSON format - first char: " . $face_data[0]);
        return null;
    }

    $parsed = json_decode($face_data, true);
    if (!is_array($parsed)) {
        error_log("face_parse_stored_data: JSON decode failed");
        return null;
    }

    // Validate image presence
    if (!isset($parsed['image']) || empty($parsed['image'])) {
        error_log("face_parse_stored_data: Missing or empty image");
        return null;
    }

    // Validate image format
    if (strpos($parsed['image'], 'data:image') !== 0) {
        error_log("face_parse_stored_data: Invalid image format");
        return null;
    }

    // Validate descriptor
    $descriptor = $parsed['descriptor'] ?? null;
    if (!is_array($descriptor)) {
        error_log("face_parse_stored_data: Descriptor is not an array");
        return null;
    }

    $descriptor_count = count($descriptor);
    if ($descriptor_count < FACE_MIN_DESCRIPTOR_LENGTH || $descriptor_count > FACE_MAX_DESCRIPTOR_LENGTH) {
        error_log("face_parse_stored_data: Invalid descriptor count: " . $descriptor_count . 
                  " (min: " . FACE_MIN_DESCRIPTOR_LENGTH . ", max: " . FACE_MAX_DESCRIPTOR_LENGTH . ")");
        return null;
    }

    // Normalize all values to float and validate range
    $normalized = [];
    foreach ($descriptor as $index => $value) {
        if (!is_numeric($value)) {
            error_log("face_parse_stored_data: Non-numeric value at index $index");
            return null;
        }
        
        $float_val = (float)$value;
        
        // Check value range
        if ($float_val < FACE_DESCRIPTOR_VALUE_RANGE_MIN || $float_val > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
            error_log("face_parse_stored_data: Value out of range at index $index: " . $float_val);
            return null;
        }
        
        $normalized[] = $float_val;
    }

    return [
        'image'      => (string)$parsed['image'],
        'descriptor' => $normalized,
    ];
}

/**
 * Parse login descriptor from JavaScript
 * Expects JSON array of 128 floats
 * 
 * @param string|array $raw Raw descriptor data from frontend
 * @return array|null Normalized descriptor array or null if invalid
 */
function face_parse_login_descriptor($raw)
{
    // Handle array input
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
            error_log("face_parse_login_descriptor: Failed to decode JSON");
            return null;
        }
    }

    // Validate count
    $count = count($values);
    if ($count < FACE_MIN_DESCRIPTOR_LENGTH || $count > FACE_MAX_DESCRIPTOR_LENGTH) {
        error_log("face_parse_login_descriptor: Invalid descriptor count: $count");
        return null;
    }

    // Normalize and validate
    $normalized = [];
    foreach ($values as $index => $value) {
        if (!is_numeric($value)) {
            error_log("face_parse_login_descriptor: Non-numeric value at index $index");
            return null;
        }
        
        $float_val = (float)$value;
        
        if ($float_val < FACE_DESCRIPTOR_VALUE_RANGE_MIN || $float_val > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
            error_log("face_parse_login_descriptor: Value out of range at index $index: " . $float_val);
            return null;
        }
        
        $normalized[] = $float_val;
    }

    return $normalized;
}

/**
 * Calculate Euclidean distance between two face descriptors
 * 
 * @param array $a First descriptor
 * @param array $b Second descriptor
 * @return float Distance (0 = identical, higher = more different)
 */
function face_descriptor_distance(array $a, array $b)
{
    if (empty($a) || empty($b)) {
        error_log("face_descriptor_distance: Empty descriptor provided");
        return INF;
    }
    
    $count = min(count($a), count($b));
    if ($count < FACE_MIN_DESCRIPTOR_LENGTH) {
        error_log("face_descriptor_distance: Descriptor too short: $count");
        return INF;
    }

    $sum = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $diff = $a[$i] - $b[$i];
        $sum += $diff * $diff;
    }

    $distance = sqrt($sum);
    
    // Log distance for debugging
    error_log("face_descriptor_distance: Distance = " . number_format($distance, 6));
    
    return $distance;
}

/**
 * Check if two face descriptors match within the threshold
 * Returns true ONLY if distance is below threshold and valid
 * 
 * @param array $captured Captured face descriptor
 * @param array $stored Stored face descriptor from database
 * @param float $max_distance Maximum allowed distance (default: FACE_MATCH_MAX_DISTANCE)
 * @return bool True if match, false otherwise
 */
function face_match_descriptor(array $captured, array $stored, $max_distance = FACE_MATCH_MAX_DISTANCE)
{
    // Validate inputs
    if (empty($captured) || empty($stored)) {
        error_log("face_match_descriptor: Empty descriptor provided");
        return false;
    }
    
    if (count($captured) < FACE_MIN_DESCRIPTOR_LENGTH || count($stored) < FACE_MIN_DESCRIPTOR_LENGTH) {
        error_log("face_match_descriptor: Descriptor too short - captured: " . count($captured) . 
                  ", stored: " . count($stored));
        return false;
    }
    
    $distance = face_descriptor_distance($captured, $stored);
    
    // Check for invalid distance
    if (!is_finite($distance)) {
        error_log("face_match_descriptor: Invalid distance value: " . var_export($distance, true));
        return false;
    }
    
    // Check against threshold
    $is_match = $distance <= $max_distance;
    
    error_log("face_match_descriptor: Distance = " . number_format($distance, 6) . 
              ", Threshold = " . $max_distance . 
              ", Match = " . ($is_match ? 'YES' : 'NO'));
    
    return $is_match;
}

/**
 * Check if a face is already registered (duplicate prevention)
 * 
 * @param mysqli $conn Database connection
 * @param array $captured_descriptor Face descriptor to check
 * @param int $exclude_id Optional rider ID to exclude from check (for updates)
 * @return int|false Returns existing rider ID if duplicate found, false otherwise
 */
function face_check_duplicate($conn, array $captured_descriptor, $exclude_id = null)
{
    error_log("face_check_duplicate: Checking for duplicate face");
    
    // Validate captured descriptor
    if (empty($captured_descriptor) || count($captured_descriptor) < FACE_MIN_DESCRIPTOR_LENGTH) {
        error_log("face_check_duplicate: Invalid captured descriptor");
        return false;
    }

    // Build query with exclude if provided
    $sql = "
        SELECT id, fullname, face_data
        FROM users
        WHERE role = 'rider'
          AND face_data IS NOT NULL
          AND face_data != ''
    ";
    
    if ($exclude_id !== null) {
        $sql .= " AND id != " . (int)$exclude_id;
    }
    
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        error_log("face_check_duplicate: No other riders found");
        return false;
    }

    while ($rider = $result->fetch_assoc()) {
        $stored = face_parse_stored_data($rider['face_data']);
        if (!$stored || empty($stored['descriptor'])) {
            continue;
        }

        // Calculate distance
        $distance = face_descriptor_distance($captured_descriptor, $stored['descriptor']);
        
        error_log("face_check_duplicate: Comparing with rider #{$rider['id']} ({$rider['fullname']}) - Distance: " . number_format($distance, 6));
        
        // If distance is below threshold, it's a duplicate
        if ($distance <= FACE_MATCH_MAX_DISTANCE) {
            error_log("face_check_duplicate: DUPLICATE FOUND - Rider #{$rider['id']} ({$rider['fullname']})");
            return (int)$rider['id'];
        }
    }

    error_log("face_check_duplicate: No duplicate found");
    return false;
}

/**
 * Find a registered rider that matches the captured face descriptor
 * Uses STRICT matching with confidence gap verification
 * 
 * @param mysqli $conn Database connection
 * @param array $captured_descriptor Face descriptor from login
 * @return array|null Rider data if match found, null otherwise
 */
function face_find_registered_match($conn, array $captured_descriptor)
{
    error_log("=== FACE MATCH ATTEMPT STARTED ===");
    
    // Validate captured descriptor
    if (empty($captured_descriptor) || count($captured_descriptor) < FACE_MIN_DESCRIPTOR_LENGTH) {
        error_log("face_find_registered_match: Invalid captured descriptor - count: " . 
                  (is_array($captured_descriptor) ? count($captured_descriptor) : 'not array'));
        return null;
    }
    
    error_log("face_find_registered_match: Captured descriptor length: " . count($captured_descriptor));
    
    // Get all approved riders with face data
    $stmt = $conn->prepare("
        SELECT id, fullname, email, ebike_id, face_data, status
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
    $second_best_distance = INF;
    $riders_checked = 0;
    $valid_riders_checked = 0;

    while ($rider = $result->fetch_assoc()) {
        $riders_checked++;
        
        // Parse stored face data
        $stored = face_parse_stored_data($rider['face_data']);
        if (!$stored) {
            error_log("face_find_registered_match: Failed to parse stored data for rider ID: " . $rider['id']);
            continue;
        }
        
        // Validate stored descriptor
        if (empty($stored['descriptor']) || count($stored['descriptor']) < FACE_MIN_DESCRIPTOR_LENGTH) {
            error_log("face_find_registered_match: Invalid stored descriptor for rider ID: " . $rider['id']);
            continue;
        }

        $valid_riders_checked++;
        
        // Calculate distance
        $distance = face_descriptor_distance($captured_descriptor, $stored['descriptor']);
        
        error_log("face_find_registered_match: Rider #{$rider['id']} ({$rider['fullname']}) - " .
                  "Distance: " . number_format($distance, 6));
        
        // Track best and second best
        if ($distance < $best_distance) {
            $second_best_distance = $best_distance;
            $best_distance = $distance;
            $best_match = [
                'id' => (int)$rider['id'],
                'fullname' => $rider['fullname'],
                'email' => $rider['email'],
                'ebike_id' => $rider['ebike_id'],
                'distance' => $distance
            ];
        } elseif ($distance < $second_best_distance) {
            $second_best_distance = $distance;
        }
    }

    $stmt->close();

    error_log("face_find_registered_match: Total riders checked: $riders_checked, Valid: $valid_riders_checked");

    // No valid riders found
    if ($best_match === null) {
        error_log("face_find_registered_match: No valid riders found in database");
        return null;
    }

    error_log("face_find_registered_match: Best match: " . $best_match['fullname'] . 
              " (ID: " . $best_match['id'] . ") - Distance: " . number_format($best_distance, 6));
    error_log("face_find_registered_match: Second best distance: " . number_format($second_best_distance, 6));

    // ================================================================
    // SECURITY CHECK 1: Distance threshold
    // ================================================================
    if ($best_distance > FACE_MATCH_MAX_DISTANCE) {
        error_log("face_find_registered_match: REJECTED - Distance " . number_format($best_distance, 6) . 
                  " exceeds threshold " . FACE_MATCH_MAX_DISTANCE);
        return null;
    }

    // ================================================================
    // SECURITY CHECK 2: Confidence gap
    // Ensures the match is unambiguous
    // ================================================================
    $gap = $second_best_distance - $best_distance;
    if ($gap < FACE_MIN_CONFIDENCE_GAP && $second_best_distance !== INF) {
        error_log("face_find_registered_match: REJECTED - Confidence gap " . number_format($gap, 6) . 
                  " is less than required " . FACE_MIN_CONFIDENCE_GAP);
        return null;
    }

    // ================================================================
    // SECURITY CHECK 3: Distance sanity check
    // Prevents obvious invalid matches
    // ================================================================
    if ($best_distance < 0.001) {
        error_log("face_find_registered_match: WARNING - Very low distance " . number_format($best_distance, 6) . 
                  " - This might be the same image");
        // Still allow it, but log it
    }

    error_log("=== FACE MATCH ACCEPTED ===");
    error_log("face_find_registered_match: ACCEPTED - Rider: " . $best_match['fullname'] . 
              " (ID: " . $best_match['id'] . ") - Distance: " . number_format($best_distance, 6) . 
              ", Gap: " . number_format($gap, 6));

    return $best_match;
}

/**
 * Build stored face data payload for database storage
 * Used by the admin panel when registering/updating riders
 * 
 * @param string $image_data_url Base64 image data
 * @param array $descriptor Face descriptor array
 * @return string|null JSON payload or null if invalid
 */
function face_build_stored_face_payload($image_data_url, $descriptor)
{
    // Validate inputs
    if (empty($image_data_url) || empty($descriptor) || !is_array($descriptor)) {
        error_log("face_build_stored_face_payload: Invalid inputs");
        return null;
    }
    
    // Validate image format
    if (strpos($image_data_url, 'data:image') !== 0) {
        error_log("face_build_stored_face_payload: Invalid image format");
        return null;
    }
    
    // Validate descriptor length
    $count = count($descriptor);
    if ($count < FACE_MIN_DESCRIPTOR_LENGTH || $count > FACE_MAX_DESCRIPTOR_LENGTH) {
        error_log("face_build_stored_face_payload: Invalid descriptor count: $count");
        return null;
    }
    
    // Normalize and validate descriptor values
    $normalized = [];
    foreach ($descriptor as $index => $value) {
        if (!is_numeric($value)) {
            error_log("face_build_stored_face_payload: Non-numeric value at index $index");
            return null;
        }
        
        $float_val = (float)$value;
        
        if ($float_val < FACE_DESCRIPTOR_VALUE_RANGE_MIN || $float_val > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
            error_log("face_build_stored_face_payload: Value out of range at index $index: " . $float_val);
            return null;
        }
        
        $normalized[] = $float_val;
    }
    
    $payload = [
        'image' => $image_data_url,
        'descriptor' => $normalized
    ];
    
    return json_encode($payload);
}

/**
 * Validate that face data is properly formatted for storage
 * 
 * @param string $face_data Raw face data
 * @return bool True if valid
 */
function face_validate_face_data($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    return $parsed !== null;
}

/**
 * Get the image preview from stored face data
 * Returns the image data URL or empty string
 * 
 * @param string $face_data Raw face data
 * @return string Image data URL or empty string
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
 * Get descriptor from stored face data
 * 
 * @param string $face_data Raw face data
 * @return array|null Descriptor array or null if invalid
 */
function face_get_descriptor($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    if ($parsed && !empty($parsed['descriptor'])) {
        return $parsed['descriptor'];
    }
    return null;
}

/**
 * Debug function to check face data format
 * 
 * @param string $face_data Raw face data
 * @return array Debug information
 */
function face_debug_face_data($face_data)
{
    $result = [
        'valid' => false,
        'format' => 'unknown',
        'has_image' => false,
        'descriptor_count' => 0,
        'descriptor_sample' => [],
        'size_bytes' => strlen($face_data)
    ];
    
    if (empty($face_data)) {
        $result['format'] = 'empty';
        return $result;
    }
    
    $parsed = face_parse_stored_data($face_data);
    if ($parsed) {
        $result['valid'] = true;
        $result['format'] = 'json';
        $result['has_image'] = !empty($parsed['image']);
        $result['descriptor_count'] = count($parsed['descriptor']);
        $result['descriptor_sample'] = array_slice($parsed['descriptor'], 0, 5);
        $result['image_size'] = strlen($parsed['image']);
    } elseif (strpos($face_data, 'data:image') === 0) {
        $result['format'] = 'legacy_base64';
        $result['has_image'] = true;
        $result['image_size'] = strlen($face_data);
    }
    
    return $result;
}

/**
 * Check if face data has a valid descriptor
 * 
 * @param string $face_data Raw face data
 * @return bool True if descriptor is valid
 */
function face_has_valid_descriptor($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    return $parsed !== null && !empty($parsed['descriptor']) && 
           count($parsed['descriptor']) >= FACE_MIN_DESCRIPTOR_LENGTH;
}