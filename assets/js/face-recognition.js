(function (global) {
    'use strict';

    var MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';
    var modelsReady = false;
    var modelsLoading = null;

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
                return Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
            })
            .then(function () {
                modelsReady = true;
                console.log('Face recognition models loaded successfully');
            })
            .catch(function (err) {
                modelsLoading = null;
                console.error('Failed to load face recognition models:', err);
                throw err;
            });

        return modelsLoading;
    }

    function detectSingleFace(input) {
        var options = new faceapi.TinyFaceDetectorOptions({
            inputSize: 416,
            scoreThreshold: 0.5
        });

        return faceapi
            .detectSingleFace(input, options)
            .withFaceLandmarks()
            .withFaceDescriptor();
    }

    function extractFromVideo(videoEl) {
        return loadModels().then(function () {
            return detectSingleFace(videoEl);
        }).then(function (result) {
            if (!result) {
                throw new Error('No face detected. Center your face in the camera and try again.');
            }

            // Convert descriptor to array of floats
            var descriptor = Array.from(result.descriptor);
            
            // Log for debugging
            console.log('Face descriptor extracted, length:', descriptor.length);
            
            return {
                descriptor: descriptor,
                detection: result.detection
            };
        });
    }

    function buildStoredFacePayload(imageDataUrl, descriptor) {
        // Ensure descriptor is an array
        if (!Array.isArray(descriptor)) {
            throw new Error('Descriptor must be an array');
        }
        
        // Convert all values to numbers
        var normalizedDescriptor = descriptor.map(function(val) {
            return parseFloat(val);
        });
        
        var payload = {
            image: imageDataUrl,
            descriptor: normalizedDescriptor
        };
        
        return JSON.stringify(payload);
    }

    function parseStoredFacePayload(raw) {
        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.descriptor) || parsed.descriptor.length < 64) {
                console.error('Invalid face data format');
                return null;
            }
            return parsed;
        } catch (e) {
            console.error('Failed to parse face data:', e);
            return null;
        }
    }

    function compareFaces(descriptor1, descriptor2) {
        // Calculate Euclidean distance
        if (!Array.isArray(descriptor1) || !Array.isArray(descriptor2)) {
            return Infinity;
        }
        
        var length = Math.min(descriptor1.length, descriptor2.length);
        if (length === 0) {
            return Infinity;
        }
        
        var sum = 0;
        for (var i = 0; i < length; i++) {
            var diff = parseFloat(descriptor1[i]) - parseFloat(descriptor2[i]);
            sum += diff * diff;
        }
        
        return Math.sqrt(sum);
    }

    // Expose the FaceRecognition object globally
    global.FaceRecognition = {
        loadModels: loadModels,
        extractFromVideo: extractFromVideo,
        buildStoredFacePayload: buildStoredFacePayload,
        parseStoredFacePayload: parseStoredFacePayload,
        compareFaces: compareFaces,
        isModelsReady: function() { return modelsReady; }
    };
    
    console.log('FaceRecognition module loaded successfully');
})(window);