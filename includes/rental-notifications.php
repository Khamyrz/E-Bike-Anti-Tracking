<?php
/**
 * Notification System for Rental Alerts
 */

class RentalNotifier {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Send notification to a rider
     */
    public function notifyRider($rider_id, $type, $title, $message) {
        return $this->createNotification($rider_id, 'rider', $type, $title, $message);
    }
    
    /**
     * Send notification to admin
     */
    public function notifyAdmin($type, $title, $message) {
        // Get all admin users
        $stmt = $this->conn->prepare("
            SELECT id FROM users WHERE role = 'admin'
        ");
        $stmt->execute();
        $admins = $stmt->get_result();
        
        $success = true;
        while ($admin = $admins->fetch_assoc()) {
            if (!$this->createNotification($admin['id'], 'admin', $type, $title, $message)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Create a notification in the database
     */
    private function createNotification($user_id, $user_type, $type, $title, $message) {
        $stmt = $this->conn->prepare("
            INSERT INTO notifications (user_id, user_type, type, title, message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issss', $user_id, $user_type, $type, $title, $message);
        return $stmt->execute();
    }
    
    /**
     * Get unread notifications for a user
     */
    public function getUnreadNotifications($user_id, $user_type) {
        $stmt = $this->conn->prepare("
            SELECT * FROM notifications
            WHERE user_id = ? AND user_type = ? AND is_read = 0
            ORDER BY created_at DESC
        ");
        $stmt->bind_param('is', $user_id, $user_type);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Get all notifications for a user
     */
    public function getNotifications($user_id, $user_type, $limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT * FROM notifications
            WHERE user_id = ? AND user_type = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('isi', $user_id, $user_type, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = 1 WHERE id = ?
        ");
        $stmt->bind_param('i', $notification_id);
        return $stmt->execute();
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($user_id, $user_type) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = 1
            WHERE user_id = ? AND user_type = ?
        ");
        $stmt->bind_param('is', $user_id, $user_type);
        return $stmt->execute();
    }
    
    /**
     * Get notification count
     */
    public function getNotificationCount($user_id, $user_type, $unread_only = true) {
        $query = "SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND user_type = ?";
        if ($unread_only) {
            $query .= " AND is_read = 0";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('is', $user_id, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] ?? 0;
    }
}