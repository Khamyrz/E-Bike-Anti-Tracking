(function (global) {
    'use strict';

    // ================================================================
    // SECURITY CONFIGURATION - DO NOT MODIFY WITHOUT REVIEW
    // ================================================================

    /**
     * MODEL_URL - Face-api.js model weights location
     */
    var MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';

    /**
     * FACE_DETECTION_THRESHOLD - Minimum detection confidence required
     * Higher values ensure better quality face detections
     * Recommended: 0.6 for production
     */
    var FACE_DETECTION_THRESHOLD = 0.6;

    /**
     * FACE_MATCH_THRESHOLD - Maximum distance for a face match
     * Must match the PHP constant FACE_MATCH_MAX_DISTANCE
     * Value: 0.45 for strict security
     */
    var FACE_MATCH_THRESHOLD = 0.45;

    /**
     * FACE_MIN_DESCRIPTOR_LENGTH - Minimum descriptor length
     * face-api.js returns 128 values
     */
    var FACE_MIN_DESCRIPTOR_LENGTH = 64;

    /**
     * FACE_MAX_DESCRIPTOR_LENGTH - Maximum descriptor length
     */
    var FACE_MAX_DESCRIPTOR_LENGTH = 128;

    /**
     * FACE_DESCRIPTOR_VALUE_RANGE - Valid range for descriptor values
     */
    var FACE_DESCRIPTOR_VALUE_RANGE_MIN = -2;
    var FACE_DESCRIPTOR_VALUE_RANGE_MAX = 2;

    // State
    var modelsReady = false;
    var modelsLoading = null;

    // ================================================================
    // CORE FUNCTIONS
    // ================================================================

    function ensureFaceApi() {
        if (typeof faceapi === 'undefined') {
            return Promise.reject(new Error('face-api.js is not loaded'));
        }
        return Promise.resolve();
    }

    function loadModels() {
        if (modelsReady) {
            return Promise.resolve();
        }
        if (modelsLoading) {
            return modelsLoading;
        }

        modelsLoading = ensureFaceApi()
            .then(function () {
                console.log('Loading face recognition models...');
                return Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
            })
            .then(function () {
                modelsReady = true;
                console.log('Face recognition models loaded successfully');
                console.log('Detection threshold:', FACE_DETECTION_THRESHOLD);
                console.log('Match threshold:', FACE_MATCH_THRESHOLD);
            })
            .catch(function (err) {
                modelsLoading = null;
                console.error('Failed to load face recognition models:', err);
                throw err;
            });

        return modelsLoading;
    }

    /**
     * Detect single face with strict options
     */
    function detectSingleFace(input) {
        // ============================================================
        // SECURITY: Stricter detection options
        // - Larger input size for better accuracy
        // - Higher score threshold for quality
        // ============================================================
        var options = new faceapi.TinyFaceDetectorOptions({
            inputSize: 512,          // Increased from 416 for better accuracy
            scoreThreshold: 0.6      // Increased from 0.5 for stricter detection
        });

        return faceapi
            .detectSingleFace(input, options)
            .withFaceLandmarks()
            .withFaceDescriptor();
    }

    /**
     * Extract face descriptor from video with strict validation
     */
    function extractFromVideo(videoEl) {
        return loadModels().then(function () {
            return detectSingleFace(videoEl);
        }).then(function (result) {
            // ============================================================
            // SECURITY CHECK 1: Face detection
            // ============================================================
            if (!result) {
                throw new Error('No face detected. Center your face in the camera and try again.');
            }

            // ============================================================
            // SECURITY CHECK 2: Detection confidence
            // ============================================================
            var detection = result.detection;
            var confidence = detection ? detection.score || 0 : 0;
            
            if (confidence < FACE_DETECTION_THRESHOLD) {
                throw new Error(
                    'Face detection confidence too low (' + 
                    Math.round(confidence * 100) + '%). ' +
                    'Please ensure good lighting and face is centered.'
                );
            }

            // ============================================================
            // SECURITY CHECK 3: Descriptor extraction
            // ============================================================
            var descriptor = Array.from(result.descriptor);
            
            if (!Array.isArray(descriptor)) {
                throw new Error('Invalid descriptor format');
            }

            if (descriptor.length < FACE_MIN_DESCRIPTOR_LENGTH) {
                throw new Error(
                    'Invalid face descriptor length: ' + descriptor.length + 
                    '. Required: at least ' + FACE_MIN_DESCRIPTOR_LENGTH
                );
            }

            if (descriptor.length > FACE_MAX_DESCRIPTOR_LENGTH) {
                throw new Error(
                    'Invalid face descriptor length: ' + descriptor.length + 
                    '. Maximum: ' + FACE_MAX_DESCRIPTOR_LENGTH
                );
            }

            // ============================================================
            // SECURITY CHECK 4: Descriptor value range validation
            // ============================================================
            var hasInvalidValues = false;
            var invalidIndex = -1;
            
            for (var i = 0; i < descriptor.length; i++) {
                var val = parseFloat(descriptor[i]);
                if (isNaN(val) || 
                    val < FACE_DESCRIPTOR_VALUE_RANGE_MIN || 
                    val > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
                    hasInvalidValues = true;
                    invalidIndex = i;
                    break;
                }
            }
            
            if (hasInvalidValues) {
                throw new Error(
                    'Invalid descriptor value at index ' + invalidIndex + 
                    '. Values must be between ' + FACE_DESCRIPTOR_VALUE_RANGE_MIN + 
                    ' and ' + FACE_DESCRIPTOR_VALUE_RANGE_MAX
                );
            }

            // ============================================================
            // SUCCESS: All validations passed
            // ============================================================
            console.log('Face extracted successfully:');
            console.log('  - Confidence:', Math.round(confidence * 100) + '%');
            console.log('  - Descriptor length:', descriptor.length);
            console.log('  - First 5 values:', descriptor.slice(0, 5).map(function(v) { 
                return v.toFixed(6); 
            }).join(', '));
            
            return {
                descriptor: descriptor,
                detection: result.detection,
                confidence: confidence,
                landmarks: result.landmarks
            };
        });
    }

    /**
     * Build stored face payload for database
     */
    function buildStoredFacePayload(imageDataUrl, descriptor) {
        // ============================================================
        // SECURITY: Validate inputs before building payload
        // ============================================================
        if (!imageDataUrl || typeof imageDataUrl !== 'string') {
            throw new Error('Invalid image data');
        }

        if (imageDataUrl.indexOf('data:image') !== 0) {
            throw new Error('Invalid image format');
        }

        if (!Array.isArray(descriptor)) {
            throw new Error('Descriptor must be an array');
        }

        if (descriptor.length < FACE_MIN_DESCRIPTOR_LENGTH) {
            throw new Error(
                'Descriptor too short: ' + descriptor.length + 
                '. Minimum: ' + FACE_MIN_DESCRIPTOR_LENGTH
            );
        }

        // Normalize and validate descriptor values
        var normalizedDescriptor = descriptor.map(function(val) {
            var num = parseFloat(val);
            if (isNaN(num)) {
                throw new Error('Invalid number in descriptor');
            }
            if (num < FACE_DESCRIPTOR_VALUE_RANGE_MIN || num > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
                throw new Error(
                    'Descriptor value out of range: ' + num +
                    '. Must be between ' + FACE_DESCRIPTOR_VALUE_RANGE_MIN +
                    ' and ' + FACE_DESCRIPTOR_VALUE_RANGE_MAX
                );
            }
            return num;
        });

        var payload = {
            image: imageDataUrl,
            descriptor: normalizedDescriptor,
            version: '1.0',
            timestamp: new Date().toISOString()
        };

        return JSON.stringify(payload);
    }

    /**
     * Parse stored face payload from database
     */
    function parseStoredFacePayload(raw) {
        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);
            
            if (!parsed || typeof parsed !== 'object') {
                console.error('Invalid payload structure');
                return null;
            }

            if (!Array.isArray(parsed.descriptor)) {
                console.error('Descriptor is not an array');
                return null;
            }

            if (parsed.descriptor.length < FACE_MIN_DESCRIPTOR_LENGTH) {
                console.error(
                    'Descriptor too short: ' + parsed.descriptor.length +
                    '. Minimum: ' + FACE_MIN_DESCRIPTOR_LENGTH
                );
                return null;
            }

            // Validate descriptor values
            for (var i = 0; i < parsed.descriptor.length; i++) {
                var val = parseFloat(parsed.descriptor[i]);
                if (isNaN(val) || 
                    val < FACE_DESCRIPTOR_VALUE_RANGE_MIN || 
                    val > FACE_DESCRIPTOR_VALUE_RANGE_MAX) {
                    console.error('Invalid descriptor value at index:', i, val);
                    return null;
                }
            }

            return parsed;
        } catch (e) {
            console.error('Failed to parse face data:', e);
            return null;
        }
    }

    /**
     * Calculate Euclidean distance between two face descriptors
     */
    function compareFaces(descriptor1, descriptor2) {
        if (!Array.isArray(descriptor1) || !Array.isArray(descriptor2)) {
            console.error('Invalid descriptors for comparison');
            return Infinity;
        }

        if (descriptor1.length < FACE_MIN_DESCRIPTOR_LENGTH || 
            descriptor2.length < FACE_MIN_DESCRIPTOR_LENGTH) {
            console.error('Descriptors too short for comparison');
            return Infinity;
        }

        var length = Math.min(descriptor1.length, descriptor2.length);
        var sum = 0;
        
        for (var i = 0; i < length; i++) {
            var val1 = parseFloat(descriptor1[i]);
            var val2 = parseFloat(descriptor2[i]);
            
            if (isNaN(val1) || isNaN(val2)) {
                console.error('Invalid value at index:', i);
                return Infinity;
            }
            
            var diff = val1 - val2;
            sum += diff * diff;
        }

        var distance = Math.sqrt(sum);
        console.log('Face distance:', distance.toFixed(6));
        return distance;
    }

    /**
     * Check if two faces match within threshold
     */
    function isFaceMatch(descriptor1, descriptor2) {
        var distance = compareFaces(descriptor1, descriptor2);
        
        if (!isFinite(distance)) {
            console.error('Invalid distance for match check');
            return false;
        }

        var matches = distance <= FACE_MATCH_THRESHOLD;
        
        console.log(
            'Match check: distance=' + distance.toFixed(6) + 
            ', threshold=' + FACE_MATCH_THRESHOLD + 
            ', result=' + (matches ? 'MATCH' : 'NO MATCH')
        );
        
        return matches;
    }

    /**
     * Validate face data structure
     */
    function validateFaceData(faceData) {
        if (!faceData) {
            return { valid: false, reason: 'Empty data' };
        }

        try {
            var parsed = parseStoredFacePayload(faceData);
            if (!parsed) {
                return { valid: false, reason: 'Failed to parse' };
            }

            return {
                valid: true,
                hasImage: !!parsed.image,
                descriptorLength: parsed.descriptor.length
            };
        } catch (e) {
            return { valid: false, reason: e.message };
        }
    }

    // ================================================================
    // EXPOSE API
    // ================================================================

    global.FaceRecognition = {
        // Core functions
        loadModels: loadModels,
        extractFromVideo: extractFromVideo,
        
        // Payload handling
        buildStoredFacePayload: buildStoredFacePayload,
        parseStoredFacePayload: parseStoredFacePayload,
        
        // Comparison functions
        compareFaces: compareFaces,
        isFaceMatch: isFaceMatch,
        
        // Validation
        validateFaceData: validateFaceData,
        
        // Getters for security settings
        isModelsReady: function() { return modelsReady; },
        getDetectionThreshold: function() { return FACE_DETECTION_THRESHOLD; },
        getMatchThreshold: function() { return FACE_MATCH_THRESHOLD; },
        getMinDescriptorLength: function() { return FACE_MIN_DESCRIPTOR_LENGTH; },
        
        // Debug info
        getConfig: function() {
            return {
                detectionThreshold: FACE_DETECTION_THRESHOLD,
                matchThreshold: FACE_MATCH_THRESHOLD,
                minDescriptorLength: FACE_MIN_DESCRIPTOR_LENGTH,
                maxDescriptorLength: FACE_MAX_DESCRIPTOR_LENGTH,
                valueRange: {
                    min: FACE_DESCRIPTOR_VALUE_RANGE_MIN,
                    max: FACE_DESCRIPTOR_VALUE_RANGE_MAX
                },
                modelsReady: modelsReady
            };
        }
    };
    
    console.log('FaceRecognition module loaded successfully');
    console.log('Configuration:', global.FaceRecognition.getConfig());

})(window);