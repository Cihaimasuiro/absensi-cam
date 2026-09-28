/*
 * Smart Absen — Arduino TFT USB Display Bridge
 * Hardware : Arduino Uno + 2.4" TFT Touch Shield (ST7789V, 240x320)
 * Host     : Orange Pi Lite 2 / Raspberry Pi (any) via USB-Serial
 * Protocol : plain-text, newline-delimited commands @ 115200 baud
 *
 * COMMAND FORMAT  (SBC -> Arduino, terminated by '\n'):
 *   BOOT                         — show boot splash
 *   STANDBY                      — idle / waiting for face
 *   OK:<name>:<dept>:<HH:MM:SS>  — face recognized
 *   FAIL                         — unknown face
 *   PROC                         — processing (LED blink, no text change)
 *   SYS:<cpu%>:<mem%>:<temp_C>:<ip>  — system info bar (bottom)
 *
 * SHIELD PINOUT (ST7789V, SPI mode — standard 2.4" Uno shield):
 *   SCK  -> D13   MOSI -> D11   CS  -> D10
 *   DC   -> D9    RST  -> D8    BL  -> D3 (PWM)
 *
 * LIBRARIES (install via Arduino IDE Library Manager):
 *   Adafruit ST7789   https://github.com/adafruit/Adafruit-ST7735-Library
 *   Adafruit GFX      https://github.com/adafruit/Adafruit-GFX-Library
 */

#include <Adafruit_GFX.h>
#include <Adafruit_ST7789.h>
#include <SPI.h>

// ── Pin definitions ───────────────────────────────────────────────────────────
#define TFT_CS   10
#define TFT_DC    9
#define TFT_RST   8
#define TFT_BL    3   // PWM backlight; tie to 3.3V/5V if not needed

// ── Display object ────────────────────────────────────────────────────────────
Adafruit_ST7789 tft = Adafruit_ST7789(TFT_CS, TFT_DC, TFT_RST);

// ── Colours (RGB565) ─────────────────────────────────────────────────────────
#define C_BG      0x0841   // near-black
#define C_GREEN   0x07E0
#define C_RED     0xF800
#define C_YELLOW  0xFFE0
#define C_WHITE   0xFFFF
#define C_GRAY    0x7BEF
#define C_CYAN    0x07FF
#define C_ACCENT  0x03DF   // teal

// ── Layout constants ─────────────────────────────────────────────────────────
#define SCR_W  240
#define SCR_H  320
#define SYSBAR_Y (SCR_H - 24)   // bottom status bar top-edge

// ── State ─────────────────────────────────────────────────────────────────────
String  serialBuf;
char    sysInfo[64] = "CPU:-- MEM:-- T:--C IP:0.0.0.0";

// ── Helpers ───────────────────────────────────────────────────────────────────
void drawHeader(const char* title, uint16_t bg) {
  tft.fillRect(0, 0, SCR_W, 36, bg);
  tft.setTextColor(C_WHITE);
  tft.setTextSize(2);
  tft.setCursor(8, 10);
  tft.print(title);
}

void drawSysBar() {
  tft.fillRect(0, SYSBAR_Y, SCR_W, 24, 0x2104);
  tft.setTextColor(C_GRAY);
  tft.setTextSize(1);
  tft.setCursor(4, SYSBAR_Y + 7);
  tft.print(sysInfo);
}

void clearBody() {
  tft.fillRect(0, 36, SCR_W, SYSBAR_Y - 36, C_BG);
}

void drawCentered(const char* txt, int16_t y, uint8_t sz, uint16_t col) {
  tft.setTextSize(sz);
  tft.setTextColor(col);
  int16_t x = (SCR_W - strlen(txt) * 6 * sz) / 2;
  if (x < 0) x = 0;
  tft.setCursor(x, y);
  tft.print(txt);
}

// ── Screens ───────────────────────────────────────────────────────────────────
void showBoot() {
  tft.fillScreen(C_BG);
  tft.drawFastHLine(0, 37, SCR_W, C_ACCENT);
  drawHeader("Smart Absen", C_ACCENT);
  drawCentered("Sistem Absensi",  80, 2, C_WHITE);
  drawCentered("Face Recognition", 108, 1, C_GRAY);
  drawCentered("Menghubungkan...", 150, 1, C_YELLOW);
  drawSysBar();
}

