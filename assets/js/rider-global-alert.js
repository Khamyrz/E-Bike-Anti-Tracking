// assets/js/rider-global-alert.js
// Global alert system para sa rider - gumagana sa lahat ng pages

(function() {
    // Check kung naka-login bilang rider
    if (typeof window.RIDER_ID === 'undefined' && !document.body.dataset.riderId) {
        // Subukang kunin ang rider ID mula sa body attribute
        const bodyRiderId = document.body.getAttribute('data-rider-id');
        if (bodyRiderId) {
            window.RIDER_ID = parseInt(bodyRiderId);
        } else {
            // Kung walang rider ID, huwag mag-run
            console.log('No rider ID found, skipping global alerts');
            return;
        }
    }
    
    const riderId = window.RIDER_ID;
    let lastAlertStatus = 'inside'; // Para sa pag-track ng status changes
    let alertShown = false;
    
    // Gumawa ng alert container
    function createAlertContainer() {
        if (document.getElementById('riderAlertContainer')) return;
        
        const container = document.createElement('div');
        container.id = 'riderAlertContainer';
        container.style.cssText = `
            position: fixed;
            top: 70px;
            right: 10px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 350px;
            pointer-events: none;
        `;
        document.body.appendChild(container);
    }
    
    // Function para magpakita ng alert
    function showAlert(title, message, type = 'warning') {
        createAlertContainer();
        
        const container = document.getElementById('riderAlertContainer');
        if (!container) return;
        
        // Check kung may duplicate
        const existing = container.querySelectorAll('.rider-alert');
        for (let alert of existing) {
            if (alert.dataset.alertType === type) return;
        }
        
        const alertDiv = document.createElement('div');
        alertDiv.className = 'rider-alert';
        alertDiv.dataset.alertType = type;
        
        const colors = {
            danger: { border: '#ef4444', bg: 'rgba(239, 68, 68, 0.1)', icon: '🚨' },
            warning: { border: '#f59e0b', bg: 'rgba(245, 158, 11, 0.1)', icon: '⚠️' },
            success: { border: '#10b981', bg: 'rgba(16, 185, 129, 0.1)', icon: '✅' },
            info: { border: '#3b82f6', bg: 'rgba(59, 130, 246, 0.1)', icon: 'ℹ️' }
        };
        
        const color = colors[type] || colors.info;
        
        alertDiv.style.cssText = `
            pointer-events: auto;
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(8px);
            border: 1px solid ${color.border};
            border-left: 4px solid ${color.border};
            color: #f0f4ff;
            padding: 12px 16px;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            cursor: pointer;
            animation: riderAlertSlideIn 0.3s ease;
            transition: opacity 0.3s ease, transform 0.3s ease;
            font-family: 'Inter', sans-serif;
        `;
        
        alertDiv.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:24px;">${color.icon}</span>
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:4px;">${title}</div>
                    <div style="font-size:12px;opacity:0.9;line-height:1.4;">${message}</div>
                </div>
                <button onclick="dismissRiderAlert(this)" 
                        style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:18px;padding:0;">
                    ×
                </button>
            </div>
        `;
        
        alertDiv.addEventListener('click', function(e) {
            if (e.target.tagName !== 'BUTTON') {
                // Pumunta sa map page kung hindi pa doon
                if (!window.location.pathname.includes('map.php')) {
                    window.location.href = 'map.php';
                }
            }
        });
        
        container.appendChild(alertDiv);
        
        // Auto-dismiss after 10 seconds
        setTimeout(() => {
            alertDiv.style.opacity = '0';
            alertDiv.style.transform = 'translateX(100%)';
            setTimeout(() => alertDiv.remove(), 300);
        }, 10000);
        
        // Play sound
        playAlertSound(type);
        
        // Vibration kung supported
        if (navigator.vibrate) {
            if (type === 'danger') {
                navigator.vibrate([200, 100, 200]);
            } else if (type === 'warning') {
                navigator.vibrate(200);
            }
        }
    }
    
    // Dismiss function
    window.dismissRiderAlert = function(buttonElement) {
        const alertElement = buttonElement.closest('.rider-alert');
        if (alertElement) {
            alertElement.style.opacity = '0';
            alertElement.style.transform = 'translateX(100%)';
            setTimeout(() => alertElement.remove(), 300);
        }
    };
    
    // Sound alert
    function playAlertSound(type) {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            const frequencies = {
                danger: 440,
                warning: 660,
                success: 880,
                info: 520
            };
            
            oscillator.frequency.value = frequencies[type] || 440;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 1);
        } catch (e) {
            console.error('Error playing sound:', e);
        }
    }
    
    // Function para mag-check ng geofence status
    function checkGeofenceStatus() {
        fetch('../api/get-location.php?rider_id=' + riderId, {
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.geofence && data.geofence.active) {
                const status = data.geofence.status;
                const distance = data.geofence.distance_to_boundary_m;
                
                // Check kung may pagbabago sa status
                if (status !== lastAlertStatus) {
                    if (status === 'outside') {
                        showAlert(
                            '⚠️ ALERT: Outside Allowed Zone!',
                            'You have left the operational perimeter. Return immediately to avoid penalties.',
                            'danger'
                        );
                        saveAlertToDatabase('outside', distance, data);
                    } else if (status === 'warning') {
                        showAlert(
                            '⚠️ Warning: Approaching Boundary',
                            distance ? `You are ${distance}m from the edge. Turn back to stay inside.` : 'You are near the edge of the allowed zone.',
                            'warning'
                        );
                        saveAlertToDatabase('warning', distance, data);
                    } else if (status === 'inside' && (lastAlertStatus === 'outside' || lastAlertStatus === 'warning')) {
                        showAlert(
                            '✅ You are back inside',
                            'You are now inside the allowed perimeter.',
                            'success'
                        );
                    }
                    
                    lastAlertStatus = status;
                }
            }
        })
        .catch(error => {
            console.error('Error checking geofence:', error);
        });
    }
    
    // Save alert sa database para makita ng admin
    function saveAlertToDatabase(status, distance, data) {
        fetch('../api/save-rider-alert.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                rider_id: riderId,
                status: status,
                distance: distance || null,
                latitude: data.lat || null,
                longitude: data.lng || null
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Alert saved:', data);
        })
        .catch(error => {
            console.error('Error saving alert:', error);
        });
    }
    
    // Add CSS animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes riderAlertSlideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    // Check agad
    checkGeofenceStatus();
    
    // Check every 5 seconds
    setInterval(checkGeofenceStatus, 5000);
    
    // Expose functions
    window.RiderGlobalAlerts = {
        showAlert: showAlert,
        checkGeofenceStatus: checkGeofenceStatus
    };
})();