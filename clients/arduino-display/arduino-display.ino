#include <Adafruit_GFX.h>
#include <MCUFRIEND_kbv.h>
#include <ArduinoJson.h>

MCUFRIEND_kbv tft;

// Definisikan warna GFX (RGB565)
#define BLACK   0x0000
#define BLUE    0x001F
#define RED     0xF800
#define GREEN   0x07E0
#define CYAN    0x07FF
#define MAGENTA 0xF81F
#define YELLOW  0xFFE0
#define WHITE   0xFFFF
#define ORANGE  0xFD20

// Pin I/O (Sesuai PRD)
const int LED_GREEN_PIN = 10;
const int LED_RED_PIN = 11;
const int BUZZER_PIN = 12;

// Baud rate serial: 1000000 (1 Mbps teruji 0% error) atau 115200
#ifndef BAUD_RATE
#define BAUD_RATE 1000000
#endif

// Konstanta Dual-Mode Protocol
#define MAGIC_SYNC_HI 0xFF
#define MAGIC_SYNC_LO 0xAA
#define ACK_BYTE      0x06

// Konfigurasi Riwayat Absensi Statis (Memory Safe: Tanpa alokasi dinamis)
const int MAX_HISTORY = 8;
struct HistoryEntry {
  char time[9];
  char name[20];
  uint16_t color;
};

static HistoryEntry history[MAX_HISTORY];
static int historyCount = 0;

// Buffer statis untuk JSON & Serial (Mencegah SRAM exhaustion pada ATmega328P)
static char json_buf[160];
static uint8_t json_idx = 0;

// Kontrol Bus TFT MAR2406 (Port C: A0=RD, A1=WR, A2=CD/RS, A3=CS)
#define RD_PORT PORTC
#define WR_PORT PORTC
#define CD_PORT PORTC
#define CS_PORT PORTC
#define RD_PIN  PC0
#define WR_PIN  PC1
#define CD_PIN  PC2
#define CS_PIN  PC3

#define CS_LOW   CS_PORT &= ~(1 << CS_PIN)
#define CS_HIGH  CS_PORT |=  (1 << CS_PIN)
#define CD_LOW   CD_PORT &= ~(1 << CD_PIN)
#define CD_HIGH  CD_PORT |=  (1 << CD_PIN)
#define WR_LOW   WR_PORT &= ~(1 << WR_PIN)
#define WR_HIGH  WR_PORT |=  (1 << WR_PIN)
#define RD_HIGH  RD_PORT |=  (1 << RD_PIN)

// Penulisan 1 byte ke 8-bit bus MAR2406 (D0-D7) & pulsa WR (Pin A1 / PORTC1)
// D0..D1 = Pin 8, 9 (PORTB 0..1), D2..D7 = Pin 2..7 (PORTD 2..7)
static inline void write8_fast(uint8_t d) {
  PORTB = (PORTB & 0xFC) | (d & 0x03);
  PORTD = (PORTD & 0x03) | (d & 0xFC);
  WR_LOW;
  WR_HIGH;
}

// Menulis 1 piksel 16-bit RGB565 ke bus layar
static inline void write16_fast(uint8_t hi, uint8_t lo) {
  write8_fast(hi);
  write8_fast(lo);
}

// Urutan Memory Write ILI9341 yang Benar (Sesuai Spesifikasi Protokol v3 Poin 3.1)
static inline void tft_start_write(uint16_t x0, uint16_t y0, uint16_t x1, uint16_t y1) {
  tft.setAddrWindow(x0, y0, x1, y1);
  RD_HIGH;
  CS_LOW;
  CD_LOW;
  write8_fast(0x2C); // Memory Write (0x2C)
  CD_HIGH;
}

static inline void tft_end_write() {
  CS_HIGH;
  RD_HIGH;
}

// Rumus Koreksi RGB332 -> RGB565 (Protokol v3 Poin 3.4)
static inline void rgb332_to_565_bytes(uint8_t c, uint8_t &hi, uint8_t &lo) {
  hi = (c & 0xE0) | ((c >> 3) & 0x18) | ((c >> 2) & 0x07);
  lo = ((c & 0x1C) << 3) | ((c & 0x03) << 3) | ((c & 0x03) << 1) | ((c & 0x03) >> 1);
}

void beep(int duration_ms) {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(duration_ms);
  digitalWrite(BUZZER_PIN, LOW);
}

void drawStaticUI();
void updateStatus(const char* msg, uint16_t color);
void drawHistory();
void addHistory(const char* time, const char* name, uint16_t color);
void processCommand(const char* jsonString);
void handleBinaryRleFrame();
void runSelftest();

