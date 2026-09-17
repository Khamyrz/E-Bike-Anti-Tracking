/**
 * E-Bike Rental Timer - Realtime Countdown
 * Displays remaining time and handles notifications
 */

class RentalTimer {
    constructor(riderId, options = {}) {
        this.riderId = riderId;
        this.sessionId = options.sessionId || null;
        this.endTime = options.endTime || null;
        this.gracePeriodMinutes = options.gracePeriodMinutes || 10;
        this.rentalDurationHours = options.rentalDurationHours || 24;
        this.onTimerUpdate = options.onTimerUpdate || null;
        this.onExpired = options.onExpired || null;
        this.onGracePeriod = options.onGracePeriod || null;
        this.onStolen = options.onStolen || null;
        
        this.timerElement = document.getElementById(options.timerElement || 'rental-timer');
        this.statusElement = document.getElementById(options.statusElement || 'rental-status');
        this.warningElement = document.getElementById(options.warningElement || 'rental-warning');
        
        this.timerInterval = null;
        this.checkInterval = null;
        this.isExpired = false;
        this.isGracePeriod = false;
        this.isStolen = false;
        
        this.init();
    }
    
    init() {
        if (!this.timerElement) {
            console.warn('Timer element not found');
            return;
        }
        
        // Check if there's an active rental
        this.checkRentalStatus();
        
        // Start checking periodically
        this.checkInterval = setInterval(() => {
            this.checkRentalStatus();
        }, 30000); // Check every 30 seconds
        
        // Start timer updates
        this.startTimer();
    }
    
    checkRentalStatus() {
        fetch(`../api/rental-status.php?rider_id=${this.riderId}`)
            .then(response => response.json())
            .then(data => {
                if (data.has_rental) {
                    this.sessionId = data.rental_id;
                    this.endTimestamp = data.end_timestamp || Math.floor(new Date(data.end_time).getTime() / 1000);
                    this.endTime = data.end_time;
                    this.isExpired = data.is_expired || false;
                    this.isGracePeriod = data.is_grace_period || false;
                    this.isStolen = data.is_stolen || false;
                    
                    if (this.isStolen && this.onStolen) {
                        this.onStolen(data);
                        this.showStolenAlert();
                    } else if (this.isGracePeriod && this.onGracePeriod) {
                        this.onGracePeriod(data);
                        this.showGracePeriodAlert();
                    } else if (this.isExpired && this.onExpired) {
                        this.onExpired(data);
                    }
                    
                    this.updateDisplay(data);
                } else {
                    this.showNoRental();
                }
            })
            .catch(error => {
                console.error('Error checking rental status:', error);
            });
    }
    
    startTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        
        this.timerInterval = setInterval(() => {
            if (!this.endTimestamp && !this.endTime) return;
            
            const now = Math.floor(Date.now() / 1000);
            const end = this.endTimestamp || Math.floor(new Date(this.endTime).getTime() / 1000);
            let remaining = end - now;
            
            if (this.timerElement) {
                this.timerElement.textContent = this.formatTime(remaining);
            }
            
            // Update status colors based on time remaining
            if (this.statusElement) {
                if (remaining <= 0) {
                    this.statusElement.textContent = '⏰ EXPIRED';
                    this.statusElement.className = 'status-expired';
                    
                    // Check if in grace period
                    const graceEnd = end + (this.gracePeriodMinutes * 60);
                    if (now <= graceEnd && !this.isGracePeriod) {
                        this.isGracePeriod = true;
                        this.showGracePeriodAlert();
                    } else if (now > graceEnd && !this.isStolen) {
                        this.isStolen = true;
                        if (this.onStolen) {
                            this.onStolen({});
                        }
                        this.showStolenAlert();
                    }
                } else if (remaining < 3600) { // Less than 1 hour
                    this.statusElement.textContent = '⚠️ URGENT';
                    this.statusElement.className = 'status-urgent';
                } else if (remaining < 7200) { // Less than 2 hours
                    this.statusElement.textContent = '⚠️ Soon';
                    this.statusElement.className = 'status-warning';
                } else {
                    this.statusElement.textContent = '✅ Active';
                    this.statusElement.className = 'status-active';
                }
            }
            
            if (this.onTimerUpdate) {
                this.onTimerUpdate({
                    remaining: remaining,
                    formatted: this.formatTime(remaining),
                    isExpired: remaining <= 0,
                    isGracePeriod: this.isGracePeriod,
                    isStolen: this.isStolen
                });
            }
        }, 1000);
    }
    
    formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    
    updateDisplay(data) {
        if (this.timerElement) {
            const endTs = data.end_timestamp || Math.floor(new Date(data.end_time).getTime() / 1000);
            const remaining = Math.max(0, endTs - Math.floor(Date.now() / 1000));
            this.timerElement.textContent = this.formatTime(remaining);
        }
        
        if (this.statusElement) {
            if (data.is_stolen) {
                this.statusElement.textContent = '🚨 STOLEN';
                this.statusElement.className = 'status-stolen';
            } else if (data.is_grace_period) {
                this.statusElement.textContent = '⏰ GRACE PERIOD';
                this.statusElement.className = 'status-grace';
            } else if (data.is_expired) {
                this.statusElement.textContent = '⏰ EXPIRED';
                this.statusElement.className = 'status-expired';
            } else {
                this.statusElement.textContent = '✅ Active';
                this.statusElement.className = 'status-active';
            }
        }
    }
    
    showGracePeriodAlert() {
        if (this.warningElement) {
            this.warningElement.innerHTML = `
                <div class="alert alert-danger">
                    <strong>⚠️ URGENT:</strong> Your 24-hour rental period has ended. 
                    You have <strong>${this.gracePeriodMinutes} minutes</strong> to return the E-Bike. 
                    If you fail to return it, the vehicle will be declared as stolen.
                </div>
            `;
            this.warningElement.style.display = 'block';
        }
        
        // Show notification
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('⚠️ URGENT: Return E-Bike Now!', {
                body: `You have ${this.gracePeriodMinutes} minutes to return the E-Bike or it will be declared stolen.`,
                icon: '/assets/images/alert-icon.png'
            });
        }
    }
    
    showStolenAlert() {
        if (this.warningElement) {
            this.warningElement.innerHTML = `
                <div class="alert alert-stolen">
                    <strong>🚨 VEHICLE DECLARED AS STOLEN:</strong> 
                    You have failed to return the E-Bike within the 24-hour period. 
                    This is a serious matter. Please contact support immediately.
                </div>
            `;
            this.warningElement.style.display = 'block';
        }
        
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('🚨 VEHICLE DECLARED AS STOLEN', {
                body: 'You have been declared as a vehicle thief. Contact support immediately.',
                icon: '/assets/images/alert-icon.png'
            });
        }
    }
    
    showNoRental() {
        if (this.timerElement) {
            this.timerElement.textContent = '--:--:--';
        }
        if (this.statusElement) {
            this.statusElement.textContent = 'No active rental';
            this.statusElement.className = 'status-inactive';
        }
        if (this.warningElement) {
            this.warningElement.style.display = 'none';
        }
    }
    
    /**
     * Return the e-bike (call API)
     */
    returnEbike() {
        return fetch('../api/return-ebike.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                rider_id: this.riderId,
                session_id: this.sessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showNoRental();
                if (this.timerInterval) {
                    clearInterval(this.timerInterval);
                }
                if (this.checkInterval) {
                    clearInterval(this.checkInterval);
                }
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            }
            return data;
        });
    }
    
    /**
     * Clean up intervals
     */
    destroy() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
        }
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }
    }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RentalTimer;
}