(function () {
    if (!window.RIDER_ID || !navigator.geolocation) {
        return;
    }

    var MIN_INTERVAL_MS = 5000;
    var MIN_DISTANCE_M = 8;
    var lastSentAt = 0;
    var lastLat = null;
    var lastLng = null;

    function toRad(value) {
        return value * Math.PI / 180;
    }

    function distanceMeters(lat1, lng1, lat2, lng2) {
        var earthRadius = 6371000;
        var dLat = toRad(lat2 - lat1);
        var dLng = toRad(lng2 - lng1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function shouldSend(lat, lng) {
        var now = Date.now();
        if (now - lastSentAt < MIN_INTERVAL_MS) {
            return false;
        }

        if (lastLat === null || lastLng === null) {
            return true;
        }

        return distanceMeters(lastLat, lastLng, lat, lng) >= MIN_DISTANCE_M;
    }

    function sendLocation(lat, lng, speed) {
        fetch('../api/gps-update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'rider_id=' + encodeURIComponent(window.RIDER_ID) +
                '&lat=' + encodeURIComponent(lat) +
                '&lng=' + encodeURIComponent(lng) +
                '&speed=' + encodeURIComponent(speed || 0) +
                '&battery=' + encodeURIComponent(100)
        }).catch(function () {
            console.warn('Could not save rider location');
        });
    }

    function handlePosition(position) {
        var lat = position.coords.latitude;
        var lng = position.coords.longitude;

        if (!shouldSend(lat, lng)) {
            if (typeof window.onRiderLocationUpdate === 'function') {
                window.onRiderLocationUpdate(lat, lng, position, false);
            }
            return;
        }

        lastSentAt = Date.now();
        lastLat = lat;
        lastLng = lng;
        sendLocation(lat, lng, position.coords.speed);

        if (typeof window.onRiderLocationUpdate === 'function') {
            window.onRiderLocationUpdate(lat, lng, position, true);
        }
    }

    navigator.geolocation.watchPosition(handlePosition, function () {
        console.warn('Rider geolocation unavailable');
    }, {
        enableHighAccuracy: true,
        timeout: 20000,
        maximumAge: 0
    });
})();
