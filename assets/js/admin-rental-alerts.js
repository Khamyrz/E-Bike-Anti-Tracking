/**
 * Admin Rental Alerts - Real-time monitoring
 */

class AdminRentalAlerts {
    constructor(options = {}) {
        this.adminId = options.adminId || null;
        this.refreshInterval = options.refreshInterval || 30000;
        this.alertContainer = document.getElementById(options.alertContainer || 'rental-alerts');
        this.stolenContainer = document.getElementById(options.stolenContainer || 'stolen-vehicles');
        
        this.alerts = [];
        this.stolenVehicles = [];
        
        this.init();
    }
    
    init() {
        // Load initial data
        this.loadAlerts();
        
        // Start periodic refresh
        setInterval(() => {
            this.loadAlerts();
        }, this.refreshInterval);
        
        // Enable sound notifications
        this.enableSoundNotifications();
        
        // Request notification permission
        if ('Notification' in window) {
            Notification.requestPermission();
        }
    }
    
    loadAlerts() {
        fetch('../api/admin-rental-alerts.php')
            .then(response => response.json())
            .then(data => {
                // Check for new stolen vehicles
                if (data.stolen_vehicles && data.stolen_vehicles.length > 0) {
                    const newStolen = data.stolen_vehicles.filter(
                        vehicle => !this.stolenVehicles.some(v => v.id === vehicle.id)
                    );
                    
                    if (newStolen.length > 0) {
                        newStolen.forEach(vehicle => {
                            this.showStolenAlert(vehicle);
                            this.playAlertSound();
                        });
                    }
                }
                
                // Check for new grace period alerts
                if (data.grace_period_alerts && data.grace_period_alerts.length > 0) {
                    const newAlerts = data.grace_period_alerts.filter(
                        alert => !this.alerts.some(a => a.id === alert.id)
                    );
                    
                    if (newAlerts.length > 0) {
                        newAlerts.forEach(alert => {
                            this.showGracePeriodAlert(alert);
                            this.playWarningSound();
                        });
                    }
                }
                
                this.alerts = data.grace_period_alerts || [];
                this.stolenVehicles = data.stolen_vehicles || [];
                
                this.updateDisplay(data);
            })
            .catch(error => {
                console.error('Error loading rental alerts:', error);
            });
    }
    
