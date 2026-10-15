# Plan: Preview/Gambar Orange Pi → Arduino Uno → TFT MAR2406 (Protokol v3)

## 0. Tujuan dan Ekspektasi (Non-Negotiable)
- **Mode A, Live preview kamera:** 160×120 RGB332 (1 byte/piksel) dengan *ordered dithering*. Ditampilkan 1× di tengah layar atau di-upscale 2× oleh Uno ke layar penuh. Target ≥ 3 FPS (stretch 5 FPS). **Bukan kualitas HDMI**, hanya panduan framing wajah.
- **Mode B, Gambar statis** (logo, foto, splash): RGB565 320×240 penuh, ±1,6 detik di 1 Mbps. Kualitas tinggi untuk gambar diam.
- Jalur JSON yang ada (`display`, `standby`, `io`) **tidak boleh rusak**. Fitur streaming mati secara default (`ARDUINO_STREAM=0`).
- Semua angka FPS harus diukur di Fase 1 sebelum diklaim.

---

## 1. Aturan Wajib
1. **Satu Pemilik Port Serial:** Satu `SerialLink` dengan satu thread penulis. `ArduinoBridge` mengirim perintah JSON lewat antrean berprioritas milik `SerialLink` sehingga paket preview dan JSON tidak saling tumpang tindih.
2. Default konfigurasi: `ARDUINO_BAUD=115200` dan `ARDUINO_STREAM=0`. Baud rate 1.000.000 bps hanya diaktifkan setelah Fase 1 lulus benchmark.
3. Edit kode secara langsung, commit kecil per fase, dan push per fase.
4. Firmware C++ zero dynamic heap allocation (tanpa `malloc`, `new`, `String`). Rust memakai `#![forbid(unsafe_code)]` pada crate streamer.

---

## 2. Spesifikasi Protokol v3

| Offset | Field | Keterangan |
|---|---|---|
| 0–1 | SYNC | `0xFF 0xAA` |
| 2 | TYPE | `0x01` INIT, `0x02` DATA, `0x03` END, `0x0F` ABORT |
| 3 | SEQ | Naik 1 per paket (wrap 256) |
| 4 | LEN | Payload ≤ **80** byte |
| 5.. | PAYLOAD | Data spesifik per TYPE |
| 5+LEN | XOR | XOR dari TYPE, SEQ, LEN, dan seluruh PAYLOAD |

- **INIT (LEN=10):** `[x(2), y(2), w(2), h(2), fmt(1), scale(1)]`, big-endian.
  - `fmt`: `0 = RGB332`, `1 = RGB565` (hi lalu lo).
  - `scale`: `1` (1x1 normal), `2` (2x horizontal & 2x vertikal).
- **DATA:** Potongan byte piksel raster berurutan.
- **END (LEN=0):** Menutup frame.
- **ABORT (LEN=0):** Membatalkan frame seketika.
- **Balasan Uno → SBC (3 Byte):** `0xA5, 0x06|0x15, seq` (ACK / NAK). Awalan `0xA5` membedakannya dari JSON `{"evt":...}`.
- **Flow Control:** Maksimal 2 paket in-flight unacknowledged (≤ 172 byte). Timeout ACK 30 ms di SBC.
- **Tanpa Retransmit:** Jika NAK/timeout/desync, kirim ABORT lalu mulai frame berikutnya.
- **Timeout Uno:** 100 ms tanpa byte saat di dalam frame memicu abort otomatis (CS dilepas).

---

## 3. Arsitektur Firmware Arduino

### 3.1 Jalur Tulis Bus Paralel MAR2406
```cpp
// Urutan Memory Write ILI9341 yang Benar:
tft.setAddrWindow(x0, y0, x1, y1);
CS_LOW;  CD_LOW;  write8(0x2C);  CD_HIGH;   // Mulai Memory Write (0x2C)
/* Stream piksel via write8 ... */
CS_HIGH;                                     // Saat END / ABORT / Error / Timeout
```
- Pin Shield Uno: `RD=PC0` (A0, tetap HIGH), `WR=PC1` (A1), `CD/RS=PC2` (A2), `CS=PC3` (A3).
- Data Bus: `PB0..PB1` (D8..D9), `PD2..PD7` (D2..D7). Bit lain dipertahankan (`PD0/1` UART, `PB2..PB4` LED/Buzzer).

### 3.2 Rumus Koreksi RGB332 → RGB565
```cpp
hi = (c & 0xE0) | ((c >> 3) & 0x18) | ((c >> 2) & 0x07);
lo = ((c & 0x1C) << 3) | ((c & 0x03) << 3) | ((c & 0x03) << 1) | ((c & 0x03) >> 1);
```

---

## 4. Urutan Kerja Fase 0 – 5

- **Fase 0: Selftest Jalur Bus & Verifikasi LCD ID** (`{"cmd":"selftest"}` menggambar color bar).
- **Fase 1: Benchmark Link & Throughput Gambar** (115200 & 1 Mbps, Timer0 aktif).
- **Fase 2: Crate Protokol & Simulator Host** (Fuzzing & Property Tests).
- **Fase 3: Firmware v3 Produksi** (State machine byte-per-byte, no `Serial`).
- **Fase 4: SBC Streamer Rust** (Box filter 4x4, Bayer 4x4 ordered dither, single owner `SerialLink`).
- **Fase 5: Integrasi Penuh & Kriteria Penerimaan**.
