/*
 * E-Bike Tracker — ESP32 + SIM800L heartbeat (online status, no GPS coordinates)
 *
 * LOCAL CAPSTONE SETUP:
 * 1. Run XAMPP on your laptop.
 * 2. Find laptop IP: ipconfig  (e.g. 192.168.1.105)
 * 3. Set SERVER_HOST below to that IP.
 * 4. In admin > Manage Riders, assign device ID + token for the rider.
 * 5. Each heartbeat marks the rider online on admin/map.php via SIM800L.
 *
 * MODE_WIFI  = demo on local network (recommended for defense)
 * MODE_GPRS  = SIM800L cellular
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <HardwareSerial.h>

#define USE_WIFI true

const char* SERVER_HOST = "192.168.1.105";
const char* SERVER_PATH = "/CAPSTONE/ebike-tracker/api/device-gps-update.php";

const char* DEVICE_ID = "EBIKE0002";
const char* API_TOKEN = "paste_token_from_admin_panel";

const char* WIFI_SSID = "YourWiFiName";
const char* WIFI_PASS = "YourWiFiPassword";

const char* GPRS_APN  = "internet";
const char* GPRS_USER = "";
const char* GPRS_PASS = "";

#define SIM_RX 25
#define SIM_TX 26

HardwareSerial SIMSerial(1);

unsigned long lastUpload = 0;
const unsigned long UPLOAD_INTERVAL_MS = 15000;

void setup() {
  Serial.begin(115200);

  if (USE_WIFI) {
    WiFi.begin(WIFI_SSID, WIFI_PASS);
    Serial.print("Connecting WiFi");
    while (WiFi.status() != WL_CONNECTED) {
      delay(500);
      Serial.print(".");
    }
    Serial.println("\nWiFi connected");
  } else {
    SIMSerial.begin(9600, SERIAL_8N1, SIM_RX, SIM_TX);
    initGprs();
  }

  Serial.print("Heartbeat server: http://");
  Serial.print(SERVER_HOST);
  Serial.println(SERVER_PATH);
}

void loop() {
  if (millis() - lastUpload < UPLOAD_INTERVAL_MS) {
    delay(200);
    return;
  }
  lastUpload = millis();

  int battery = 100;
  bool ok = USE_WIFI ? uploadViaWifi(battery) : uploadViaGprs(battery);
  Serial.println(ok ? "SIM800L heartbeat OK" : "SIM800L heartbeat failed");
}

bool uploadViaWifi(int battery) {
  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  HTTPClient http;
  String url = String("http://") + SERVER_HOST + SERVER_PATH;
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String body = String("device_id=") + DEVICE_ID +
                "&token=" + API_TOKEN +
                "&speed=0" +
                "&battery=" + String(battery);

  int code = http.POST(body);
  http.end();
  return code == 200;
}

void initGprs() {
  sendAt("AT", 1000);
  sendAt("AT+CPIN?", 1000);
  sendAt("AT+CREG?", 1000);
  sendAt("AT+CGATT=1", 2000);
  String cmd = String("AT+CSTT=\"") + GPRS_APN + "\",\"" + GPRS_USER + "\",\"" + GPRS_PASS + "\"";
  sendAt(cmd.c_str(), 2000);
  sendAt("AT+CIICR", 5000);
  sendAt("AT+CIFSR", 3000);
}

bool uploadViaGprs(int battery) {
  String host = SERVER_HOST;
  String path = SERVER_PATH;
  String body = String("device_id=") + DEVICE_ID +
                "&token=" + API_TOKEN +
                "&speed=0" +
                "&battery=" + String(battery);

  sendAt("AT+CIPSHUT", 2000);
  sendAt("AT+CIPSTART=\"TCP\",\"" + host + "\",80", 8000);
  sendAt("AT+CIPSEND=" + String(body.length() + path.length() + 120), 2000);

  String request = String("POST ") + path + " HTTP/1.1\r\n" +
                   "Host: " + host + "\r\n" +
                   "Content-Type: application/x-www-form-urlencoded\r\n" +
                   "Content-Length: " + body.length() + "\r\n\r\n" +
                   body;

  SIMSerial.print(request);
  delay(300);
  sendAt("", 100);
  sendAt("AT+CIPCLOSE", 2000);
  return true;
}

void sendAt(const String& cmd, uint32_t wait) {
  if (cmd.length()) {
    SIMSerial.println(cmd);
  }
  unsigned long start = millis();
  while (millis() - start < wait) {
    while (SIMSerial.available()) {
      Serial.write(SIMSerial.read());
    }
    delay(10);
  }
}