void setup() {
  Serial.begin(BAUD_RATE);

  pinMode(LED_GREEN_PIN, OUTPUT);
  pinMode(LED_RED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  // Matikan semua I/O saat boot (AC-19a)
  digitalWrite(LED_GREEN_PIN, LOW);
  digitalWrite(LED_RED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  // Inisialisasi Layar TFT 2.4"
  uint16_t ID = tft.readID();
  if (ID == 0xD3D3) ID = 0x9486; // Workaround hanya jika controller mengembalikan 0xD3D3
  tft.begin(ID);
  tft.setRotation(1); // Mode Landscape (320x240)

  drawStaticUI();
  updateStatus("MEMULAI SISTEM...", YELLOW);

  // Kirim event siap ke SBC dengan LCD ID terverifikasi
  char ready_msg[64];
  snprintf(ready_msg, sizeof(ready_msg), "{\"evt\":\"ready\",\"lcd_id\":\"0x%04X\",\"fw\":\"3.0.0\"}", ID);
  Serial.println(ready_msg);
}

void loop() {
  while (Serial.available()) {
    int b = Serial.peek();

    // Deteksi Magic Sync Byte 0xFF 0xAA untuk Mode Frame Biner RLE
    if (b == MAGIC_SYNC_HI) {
      Serial.read(); // konsumsi 0xFF
      while (!Serial.available()) {}
      if (Serial.peek() == MAGIC_SYNC_LO) {
        Serial.read(); // konsumsi 0xAA
        handleBinaryRleFrame();
        continue;
      } else {
        // Bukan magic sync, simpan ke buffer JSON jika muat
        if (json_idx < sizeof(json_buf) - 1) {
          json_buf[json_idx++] = (char)MAGIC_SYNC_HI;
        }
      }
    }

    // Mode Pembacaan JSON Baris Biasa (\n)
    char c = (char)Serial.read();
    if (c == '\n' || c == '\r') {
      if (json_idx > 0) {
        json_buf[json_idx] = '\0';
        processCommand(json_buf);
        json_idx = 0;
      }
    } else {
      if (json_idx < sizeof(json_buf) - 1) {
        json_buf[json_idx++] = c;
      } else {
        // Buffer overflow protection: reset jika baris terlalu panjang
        json_idx = 0;
      }
    }
  }
}

// Handler Dekompresi RLE Langsung ke Layar TFT (Zero-Allocation)
void handleBinaryRleFrame() {
  // Baca Header Window Rect (8 byte: X_hi, X_lo, Y_hi, Y_lo, W_hi, W_lo, H_hi, H_lo)
  uint8_t hdr[8];
  for (int i = 0; i < 8; i++) {
    while (!Serial.available()) {}
    hdr[i] = (uint8_t)Serial.read();
  }

  uint16_t x = ((uint16_t)hdr[0] << 8) | hdr[1];
  uint16_t y = ((uint16_t)hdr[2] << 8) | hdr[3];
  uint16_t w = ((uint16_t)hdr[4] << 8) | hdr[5];
  uint16_t h = ((uint16_t)hdr[6] << 8) | hdr[7];

  // Batasi rentang jendela layar agar aman
  if (x >= 320 || y >= 240 || (x + w) > 320 || (y + h) > 240) {
    return;
  }

  // Mulai transaksi bus paralel ILI9341 Memory Write (0x2C)
  tft_start_write(x, y, x + w - 1, y + h - 1);

  // Dekompresi RLE piksel langsung ke layar
  while (true) {
    while (!Serial.available()) {}
    uint8_t count = (uint8_t)Serial.read();

    // End of Frame Delimiter (count == 0)
    if (count == 0) {
      break;
    }

    while (!Serial.available()) {}
    uint8_t color_hi = (uint8_t)Serial.read();

    while (!Serial.available()) {}
    uint8_t color_lo = (uint8_t)Serial.read();

    // Tulis piksel sebanyak count kali secara zero-copy
    for (uint8_t i = 0; i < count; i++) {
      write16_fast(color_hi, color_lo);
    }
  }

  // Tutup transaksi bus (CS HIGH)
  tft_end_write();

  // Kirim sinyal ACK (0x06) ke SBC bahwa frame selesai digambar
  Serial.write(ACK_BYTE);
}

// Fase 0: Selftest Jalur Bus Paralel & Verifikasi Warna Layar
void runSelftest() {
  uint16_t ID = tft.readID();

  // 1. Bar Merah Penuh (320x48)
  tft_start_write(0, 0, 319, 47);
  for (uint32_t i = 0; i < 320UL * 48UL; i++) write16_fast(0xF8, 0x00);
  tft_end_write();

  // 2. Bar Hijau Penuh (320x48)
  tft_start_write(0, 48, 319, 95);
  for (uint32_t i = 0; i < 320UL * 48UL; i++) write16_fast(0x07, 0xE0);
  tft_end_write();

  // 3. Bar Biru Penuh (320x48)
  tft_start_write(0, 96, 319, 143);
  for (uint32_t i = 0; i < 320UL * 48UL; i++) write16_fast(0x00, 0x1F);
  tft_end_write();

  // 4. Bar Putih Penuh (320x48)
  tft_start_write(0, 144, 319, 191);
  for (uint32_t i = 0; i < 320UL * 48UL; i++) write16_fast(0xFF, 0xFF);
  tft_end_write();

  // 5. Bar Gradasi Abu-abu (320x48, 16 step)
  tft_start_write(0, 192, 319, 239);
  for (uint16_t y = 0; y < 48; y++) {
    for (uint16_t step = 0; step < 16; step++) {
      uint8_t v5 = (step * 31) / 15;
      uint8_t v6 = (step * 63) / 15;
      uint16_t gray = (v5 << 11) | (v6 << 5) | v5;
      for (uint8_t px = 0; px < 20; px++) {
        write16_fast((uint8_t)(gray >> 8), (uint8_t)(gray & 0xFF));
      }
    }
  }
  tft_end_write();

  // Balas event ke SBC
  char msg[64];
  snprintf(msg, sizeof(msg), "{\"evt\":\"selftest_ok\",\"lcd_id\":\"0x%04X\",\"fw\":\"3.0.0\"}", ID);
  Serial.println(msg);
}

void processCommand(const char* jsonString) {
  StaticJsonDocument<256> doc;
  DeserializationError error = deserializeJson(doc, jsonString);
  if (error) {
    return;
  }

  const char* cmd = doc["cmd"];
  if (!cmd) return;

  if (strcmp(cmd, "display") == 0) {
    const char* status = doc["status"];
    if (!status) return;

    if (strcmp(status, "PROCESSING") == 0) {
      updateStatus("MEMPROSES WAJAH...", CYAN);
    } 
    else if (strcmp(status, "RECOGNIZED") == 0 || strcmp(status, "UNKNOWN") == 0 || strcmp(status, "SPOOF") == 0) {
      const char* name = doc["name"];
      const char* time = doc["time"];

      uint16_t color = GREEN;
      const char* statusMsg = "BERHASIL ABSEN!";

      if (strcmp(status, "UNKNOWN") == 0) {
        color = RED;
        statusMsg = "WAJAH TIDAK DIKENAL";
      } else if (strcmp(status, "SPOOF") == 0) {
        color = ORANGE;
        statusMsg = "PERINGATAN SPOOFING!";
      }

      updateStatus(statusMsg, color);
      addHistory(time ? time : "--:--", name ? name : "Tidak Diketahui", color);
      drawHistory();
    }
  } 
  else if (strcmp(cmd, "standby") == 0) {
    const char* msg = doc["msg"];
    updateStatus(msg ? msg : "SILAKAN HADAP KAMERA", WHITE);
  }
  else if (strcmp(cmd, "io") == 0) {
    const char* led = doc["led"];
    const char* buzzer = doc["buzzer"];

    digitalWrite(LED_GREEN_PIN, LOW);
    digitalWrite(LED_RED_PIN, LOW);

    if (led && strcmp(led, "green") == 0) {
      digitalWrite(LED_GREEN_PIN, HIGH);
    } else if (led && strcmp(led, "red") == 0) {
      digitalWrite(LED_RED_PIN, HIGH);
    }

    if (buzzer && strcmp(buzzer, "2short") == 0) {
      beep(100); delay(50); beep(100);
    } else if (buzzer && strcmp(buzzer, "1long") == 0) {
      beep(500);
    }
  }
  else if (strcmp(cmd, "selftest") == 0) {
    runSelftest();
  }
}

void addHistory(const char* time, const char* name, uint16_t color) {
  if (historyCount == MAX_HISTORY) {
    for (int i = 0; i < MAX_HISTORY - 1; i++) {
      history[i] = history[i + 1];
    }
    historyCount = MAX_HISTORY - 1;
  }

  strncpy(history[historyCount].time, time, sizeof(history[historyCount].time) - 1);
  history[historyCount].time[sizeof(history[historyCount].time) - 1] = '\0';

  strncpy(history[historyCount].name, name, sizeof(history[historyCount].name) - 1);
  history[historyCount].name[sizeof(history[historyCount].name) - 1] = '\0';

  history[historyCount].color = color;
  historyCount++;
}

void drawStaticUI() {
  tft.fillScreen(BLACK);

  // Header Box
  tft.fillRect(0, 0, 320, 35, BLUE);
  tft.setTextColor(WHITE);
  tft.setTextSize(2);
  tft.setCursor(10, 10);
  tft.print("SMART ABSENSI");

  // Pemisah Tabel
  tft.drawLine(0, 75, 320, 75, WHITE);
}

void updateStatus(const char* msg, uint16_t color) {
  tft.fillRect(0, 36, 320, 38, BLACK);
  tft.setTextSize(2);
  tft.setTextColor(color);

  int len = strlen(msg);
  int textWidth = len * 12;
  int xPos = (320 - textWidth) / 2;
  if (xPos < 0) xPos = 0;

  tft.setCursor(xPos, 48);
  tft.print(msg);
}

void drawHistory() {
  tft.fillRect(0, 76, 320, 164, BLACK);
  tft.setTextSize(2);
  int yPos = 85;

  for (int i = historyCount - 1; i >= 0; i--) {
    tft.setTextColor(WHITE);
    tft.setCursor(10, yPos);
    tft.print(history[i].time);

    tft.setTextColor(history[i].color);
    tft.setCursor(85, yPos);
    tft.print(history[i].name);

    yPos += 20;
  }
}
