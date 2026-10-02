#include <Adafruit_GFX.h>
#include <MCUFRIEND_kbv.h>
#include <ArduinoJson.h>

MCUFRIEND_kbv tft;

// Definisikan warna GFX
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

// Konfigurasi Tabel Riwayat (8 Baris)
const int MAX_HISTORY = 8;
struct HistoryEntry {
  String time;
  String name;
  uint16_t color;
};

HistoryEntry history[MAX_HISTORY];
int historyCount = 0;

void setup() {
  Serial.begin(115200);
  
  pinMode(LED_GREEN_PIN, OUTPUT);
  pinMode(LED_RED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  
  // Matikan semua I/O saat boot
  digitalWrite(LED_GREEN_PIN, LOW);
  digitalWrite(LED_RED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  // Inisialisasi Layar TFT 2.4"
  uint16_t ID = tft.readID();
  if (ID == 0xD3D3) ID = 0x9486; // Workaround untuk beberapa shield
  tft.begin(ID);
  tft.setRotation(1); // Mode Landscape (320x240)
  
  drawStaticUI();
  updateStatus("MEMULAI SISTEM...", YELLOW);
  
  // Kirim event siap ke SBC
  Serial.println("{\"evt\":\"ready\",\"fw\":\"1.0.0\"}");
}

void loop() {
  // Cek apakah ada data serial masuk
  if (Serial.available()) {
    // Baca baris hingga karakter newline (\n)
    String line = Serial.readStringUntil('\n');
    line.trim();
    
    if (line.length() > 0) {
      processCommand(line);
    }
  }
}

void processCommand(String jsonString) {
  // Menggunakan memori statis 256 byte agar aman untuk SRAM Uno (2KB)
  StaticJsonDocument<256> doc;
  DeserializationError error = deserializeJson(doc, jsonString);
  
  if (error) {
    // Abaikan JSON yang rusak
    return;
  }
  
  const char* cmd = doc["cmd"];
  if (!cmd) return;
  
  if (strcmp(cmd, "display") == 0) {
    const char* status = doc["status"];
    
    if (strcmp(status, "PROCESSING") == 0) {
      updateStatus("MEMPROSES WAJAH...", CYAN);
    } 
    else if (strcmp(status, "RECOGNIZED") == 0 || strcmp(status, "UNKNOWN") == 0 || strcmp(status, "SPOOF") == 0) {
      const char* name = doc["name"];
      const char* time = doc["time"]; // Misal: "07:30"
      
      uint16_t color = GREEN;
      String statusMsg = "BERHASIL ABSEN!";
      
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
    
    // Matikan LED
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
}

void addHistory(String time, String name, uint16_t color) {
  // Jika buffer penuh, geser elemen ke atas
  if (historyCount == MAX_HISTORY) {
    for (int i = 0; i < MAX_HISTORY - 1; i++) {
      history[i] = history[i + 1];
    }
    historyCount = MAX_HISTORY - 1;
  }
  
  // Batasi panjang nama maksimal 18-20 karakter agar muat (26 char per baris limit)
  if (name.length() > 18) {
    name = name.substring(0, 15) + "...";
  }
  
  history[historyCount].time = time;
  history[historyCount].name = name;
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

void updateStatus(String msg, uint16_t color) {
  // Bersihkan area status (di bawah header, di atas tabel)
  tft.fillRect(0, 36, 320, 38, BLACK);
  
  tft.setTextSize(2);
  tft.setTextColor(color);
  
  // Hitung perkiraan titik tengah agar teks ke tengah (approximate)
  // Font size 2 lebarnya sekitar 12 pixel per karakter
  int textWidth = msg.length() * 12;
  int xPos = (320 - textWidth) / 2;
  if (xPos < 0) xPos = 0;
  
  tft.setCursor(xPos, 48);
  tft.print(msg);
}

void drawHistory() {
  // Bersihkan area tabel saja (baris Y: 76 sampai 240)
  tft.fillRect(0, 76, 320, 164, BLACK);
  
  tft.setTextSize(2);
  
  // Mulai cetak dari sejarah paling baru (posisi terakhir di array) ke paling lama
  // Posisi Y awal = 85, tambah 20 setiap baris (max 8 baris = 85 + (8*20) = 245)
  int yPos = 85;
  
  for (int i = historyCount - 1; i >= 0; i--) {
    tft.setTextColor(WHITE); // Waktu warnanya putih
    tft.setCursor(10, yPos);
    tft.print(history[i].time);
    
    tft.setTextColor(history[i].color); // Nama menyesuaikan warna (Hijau/Merah)
    tft.setCursor(85, yPos);
    tft.print(history[i].name);
    
    yPos += 20;
  }
}

void beep(int duration_ms) {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(duration_ms);
  digitalWrite(BUZZER_PIN, LOW);
}
