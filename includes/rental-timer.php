<?php
/**
 * E-Bike Rental Timer System
 * Manages 24-hour rental sessions with anti-theft alerts
 */

require_once __DIR__ . '/rental-notifications.php';

class RentalTimer {
    private $conn;
    private $rider_id;
    private $session_data;
    public $grace_period_minutes = 10;
    public $rental_duration_hours = 24; // 24 HOURS - DO NOT CHANGE
    
    public function __construct($conn, $rider_id = null) {
        $this->conn = $conn;
        $this->rider_id = $rider_id;
    }

    private function getRentalDurationSeconds() {
        return $this->rental_duration_hours * 60 * 60;
    }

    /**
     * Ensure end_time is always start_time + rental_duration_hours.
     * Fixes legacy rows that were saved with 12-hour (or missing) end_time.
     */
    private function normalizeSessionEndTime(array $session) {
        $start_time = strtotime($session['start_time']);
        if (!$start_time) {
            return $session;
        }

        $expected_end = $start_time + $this->getRentalDurationSeconds();
        $expected_end_str = date('Y-m-d H:i:s', $expected_end);
        $current_end = !empty($session['end_time']) ? strtotime($session['end_time']) : false;

        if ($current_end === false || abs($current_end - $expected_end) > 1) {
            $stmt = $this->conn->prepare("UPDATE rental_sessions SET end_time = ? WHERE id = ?");
            $stmt->bind_param('si', $expected_end_str, $session['id']);
            $stmt->execute();
            $session['end_time'] = $expected_end_str;
        }

        return $session;
    }
    
