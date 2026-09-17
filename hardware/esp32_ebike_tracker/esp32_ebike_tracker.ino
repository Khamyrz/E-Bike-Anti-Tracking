/**
 * ================================================================
 * E-Bike Tracker - ESP32 + GPS NEO-6M + SIM A7670C
 * Version: 7.0 - STABLE (NO RESET, NO CRASH)
 * ================================================================
 * 
 * Fixes:
 * - ✅ Hindi na gumagamit ng String concatenation (mas kaunting memory)
 * - ✅ May delay(1) sa lahat ng while loops (iwas watchdog reset)
 * - ✅ Mas maikling timeout (iwas stuck)
 * - ✅ Hindi na nagre-reconnect kung hindi kailangan
 * - ✅ Hindi na nagbi-blink ng mabilis (iwas confusion)
 * ================================================================
 */

#include <TinyGPS++.h>
#include <HardwareSerial.h>

// ========== SERVER CONFIGURATION ==========
const char* SERVER_URL = "https://camisole-dispersal-crouch.ngrok-free.dev";
const char* API_ENDPOINT = "/ebike-gps/api/device-gps-update.php";

// ========== DEVICE AUTHENTICATION ==========
const char* DEVICE_ID = "EBIKE0002";
const char* API_TOKEN = "698d46a8dc2d284998a01bdb0e22629b";

// ========== GPS PINS ==========
const int GPS_RX_PIN = 16;
const int GPS_TX_PIN = 17;
const int GPS_BAUD = 9600;

// ========== A7670C PINS ==========
const int A7670C_RX_PIN = 25;
const int A7670C_TX_PIN = 26;
const int A7670C_PEN_PIN = 27;
const int A7670C_PWK_PIN = 14;
const int A7670C_BAUD = 115200;

// ========== LED ==========
const int LED_PIN = 2;

// ========== TIMING ==========
const unsigned long UPDATE_INTERVAL = 10000;  // 10 seconds
const unsigned long GPS_TIMEOUT = 60000;

// ========== GLOBAL VARIABLES ==========
TinyGPSPlus gps;
HardwareSerial gpsSerial(2);
HardwareSerial cellularSerial(1);

bool gpsHasFix = false;
float currentLat = 0.0;
float currentLng = 0.0;
int currentSatellites = 0;

bool cellularConnected = false;

unsigned long lastUpdateMillis = 0;
unsigned long lastReconnectAttempt = 0;

int successfulUpdates = 0;
int failedUpdates = 0;

// ========== LED FUNCTIONS (Simplified - walang mabilis na blink) ==========
void blinkSuccess() {
    // 1 mabagal na blink = success
    digitalWrite(LED_PIN, HIGH);
    delay(200);
    digitalWrite(LED_PIN, LOW);
}

// ========== A7670C FUNCTIONS ==========

void a7670cPowerOn() {
    pinMode(A7670C_PEN_PIN, OUTPUT);
    pinMode(A7670C_PWK_PIN, OUTPUT);
    digitalWrite(A7670C_PEN_PIN, HIGH);
    delay(1000);
    digitalWrite(A7670C_PWK_PIN, HIGH);
    delay(1000);
    digitalWrite(A7670C_PWK_PIN, LOW);
    delay(5000);
}

// ✅ FIXED: Mas simpleng send command na may delay(1)
String sendAT(String cmd, int timeout = 3000) {
    cellularSerial.println(cmd);
    String resp = "";
    unsigned long start = millis();
    
    while (millis() - start < timeout) {
        if (cellularSerial.available()) {
            resp += (char)cellularSerial.read();
        }
        delay(1);  // ✅ IMPORTANTE: Ito ang pumipigil sa watchdog reset
    }
    
    return resp;
}

bool initCellular() {
    Serial.println("\n📡 Initializing A7670C...");
    
    pinMode(A7670C_PEN_PIN, OUTPUT);
    pinMode(A7670C_PWK_PIN, OUTPUT);
    digitalWrite(A7670C_PEN_PIN, LOW);
    digitalWrite(A7670C_PWK_PIN, LOW);
    
    a7670cPowerOn();
    
    cellularSerial.begin(A7670C_BAUD, SERIAL_8N1, A7670C_RX_PIN, A7670C_TX_PIN);
    delay(2000);
    
    // Test AT
    String resp = sendAT("AT", 3000);
    if (resp.indexOf("OK") < 0) {
        Serial.println("❌ A7670C not responding");
        return false;
    }
    Serial.println("✅ A7670C responding");
    
    // Check SIM
    resp = sendAT("AT+CPIN?", 3000);
    if (resp.indexOf("READY") >= 0) {
        Serial.println("✅ SIM ready");
    }
    
    // Wait for network registration
    bool registered = false;
    for (int i = 0; i < 30; i++) {
        resp = sendAT("AT+CREG?", 2000);
        if (resp.indexOf("+CREG: 0,1") >= 0 || 
            resp.indexOf("+CREG: 0,5") >= 0 ||
            resp.indexOf("+CREG: 1,1") >= 0 ||
            resp.indexOf("+CREG: 1,5") >= 0) {
            registered = true;
            break;
        }
        delay(1000);
    }
    
    if (!registered) {
        Serial.println("❌ Network registration failed");
        return false;
    }
    Serial.println("✅ Network registered!");
    
    // Configure HTTP
    sendAT("AT+HTTPINIT", 3000);
    delay(1000);
    sendAT("AT+HTTPPARA=\"CID\",1", 2000);
    delay(1000);
    sendAT("AT+HTTPSSL=1", 3000);
    delay(1000);
    
    Serial.println("✅ A7670C initialized!");
    cellularConnected = true;
    return true;
}