void showStandby() {
  clearBody();
  drawHeader("Smart Absen", C_ACCENT);
  tft.drawFastHLine(0, 37, SCR_W, C_ACCENT);
  drawCentered("[  ]",  90, 4, C_GRAY);          // face placeholder
  drawCentered("Hadapkan Wajah", 160, 2, C_WHITE);
  drawCentered("ke Kamera", 184, 2, C_WHITE);
  drawSysBar();
}

void showOK(const char* name, const char* dept, const char* time) {
  clearBody();
  drawHeader("TERKENALI", C_GREEN);
  tft.drawFastHLine(0, 37, SCR_W, C_GREEN);
  drawCentered("OK", 60, 4, C_GREEN);
  // name — may be long, use size 2 then size 1 fallback
  tft.setTextSize(2); tft.setTextColor(C_WHITE);
  tft.setCursor(4, 118); tft.print(name);
  tft.setTextSize(1); tft.setTextColor(C_GRAY);
  tft.setCursor(4, 140); tft.print(dept);
  tft.setCursor(4, 154); tft.print(time);
  drawSysBar();
}

void showFail() {
  clearBody();
  drawHeader("TIDAK DIKENAL", C_RED);
  tft.drawFastHLine(0, 37, SCR_W, C_RED);
  drawCentered("X", 70, 6, C_RED);
  drawCentered("Wajah tidak", 158, 2, C_WHITE);
  drawCentered("terdaftar", 182, 2, C_WHITE);
  drawSysBar();
}

void showProc() {
  // minimal: just flash the header colour — avoids redraw flicker
  static bool tog = false;
  tft.fillRect(0, 0, SCR_W, 36, tog ? C_YELLOW : 0x6B40);
  tft.setTextColor(C_BG); tft.setTextSize(2);
  tft.setCursor(8, 10); tft.print("Memproses...");
  tog = !tog;
}

// ── Command parser ────────────────────────────────────────────────────────────
// ponytail: simple token split with strtok — no JSON lib needed here
void parseAndDispatch(String& line) {
  line.trim();
  if (line.length() == 0) return;

  char buf[128];
  line.toCharArray(buf, sizeof(buf));
  char* cmd = strtok(buf, ":");

  if (strcmp(cmd, "BOOT") == 0) {
    showBoot();
  } else if (strcmp(cmd, "STANDBY") == 0) {
    showStandby();
  } else if (strcmp(cmd, "OK") == 0) {
    char* name = strtok(NULL, ":");
    char* dept = strtok(NULL, ":");
    char* time = strtok(NULL, ":");
    showOK(name ? name : "", dept ? dept : "", time ? time : "");
  } else if (strcmp(cmd, "FAIL") == 0) {
    showFail();
  } else if (strcmp(cmd, "PROC") == 0) {
    showProc();
  } else if (strcmp(cmd, "SYS") == 0) {
    // SYS:<cpu%>:<mem%>:<temp>:<ip>
    char* cpu  = strtok(NULL, ":");
    char* mem  = strtok(NULL, ":");
    char* temp = strtok(NULL, ":");
    char* ip   = strtok(NULL, ":");
    snprintf(sysInfo, sizeof(sysInfo), "C:%s%% M:%s%% T:%sC %s",
             cpu ? cpu : "--", mem ? mem : "--",
             temp ? temp : "--", ip ? ip : "");
    drawSysBar();
  }
  // unknown command: silently ignore (ponytail: no error frame)
}

// ── Arduino lifecycle ─────────────────────────────────────────────────────────
void setup() {
  pinMode(TFT_BL, OUTPUT);
  analogWrite(TFT_BL, 220);   // ~86% brightness; lower = dimmer

  tft.init(240, 320);
  tft.setRotation(0);         // portrait, USB-B connector at bottom
  tft.fillScreen(C_BG);

  Serial.begin(115200);
  serialBuf.reserve(128);
  showBoot();
}

void loop() {
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n') {
      parseAndDispatch(serialBuf);
      serialBuf = "";
    } else if (serialBuf.length() < 127) {
      serialBuf += c;
    }
  }
}