    /**
     * Start a new rental session for a rider
     * FIXED: Ensures 24 hours
     */
    public function startRental($rider_id, $ebike_id) {
        // Check if rider already has an active rental
        if ($this->hasActiveRental($rider_id)) {
            return ['success' => false, 'message' => 'Rider already has an active rental session'];
        }
        
        // Check if e-bike is currently rented
        if ($this->isEbikeRented($ebike_id)) {
            return ['success' => false, 'message' => 'E-Bike is currently rented by another rider'];
        }
        
        $start_time = date('Y-m-d H:i:s');
        $end_time = date('Y-m-d H:i:s', time() + $this->getRentalDurationSeconds());
        
        $stmt = $this->conn->prepare("
            INSERT INTO rental_sessions (rider_id, ebike_id, start_time, end_time, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param('isss', $rider_id, $ebike_id, $start_time, $end_time);
        
        if ($stmt->execute()) {
            $session_id = $stmt->insert_id;
            
            // Update user table
            $this->conn->query("UPDATE users SET rental_started = NOW(), is_stolen = 0 WHERE id = $rider_id");
            
            // Log the rental start
            $this->logRentalAction($session_id, 'started', 'Rental session started (24 hours)');
            
            // Send welcome notification to rider
            $this->sendRentalStartNotification($rider_id, $ebike_id);
            
            return [
                'success' => true,
                'session_id' => $session_id,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'message' => 'Rental started successfully (24 hours)'
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to start rental session'];
    }
    
    /**
     * Check if rider has an active rental
     */
    public function hasActiveRental($rider_id) {
        $stmt = $this->conn->prepare("
            SELECT id FROM rental_sessions 
            WHERE rider_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    /**
     * Check if e-bike is currently rented
     */
    public function isEbikeRented($ebike_id) {
        $stmt = $this->conn->prepare("
            SELECT id FROM rental_sessions 
            WHERE ebike_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('s', $ebike_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    /**
     * Get active rental session for a rider
     */
    public function getActiveSession($rider_id = null) {
        $rider_id = $rider_id ?? $this->rider_id;
        if (!$rider_id) return null;
        
        $stmt = $this->conn->prepare("
            SELECT rs.*, u.fullname as rider_name, u.phone as rider_phone
            FROM rental_sessions rs
            JOIN users u ON u.id = rs.rider_id
            WHERE rs.rider_id = ? AND rs.status = 'active'
            ORDER BY rs.id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $this->session_data = $this->normalizeSessionEndTime($result->fetch_assoc());
            return $this->session_data;
        }
        
        return null;
    }
    
    /**
     * Check for expiring or expired rentals and handle them
     * This is called by the cron job every minute
     */
    public function checkAndProcessRentals() {
        $results = [
            'warnings_sent' => 0,
            'stolen_declared' => 0,
            'returned_processed' => 0
        ];
        
        // 1. Find rentals entering grace period (24 hours passed, within 10 minutes)
        $grace_threshold = date('Y-m-d H:i:s', strtotime("+{$this->grace_period_minutes} minutes"));
        
        $stmt = $this->conn->prepare("
            SELECT rs.*, u.fullname, u.email, u.phone
            FROM rental_sessions rs
            JOIN users u ON u.id = rs.rider_id
            WHERE rs.status = 'active' 
              AND rs.end_time <= ?
              AND rs.grace_period_start IS NULL
        ");
        $stmt->bind_param('s', $grace_threshold);
        $stmt->execute();
        $grace_period_sessions = $stmt->get_result();
        
        while ($session = $grace_period_sessions->fetch_assoc()) {
            $session = $this->normalizeSessionEndTime($session);
            // Mark grace period start
            $this->markGracePeriodStart($session['id']);
            
            // Send warning to rider
            $this->sendGracePeriodWarning($session['rider_id'], $session['fullname']);
            
            // Send alert to admin
            $this->sendAdminGracePeriodAlert($session);
            
            $results['warnings_sent']++;
        }
        
        // 2. Find expired rentals (past end_time + grace period)
        $expired_threshold = date('Y-m-d H:i:s', strtotime("-{$this->grace_period_minutes} minutes"));
        
        $stmt = $this->conn->prepare("
            SELECT rs.*, u.fullname, u.email, u.phone
            FROM rental_sessions rs
            JOIN users u ON u.id = rs.rider_id
            WHERE rs.status = 'active' 
              AND rs.end_time <= ?
        ");
        $stmt->bind_param('s', $expired_threshold);
        $stmt->execute();
        $expired_sessions = $stmt->get_result();
        
        while ($session = $expired_sessions->fetch_assoc()) {
            $session = $this->normalizeSessionEndTime($session);
            // Declare as stolen
            $this->declareStolen($session['id'], $session['rider_id']);
            
            // Notify admin
            $this->sendStolenDeclaration($session);
            
            $results['stolen_declared']++;
        }
        
        return $results;
    }
    
    /**
     * Mark the start of grace period for a rental
     */
    private function markGracePeriodStart($session_id) {
        $stmt = $this->conn->prepare("
            UPDATE rental_sessions 
            SET grace_period_start = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        
        $this->logRentalAction($session_id, 'grace_period_started', '10-minute grace period started');
    }
    
    /**
     * Declare a rental as stolen
     */
    public function declareStolen($session_id, $rider_id) {
        $stmt = $this->conn->prepare("
            UPDATE rental_sessions 
            SET status = 'stolen', stolen_declared_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        
        // Update user table
        $this->conn->query("UPDATE users SET is_stolen = 1 WHERE id = $rider_id");
        
        $this->logRentalAction($session_id, 'stolen_declared', 'E-Bike declared as stolen');
        
        return true;
    }
    
    /**
     * Permanently remove a rider account and related tracking data.
     * Used after E-Bike return so the rider must re-register with admin.
     */
    public function deleteRiderAccount($rider_id) {
        $rider_id = (int)$rider_id;
        if ($rider_id <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("DELETE FROM gps_logs WHERE rider_id = ?");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare("DELETE FROM notifications WHERE user_id = ? AND user_type = 'rider'");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ? AND role = 'rider'");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        return $deleted;
    }

    /**
     * Return an e-bike (end rental)
     */
    public function returnEbike($rider_id, $session_id = null) {
        if (!$session_id) {
            $session = $this->getActiveSession($rider_id);
            if (!$session) {
                return ['success' => false, 'message' => 'No active rental session found'];
            }
            $session_id = $session['id'];
        }
        
        $stmt = $this->conn->prepare("
            UPDATE rental_sessions 
            SET status = 'returned', end_time = NOW() 
            WHERE id = ? AND rider_id = ?
        ");
        $stmt->bind_param('ii', $session_id, $rider_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $this->logRentalAction($session_id, 'returned', 'E-Bike returned successfully — rider account removed');

            $this->deleteRiderAccount($rider_id);

            return [
                'success' => true,
                'message' => 'E-Bike returned successfully. Your account has been removed. Please ask the administrator to register you again before logging in.',
                'logout' => true,
                'account_deleted' => true,
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to return E-Bike'];
    }
    
    /**
     * Get rental status for display
     * FIXED: ALWAYS uses 24 hours
     */
    public function getRentalStatus($rider_id = null) {
        $rider_id = $rider_id ?? $this->rider_id;
        if (!$rider_id) return null;
        
        $session = $this->getActiveSession($rider_id);
        if (!$session) {
            return [
                'has_rental' => false,
                'status' => 'no_rental',
                'message' => 'No active rental'
            ];
        }
        
        $now = time();
        $start_time = strtotime($session['start_time']);
        $end_time = strtotime($session['end_time']);
        $time_remaining = $end_time - $now;
        $is_grace_period = $this->isInGracePeriod($session);
        $is_stolen = $session['status'] === 'stolen';
        $total_duration = $this->getRentalDurationSeconds();
        
        return [
            'has_rental' => true,
            'rental_id' => $session['id'],
            'ebike_id' => $session['ebike_id'],
            'start_time' => $session['start_time'],
            'end_time' => $session['end_time'],
            'end_timestamp' => $end_time,
            'start_timestamp' => $start_time,
            'time_remaining_seconds' => max(0, $time_remaining),
            'time_remaining_formatted' => $this->formatTimeRemaining($time_remaining),
            'is_expired' => $time_remaining <= 0,
            'is_grace_period' => $is_grace_period,
            'is_stolen' => $is_stolen,
            'status' => $session['status'],
            'total_duration_seconds' => $total_duration,
            'grace_ends_at' => $is_grace_period ? date('Y-m-d H:i:s', $end_time + ($this->grace_period_minutes * 60)) : null
        ];
    }
    
    /**
     * Check if a rental is in grace period
     */
    private function isInGracePeriod($session) {
        $now = time();
        $end_time = strtotime($session['end_time']);
        $grace_end = $end_time + ($this->grace_period_minutes * 60);
        
        return $session['status'] === 'active' && 
               $now >= $end_time && 
               $now <= $grace_end;
    }
    
    /**
     * Format time remaining for display
     */
    private function formatTimeRemaining($seconds) {
        if ($seconds <= 0) {
            return '00:00:00';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
    
    /**
     * Log rental action
     */
    private function logRentalAction($session_id, $action, $details) {
        $stmt = $this->conn->prepare("
            INSERT INTO rental_logs (rental_session_id, action, details)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iss', $session_id, $action, $details);
        $stmt->execute();
    }
    
    /**
     * Send rental start notification to rider
     */
    private function sendRentalStartNotification($rider_id, $ebike_id) {
        $message = "Your rental for E-Bike #{$ebike_id} has started. You have 24 hours to return the vehicle. Please return it on time to avoid penalties.";
        
        $notifier = new RentalNotifier($this->conn);
        $notifier->notifyRider($rider_id, 'rental_started', 'Rental Started (24 hours)', $message);
    }
    
    /**
     * Send grace period warning to rider
     */
    private function sendGracePeriodWarning($rider_id, $rider_name) {
        $message = "⚠️ URGENT: Your 24-hour rental period has ended. You have 10 minutes to return the E-Bike. If you fail to return it, the vehicle will be declared as stolen. Please contact support immediately.";
        
        $notifier = new RentalNotifier($this->conn);
        $notifier->notifyRider($rider_id, 'grace_period_warning', '⚠️ URGENT: Return E-Bike Now!', $message);
    }
    
    /**
     * Send admin grace period alert
     */
    private function sendAdminGracePeriodAlert($session) {
        $message = "⚠️ Rider {$session['fullname']} has not returned E-Bike #{$session['ebike_id']} within the 24-hour period. Grace period of 10 minutes has started. If not returned, declare as stolen.";
        
        $notifier = new RentalNotifier($this->conn);
        $notifier->notifyAdmin('grace_period_alert', '⚠️ Rental Expired - Grace Period Started', $message);
    }
    
    /**
     * Send stolen declaration notification
     */
    private function sendStolenDeclaration($session) {
        $message = "🚨 STOLEN VEHICLE: Rider {$session['fullname']} has not returned E-Bike #{$session['ebike_id']} within the 24-hour period. The vehicle has been declared as stolen. Contact authorities immediately.";
        
        $notifier = new RentalNotifier($this->conn);
        $notifier->notifyAdmin('stolen_declared', '🚨 STOLEN VEHICLE DECLARED', $message);
        
        // Also notify the rider
        $rider_message = "🚨 YOUR VEHICLE HAS BEEN DECLARED AS STOLEN. You have failed to return the E-Bike within the 24-hour period. This is a serious matter. Please contact support immediately.";
        $notifier->notifyRider($session['rider_id'], 'stolen_declared', '🚨 VEHICLE DECLARED AS STOLEN', $rider_message);
    }
    
    /**
     * Send return confirmation
     */
    private function sendReturnConfirmation($rider_id) {
        $message = "✅ Thank you for returning the E-Bike. Your rental session has been successfully completed.";
        
        $notifier = new RentalNotifier($this->conn);
        $notifier->notifyRider($rider_id, 'returned', 'E-Bike Returned Successfully', $message);
    }
    
    /**
     * Check if rider has been declared stolen
     */
    public function isRiderStolen($rider_id) {
        $stmt = $this->conn->prepare("
            SELECT id FROM rental_sessions 
            WHERE rider_id = ? AND status = 'stolen'
            LIMIT 1
        ");
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }
    
    /**
     * Get all stolen rentals for admin display
     */
    public function getStolenRentals() {
        $stmt = $this->conn->prepare("
            SELECT rs.*, u.fullname, u.email, u.phone
            FROM rental_sessions rs
            JOIN users u ON u.id = rs.rider_id
            WHERE rs.status = 'stolen'
            ORDER BY rs.stolen_declared_at DESC
        ");
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Get all active rentals for admin display
     */
    public function getActiveRentals() {
        $stmt = $this->conn->prepare("
            SELECT rs.*, u.fullname, u.email, u.phone
            FROM rental_sessions rs
            JOIN users u ON u.id = rs.rider_id
            WHERE rs.status = 'active'
            ORDER BY rs.end_time ASC
        ");
        $stmt->execute();
        return $stmt->get_result();
    }
}