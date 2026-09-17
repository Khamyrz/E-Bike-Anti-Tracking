/**
 * Shared geofence helpers for admin/rider Leaflet maps.
 * Requires Turf.js and (optionally) SweetAlert2.
 */
(function(window) {
    'use strict';

    var WARNING_METERS = 150;

    /** Bantayan Island, Cebu — default map focus for admin/rider maps */
    var BANTAYAN_CENTER = [11.1714, 123.7333];
    var BANTAYAN_ZOOM = 12;
    var BANTAYAN_MAX_BOUNDS = [
        [11.05, 123.55],
        [11.30, 123.90]
    ];

    function coordsFromPoints(points) {
        return points.map(function(p) {
            return [p.lng, p.lat];
        });
    }

    function closeRing(coords) {
        if (!coords.length) {
            return coords;
        }
        var first = coords[0];
        var last = coords[coords.length - 1];
        if (first[0] !== last[0] || first[1] !== last[1]) {
            coords.push(first.slice());
        }
        return coords;
    }

    function evaluate(lat, lng, points, warningMeters) {
        warningMeters = warningMeters || WARNING_METERS;

        if (!points || points.length < 3 || typeof turf === 'undefined') {
            return {
                active: false,
                status: 'none',
                inside: true,
                distance_to_boundary_m: null
            };
        }

        var ring = closeRing(coordsFromPoints(points));
        var poly = turf.polygon([ring]);
        var pt = turf.point([lng, lat]);
        var inside = turf.booleanPointInPolygon(pt, poly);
        var distanceM = distanceToBoundaryM(lat, lng, points);
        var status = 'inside';

        if (!inside) {
            status = 'outside';
        } else if (distanceM !== null && distanceM <= warningMeters) {
            status = 'warning';
        }

        return {
            active: true,
            status: status,
            inside: inside,
            distance_to_boundary_m: distanceM !== null ? Math.round(distanceM * 10) / 10 : null,
            warning_meters: warningMeters
        };
    }

    function distanceToBoundaryM(lat, lng, points) {
        if (!points || points.length < 2 || typeof turf === 'undefined') {
            return null;
        }

        var pt = turf.point([lng, lat]);
        var min = Infinity;

        for (var i = 0; i < points.length; i++) {
            var a = points[i];
            var b = points[(i + 1) % points.length];
            var line = turf.lineString([[a.lng, a.lat], [b.lng, b.lat]]);
            var km = turf.pointToLineDistance(pt, line, { units: 'kilometers' });
            if (km < min) {
                min = km;
            }
        }

        return min === Infinity ? null : min * 1000;
    }

    function removeLayers(store) {
        if (!store || !store.map) {
            return;
        }

        ['perimeterPolygon', 'restrictedAreaPolygon', 'glowPolygon'].forEach(function(key) {
            if (store[key] && store.map.hasLayer(store[key])) {
                store.map.removeLayer(store[key]);
            }
            store[key] = null;
        });
    }

    function drawPerimeter(map, points, store) {
        store = store || {};
        store.map = map;
        removeLayers(store);

        if (!points || points.length < 3) {
            return store;
        }

        var leafletPoints = points.map(function(p) {
            return [p.lat, p.lng];
        });

        store.perimeterPolygon = L.polygon(leafletPoints, {
            color: '#F59E0B',
            weight: 4,
            fill: false,
            opacity: 0.95
        }).addTo(map);

        store.glowPolygon = L.polygon(leafletPoints, {
            color: '#F59E0B',
            weight: 10,
            fill: false,
            opacity: 0.15
        }).addTo(map);

        var outerBounds = [
            [-90, -180],
            [-90, 180],
            [90, 180],
            [90, -180]
        ];
        var holePoints = leafletPoints.slice().reverse();

        store.restrictedAreaPolygon = L.polygon([outerBounds, holePoints], {
            stroke: false,
            fillColor: '#EF4444',
            fillOpacity: 0.10,
            interactive: false
        }).addTo(map);

        return store;
    }

    function statusLabel(status) {
        if (status === 'outside') return 'Outside zone';
        if (status === 'warning') return 'Near boundary';
        if (status === 'inside') return 'Inside zone';
        return 'No perimeter';
    }

    function showPopup(status, options) {
        options = options || {};
        if (typeof Swal === 'undefined') {
            if (options.fallbackAlert) {
                window.alert(options.fallbackAlert);
            }
            return;
        }

        var config = {
            background: '#111827',
            color: '#F1F5F9',
            confirmButtonColor: '#3B82F6',
            allowOutsideClick: false
        };

        if (status === 'outside') {
            Swal.fire(Object.assign({}, config, {
                icon: 'error',
                title: options.title || 'Outside Allowed Zone',
                text: options.text || 'You have left the operational perimeter. Return immediately.',
                confirmButtonText: 'OK'
            }));
            return;
        }

        if (status === 'warning') {
            Swal.fire(Object.assign({}, config, {
                icon: 'warning',
                title: options.title || 'Approaching Boundary',
                text: options.text || 'You are close to the edge of the allowed zone. Turn back to stay inside.',
                confirmButtonText: 'Got it'
            }));
        }
    }

    /**
     * Fire popup only when status changes to reduce spam during polling.
     */
    function handleStatusChange(status, stateKey, stateStore, popupOptions) {
        stateStore = stateStore || {};
        var previous = stateStore[stateKey];

        if (status === 'outside' && previous !== 'outside') {
            showPopup('outside', popupOptions);
        } else if (status === 'warning' && previous !== 'warning' && previous !== 'outside') {
            showPopup('warning', popupOptions);
        }

        if (status !== 'none') {
            stateStore[stateKey] = status;
        }

        return stateStore;
    }

    window.GeofenceMap = {
        WARNING_METERS: WARNING_METERS,
        BANTAYAN_CENTER: BANTAYAN_CENTER,
        BANTAYAN_ZOOM: BANTAYAN_ZOOM,
        BANTAYAN_MAX_BOUNDS: BANTAYAN_MAX_BOUNDS,
        evaluate: evaluate,
        distanceToBoundaryM: distanceToBoundaryM,
        drawPerimeter: drawPerimeter,
        removeLayers: removeLayers,
        statusLabel: statusLabel,
        showPopup: showPopup,
        handleStatusChange: handleStatusChange
    };
})(window);
