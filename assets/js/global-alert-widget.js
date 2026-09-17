// assets/js/global-alert-widget.js
// Simple global alert widget para sa lahat ng pages

(function() {
    function createWidget() {
        if (document.getElementById('globalAlertWidget')) return;
        
        var widget = document.createElement('div');
        widget.id = 'globalAlertWidget';
        widget.style.cssText = 'position:fixed;top:60px;right:10px;z-index:9999;display:flex;flex-direction:column;gap:8px;max-width:350px;pointer-events:none;';
        document.body.appendChild(widget);
    }
    
    function showAlert(alert) {
        createWidget();
        
        var widget = document.getElementById('globalAlertWidget');
        if (!widget) return;
        
        // Check duplicate
        var existing = widget.querySelectorAll('.global-alert');
        for (var i = 0; i < existing.length; i++) {
            if (existing[i].getAttribute('data-id') === String(alert.id)) return;
        }
        
        var div = document.createElement('div');
        div.className = 'global-alert';
        div.setAttribute('data-id', alert.id);
        div.style.cssText = 'pointer-events:auto;background:rgba(17,24,39,0.95);border:1px solid #ef4444;border-left:4px solid #ef4444;color:#f0f4ff;padding:12px 16px;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.4);cursor:pointer;font-family:Inter,sans-serif;';
        
        var status = alert.is_online == 1 ? '🟢 Online' : '🔴 Offline';
        
        div.innerHTML = '<div style="display:flex;align-items:center;gap:10px;">' +
            '<span style="font-size:20px;">⚠️</span>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:600;font-size:13px;margin-bottom:2px;">' + alert.rider_name + ' - Outside Perimeter</div>' +
            '<div style="font-size:12px;opacity:0.9;">' + status + ' | E-Bike #' + alert.ebike_id + '</div>' +
            '<div style="font-size:10px;opacity:0.7;margin-top:2px;">📍 ' + alert.latitude + ', ' + alert.longitude + '</div>' +
            '</div>' +
            '<button onclick="dismissAlert(' + alert.id + ', this)" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:18px;padding:0;">×</button>' +
            '</div>';
        
        div.addEventListener('click', function(e) {
            if (e.target.tagName !== 'BUTTON') {
                window.location.href = 'map.php?focus_rider=' + alert.rider_id;
            }
        });
        
        widget.appendChild(div);
    }
    
    window.dismissAlert = function(alertId, btn) {
        fetch('../api/dismiss-alert.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ alert_id: alertId })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var alertElement = btn.closest('.global-alert');
                if (alertElement) {
                    alertElement.style.opacity = '0';
                    setTimeout(function() { alertElement.remove(); }, 300);
                }
            }
        });
    };
    
    function checkAlerts() {
        fetch('../api/get-alerts.php', {
            cache: 'no-store'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.alerts.length > 0) {
                data.alerts.forEach(function(alert) {
                    showAlert(alert);
                });
            }
        })
        .catch(function(error) {
            console.error('Error checking alerts:', error);
        });
    }
    
    // Check agad
    checkAlerts();
    
    // Check every 10 seconds
    setInterval(checkAlerts, 10000);
})();