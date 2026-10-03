#include <avr/wdt.h>
void wdt_init() __attribute__((naked)) __attribute__((section(".init3")));
void wdt_init() { MCUSR = 0; wdt_disable(); }

#include <Wire.h>
#include <Adafruit_GFX.h>
#include <MCUFRIEND_kbv.h>

MCUFRIEND_kbv tft;

// ── Colors ────────────────────────────────────────────────────────────────────
#define C_BLACK    0x0000
#define C_WHITE    0xFFFF
#define C_GREEN    0x07E0
#define C_RED      0xF800
#define C_YELLOW   0xFFE0
#define C_ORANGE   0xFD20
#define C_DKGRAY   0x4208
#define C_LTGRAY   0xC618
#define C_CYAN     0x07FF
#define C_DKBLUE   0x000F

// ── Globals ───────────────────────────────────────────────────────────────────
static char gBuf[128];
static uint8_t gBufLen = 0;
bool gConnected = false;
unsigned long gLastCmdMs = 0;

// ── Tiny JSON Helper ──────────────────────────────────────────────────────────
static bool jStr(const char* json, const char* key, char* buf, uint8_t sz) {
  char pat[20];
  uint8_t kl = strlen(key);
  if (kl + 4 > sizeof(pat)) return false;
  pat[0] = '"'; memcpy(pat + 1, key, kl); pat[kl + 1] = '"'; pat[kl + 2] = ':'; pat[kl + 3] = '"'; pat[kl + 4] = '\0';
  const char* p = strstr(json, pat);
  if (!p) return false;
  p += kl + 4;
  uint8_t i = 0;
  while (*p && *p != '"' && i < sz - 1) buf[i++] = *p++;
  buf[i] = '\0';
  return true;
}

// ── Drawing ───────────────────────────────────────────────────────────────────
static void drawCenter(const char* text, int16_t y, uint16_t color, uint8_t sz) {
  tft.setTextColor(color);
  tft.setTextSize(sz);
  tft.setCursor((320 - (int16_t)(strlen(text) * 6 * sz)) / 2, y);
  tft.print(text);
}

static void drawHeader(const char* title, uint16_t bg, uint16_t fg) {
  tft.fillRect(0, 0, 320, 38, bg);
  tft.drawFastHLine(0, 38, 320, C_LTGRAY);
  drawCenter(title, 11, fg, 2);
}

static void drawFooter(const char* msg) {
  tft.fillRect(0, 220, 320, 20, C_DKGRAY);
  tft.setTextColor(C_LTGRAY);
  tft.setTextSize(1);
  tft.setCursor(4, 226);
  tft.print(F("SMART ABSENSI"));
  tft.setCursor(180, 226);
  tft.print(msg);
}

static void drawWaiting() {
  tft.fillScreen(C_BLACK);
  drawHeader("SMART ABSENSI", C_DKGRAY, C_LTGRAY);
  drawCenter("Menunggu Sistem...", 100, C_YELLOW, 2);
  drawFooter("Offline");
}

static void drawStandby(const char* msg) {
  tft.fillScreen(C_BLACK);
  drawHeader("SIAP ABSEN", C_DKBLUE, C_CYAN);
  drawCenter(msg, 100, C_WHITE, 2);
  drawFooter("Online");
}

static void drawResult(const char* status, const char* name, const char* timeStr) {
  tft.fillScreen(C_BLACK);
  if (strcmp(status, "RECOGNIZED") == 0) {
    drawHeader("BERHASIL", C_GREEN, C_BLACK);
    drawCenter(name, 100, C_WHITE, 3);
    drawCenter(timeStr, 140, C_CYAN, 2);
  } else if (strcmp(status, "SPOOF") == 0) {
    drawHeader("DITOLAK", C_RED, C_WHITE);
    drawCenter("Spoofing Terdeteksi!", 100, C_YELLOW, 2);
  } else {
    drawHeader("TIDAK DIKENAL", C_ORANGE, C_BLACK);
    drawCenter("Wajah tidak terdaftar", 100, C_WHITE, 2);
  }
  drawFooter("Online");
}

// ── Serial processing ─────────────────────────────────────────────────────────
static void processLine(char* line) {
  char cmd[16];
  if (!jStr(line, "cmd", cmd, sizeof(cmd))) return;

  if (strcmp(cmd, "ping") == 0) {
    Serial.println(F("{\"evt\":\"pong\"}"));
  } 
  else if (strcmp(cmd, "standby") == 0) {
    char msg[32] = "Silakan hadapkan wajah";
    jStr(line, "msg", msg, sizeof(msg));
    drawStandby(msg);
  }
  else if (strcmp(cmd, "display") == 0) {
    char status[16] = "UNKNOWN";
    char name[32] = "";
    char timeStr[16] = "";
    jStr(line, "status", status, sizeof(status));
    jStr(line, "name", name, sizeof(name));
    jStr(line, "time", timeStr, sizeof(timeStr));
    drawResult(status, name, timeStr);
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(500);

  uint16_t id = tft.readID();
  if (id == 0xD3D3 || id == 0x0000 || id == 0xFFFF) id = 0x9341;

  tft.begin(id);
  tft.setRotation(1);

  drawWaiting();

  wdt_enable(WDTO_8S);
  Serial.println(F("{\"evt\":\"ready\",\"fw\":\"1.3.0\"}"));
}

void loop() {
  wdt_reset();

  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n') {
      if (gBufLen > 0) {
        gBuf[gBufLen] = '\0';
        processLine(gBuf);
        gLastCmdMs = millis();
        gConnected = true;
      }
      gBufLen = 0;
    } else if (gBufLen < sizeof(gBuf) - 1 && c != '\r') {
      gBuf[gBufLen++] = c;
    }
  }

  // Timeout -> go back to waiting screen
  if (gConnected && (millis() - gLastCmdMs > 10000UL)) {
    gConnected = false;
    drawWaiting();
  }
}