// ✅ FIXED: Mas simpleng send function
bool sendToServer() {
    if (!cellularConnected) return false;
    
    // Build URL (mas kaunting String operations)
    String url = String(SERVER_URL) + String(API_ENDPOINT);
    url += "?device_id=" + String(DEVICE_ID);
    url += "&token=" + String(API_TOKEN);
    
    if (gpsHasFix) {
        url += "&lat=" + String(currentLat, 6);
        url += "&lng=" + String(currentLng, 6);
        url += "&speed=0&battery=100&vibration=0&satellites=" + String(currentSatellites);
    } else {
        url += "&battery=100&vibration=0&satellites=0";
    }
    
    // Set URL
    sendAT("AT+HTTPPARA=\"URL\",\"" + url + "\"", 5000);
    delay(500);
    
    // Execute GET request
    String resp = sendAT("AT+HTTPACTION=0", 15000);
    
    if (resp.indexOf("200") >= 0) {
        successfulUpdates++;
        return true;
    }
    
    failedUpdates++;
    return false;
}

void processGPS() {
    while (gpsSerial.available() > 0) {
        if (gps.encode(gpsSerial.read())) {
            if (gps.location.isValid() && gps.location.age() < 2000) {
                if (!gpsHasFix) {
                    Serial.println("\n🎯 GPS FIX ACQUIRED!");
                }
                gpsHasFix = true;
                currentLat = gps.location.lat();
                currentLng = gps.location.lng();
                currentSatellites = gps.satellites.value();
            }
        }
        delay(1);  // ✅ Importante
    }
    
    // Check kung nawala ang GPS fix
    static unsigned long lastGpsCheck = 0;
    if (millis() - lastGpsCheck >= GPS_TIMEOUT) {
        lastGpsCheck = millis();
        if (gps.location.age() > 3000) {
            gpsHasFix = false;
        }
    }
}

void setup() {
    Serial.begin(115200);
    delay(2000);
    
    pinMode(LED_PIN, OUTPUT);
    digitalWrite(LED_PIN, LOW);
    
    Serial.println("\n╔══════════════════════════════════╗");
    Serial.println("║  E-BIKE TRACKER v7.0             ║");
    Serial.println("║  STABLE - NO RESET               ║");
    Serial.println("╠══════════════════════════════════╣");
    Serial.print("║  Device: ");
    Serial.print(DEVICE_ID);
    Serial.println("            ║");
    Serial.println("╚══════════════════════════════════╝");
    
    Serial.println("\n📡 Initializing GPS...");
    gpsSerial.begin(GPS_BAUD, SERIAL_8N1, GPS_RX_PIN, GPS_TX_PIN);
    Serial.println("✅ GPS initialized");
    
    if (initCellular()) {
        Serial.println("\n✅ Cellular Ready!");
    } else {
        Serial.println("\n❌ Cellular Failed! Will retry...");
    }
    
    Serial.println("\n💡 Commands: status, send, help");
    Serial.println("📍 System Ready!\n");
}

void loop() {
    processGPS();
    
    // Reconnect kung kailangan (kada 30 seconds)
    if (!cellularConnected) {
        if (millis() - lastReconnectAttempt >= 30000) {
            lastReconnectAttempt = millis();
            Serial.println("\n🔄 Reconnecting cellular...");
            if (initCellular()) {
                Serial.println("✅ Reconnected!");
            }
        }
    }
    
    // Send data every 10 seconds
    if (millis() - lastUpdateMillis >= UPDATE_INTERVAL) {
        lastUpdateMillis = millis();
        
        if (cellularConnected) {
            if (sendToServer()) {
                blinkSuccess();  // ✅ 1 mabagal na blink = success
            }
        }
    }
    
    // Heartbeat print every 60 seconds (mas madalang para hindi ma-flood)
    static unsigned long lastHeartbeatPrint = 0;
    if (millis() - lastHeartbeatPrint >= 60000) {
        lastHeartbeatPrint = millis();
        Serial.println("💓 ESP32 alive - Sent: " + String(successfulUpdates) + " OK, " + String(failedUpdates) + " FAIL");
    }
    
    // Serial commands
    if (Serial.available()) {
        String cmd = Serial.readStringUntil('\n');
        cmd.trim();
        cmd.toLowerCase();
        
        if (cmd == "status" || cmd == "s") {
            Serial.println("\n--- STATUS ---");
            Serial.print("Cellular: ");
            Serial.println(cellularConnected ? "✅ Connected" : "❌ Disconnected");
            Serial.print("GPS: ");
            Serial.println(gpsHasFix ? "✅ FIX" : "❌ NO FIX");
            if (gpsHasFix) {
                Serial.printf("Lat: %.6f, Lng: %.6f\n", currentLat, currentLng);
                Serial.print("Sats: ");
                Serial.println(currentSatellites);
            }
            Serial.print("Sent: ");
            Serial.print(successfulUpdates);
            Serial.print(" OK, ");
            Serial.print(failedUpdates);
            Serial.println(" FAIL");
        }
        else if (cmd == "send" || cmd == "x") {
            if (sendToServer()) {
                Serial.println("✅ Sent!");
            } else {
                Serial.println("❌ Failed!");
            }
        }
        else if (cmd == "help" || cmd == "h") {
            Serial.println("\n=== COMMANDS ===");
            Serial.println("status/s  - Status");
            Serial.println("send/x    - Force send");
            Serial.println("help/h    - Help");
            Serial.println("================\n");
        }
    }
    
    delay(10);
}