    updateDisplay(data) {
        // Update stolen vehicles list
        if (this.stolenContainer) {
            if (data.stolen_vehicles && data.stolen_vehicles.length > 0) {
                this.stolenContainer.innerHTML = data.stolen_vehicles.map(vehicle => `
                    <div class="stolen-card alert-danger">
                        <div class="stolen-header">
                            <span class="stolen-icon">🚨</span>
                            <strong>STOLEN VEHICLE</strong>
                            <span class="stolen-time">${vehicle.stolen_declared_at}</span>
                        </div>
                        <div class="stolen-body">
                            <p><strong>Rider:</strong> ${vehicle.fullname}</p>
                            <p><strong>E-Bike ID:</strong> ${vehicle.ebike_id}</p>
                            <p><strong>Phone:</strong> ${vehicle.phone}</p>
                            <p><strong>Stolen At:</strong> ${vehicle.stolen_declared_at}</p>
                            <div class="stolen-actions">
                                <button onclick="window.location.href='riders.php?view=stolen&id=${vehicle.rider_id}'" class="btn btn-danger">View Details</button>
                                <button onclick="sendAlert('${vehicle.rider_id}', 'stolen')" class="btn btn-warning">Alert Authorities</button>
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                this.stolenContainer.innerHTML = `
                    <div class="empty-state">
                        <p>✅ No stolen vehicles reported</p>
                    </div>
                `;
            }
        }
        
        // Update alerts list
        if (this.alertContainer) {
            if (data.grace_period_alerts && data.grace_period_alerts.length > 0) {
                this.alertContainer.innerHTML = data.grace_period_alerts.map(alert => `
                    <div class="alert-card alert-warning">
                        <div class="alert-header">
                            <span class="alert-icon">⚠️</span>
                            <strong>Grace Period</strong>
                            <span class="alert-time">${alert.grace_period_start}</span>
                        </div>
                        <div class="alert-body">
                            <p><strong>Rider:</strong> ${alert.fullname}</p>
                            <p><strong>E-Bike ID:</strong> ${alert.ebike_id}</p>
                            <p><strong>Time Remaining:</strong> <span class="timer-countdown" data-end="${alert.grace_period_end}">${this.calculateTimeRemaining(alert.grace_period_end)}</span></p>
                            <div class="alert-actions">
                                <button onclick="window.location.href='riders.php?view=expired&id=${alert.rider_id}'" class="btn btn-warning">View Rider</button>
                                <button onclick="sendAlert('${alert.rider_id}', 'warning')" class="btn btn-primary">Send Reminder</button>
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                this.alertContainer.innerHTML = `
                    <div class="empty-state">
                        <p>✅ No active alerts</p>
                    </div>
                `;
            }
        }
    }
    
    showStolenAlert(vehicle) {
        const alert = document.createElement('div');
        alert.className = 'stolen-alert';
        alert.innerHTML = `
            <div class="stolen-alert-content">
                <span class="stolen-alert-icon">🚨</span>
                <div class="stolen-alert-text">
                    <strong>STOLEN VEHICLE DECLARED</strong>
                    <p>Rider ${vehicle.fullname} has not returned E-Bike #${vehicle.ebike_id}</p>
                    <p class="stolen-alert-time">${vehicle.stolen_declared_at}</p>
                </div>
                <button class="stolen-alert-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        document.body.appendChild(alert);
        
        // Auto-dismiss after 30 seconds
        setTimeout(() => {
            if (alert.parentElement) {
                alert.classList.add('fade-out');
                setTimeout(() => alert.remove(), 500);
            }
        }, 30000);
    }
    
    showGracePeriodAlert(alert) {
        const alertEl = document.createElement('div');
        alertEl.className = 'grace-alert';
        alertEl.innerHTML = `
            <div class="grace-alert-content">
                <span class="grace-alert-icon">⚠️</span>
                <div class="grace-alert-text">
                    <strong>Rental Expired - Grace Period</strong>
                    <p>Rider ${alert.fullname} has not returned E-Bike #${alert.ebike_id}</p>
                    <p class="grace-alert-time">${alert.grace_period_start}</p>
                </div>
                <button class="grace-alert-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        document.body.appendChild(alertEl);
        
        setTimeout(() => {
            if (alertEl.parentElement) {
                alertEl.classList.add('fade-out');
                setTimeout(() => alertEl.remove(), 500);
            }
        }, 15000);
    }
    
    calculateTimeRemaining(endTime) {
        const now = Math.floor(Date.now() / 1000);
        const end = Math.floor(new Date(endTime).getTime() / 1000);
        const remaining = end - now;
        
        if (remaining <= 0) return 'EXPIRED';
        
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        return `${minutes}m ${seconds}s`;
    }
    
    enableSoundNotifications() {
        // Create audio elements
        this.warningSound = new Audio('/assets/sounds/warning.mp3');
        this.alertSound = new Audio('/assets/sounds/alert.mp3');
    }
    
    playWarningSound() {
        if (this.warningSound) {
            this.warningSound.play().catch(() => {});
        }
    }
    
    playAlertSound() {
        if (this.alertSound) {
            this.alertSound.play().catch(() => {});
        }
    }
}

// Global functions for buttons
function sendAlert(riderId, type) {
    fetch('../api/send-rental-alert.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rider_id: riderId,
            type: type
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Alert sent successfully!');
        } else {
            alert('Failed to send alert: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending alert:', error);
        alert('Failed to send alert. Please try again.');
    });
}