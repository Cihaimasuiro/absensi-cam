# PRD — Sistem Absensi "Smart Absensi"
## Product Requirement Document (PRD) — Monorepo

> **Versi**: 2.0.0
> **Tanggal**: 2026-09-29
> **Status**: Draft — Disetujui untuk Implementasi
> **Penulis**: Tim Smart Absensi
> **Repositori**: `d:\Github\absensi-cam`

---

## Daftar Isi

---

## 1. Executive Summary & Arsitektur Sistem

### 1.1 Tujuan Dokumen

Dokumen ini adalah **Product Requirement Document (PRD)** untuk monorepo **"Smart Absensi"** — sistem absensi face recognition 


### 1.2 Lingkup Proyek & Keunggulan Kompetitif

| Aspek | Smart Absensi (Proyek Kita) | Facenox / Fingerspot DT-12MQ | Keunggulan Smart Absensi |
|-------|--------------------------|------------------------------|------------------------|
| **Anti-Spoofing (Anti-Kecurangan)** | **On-Device 600KB ONNX Liveness Model** | Facenox (600KB) / DT-12MQ (IR Sensor) | **Mencegah foto HP / print cetak tanpa sensor IR mahal** |
| **Jarak Absensi** | **0.5m - 3.0m (Long-Distance / Walk-Through)** | DT-12MQ (0.3m - 0.8m / Berhenti diam) | **Siswa/Karyawan tidak perlu berhenti jalan** |
| **Kapasitas Pemindaian** | **Multi-Face Parallel (3-5 wajah sekaligus)** | DT-12MQ (Single-Face Sequential) | **Bebas Antrean / High-Throughput** |
| **Kecepatan Inference Engine** | **< 30 ms per frame (Native C++ Engine)** | Facenox (FastAPI/Electron) / DT-12MQ (~500ms) | **30x Hemat RAM (< 50MB vs > 1.5GB Facenox)** |
| **Target Hardware** | **Orange Pi Lite 2 (Embedded ARM SBC)** | PC / Laptop (Facenox) | **Hemat daya & biaya hardware murah ($15 SBC)** |
| **Server Pusat** | **Laravel 13 — REST API / JSON** | Tidak ada / Cloud third-party | **Admin PC sendiri, data tidak keluar** |
| **Sinkronisasi Data** | **REST API JSON (edge -> server pusat)** | Proprietary cloud / tidak ada | **Standard, audit-friendly, terbuka** |

### 1.3 Alur Kerja Sistem

**Narasi Alur:**

1. **Power ON**: Adaptor 5V/3A menyuplai daya ke modul UPS 18650 step-up. Modul UPS mendistribusikan 5V ke SBC dan Arduino.
2. **Boot SBC**: Orange Pi Lite 2 boot dari MicroSD dalam < 30 detik. Service systemd `smart-absensi.service` (C++ Engine) start otomatis.
3. **Init Camera**: Sistem menginisialisasi UVC USB Web Camera (`/dev/video0`) pada resolusi 640x480 @ 30 FPS.
4. **Long-Distance Multi-Face Recognition**: Native C++ YuNet Engine memindai area hingga jarak 3 meter secara real-time.
5. **Liveness & Anti-Spoofing Check**: Setiap wajah yang terdeteksi secara otomatis diverifikasi oleh model Mini-FASNet ONNX (600KB). Foto cetak atau layar HP akan langsung ditolak (`FAIL`).
6. **Feature Matching & Logging**: Wajah asli diverifikasi dengan embedding SFace dan dicocokkan ke database SQLite lokal (**kapasitas 2000+ wajah**).
7. **Feedback Output**: Perintah JSON dikirim ke Arduino Uno via USB Serial untuk ditampilkan pada 2.4" TFT LCD.
8. **REST API Sync**: Data absensi lokal di-sync ke **Server Pusat Laravel 13** via REST API / JSON secara asynchronous. Server Pusat menerima, menyimpan, dan menyediakan akses laporan terpusat.

### 1.4 Objektif

| Fitur Utama | Deskripsi Spesifikasi Teknikal | Target Objektif |
|-------------|--------------------------------|-----------------|
| **Desktop App Features** | Electron + React 19 desktop app (Windows/macOS/Linux): enroll wajah, manajemen anggota, sesi absensi lokal, laporan, backup/restore. | Kontrol penuh secara offline tanpa browser |
| **Custom 2000+ Face Capacity** | Pengindeksan vektor SFace 128-d teroptimasi di SQLite C++ untuk kapasitas **>= 2000 wajah** per perangkat SBC tanpa kenaikan latensi. | Kapasitas besar untuk sekolah / perkantoran skala besar |
| **Connected Devices** | Monitoring & manajemen terpusat untuk multiple terminal SBC Orange Pi Lite 2 yang terhubung di jaringan LAN/WiFi via Server Pusat. | Telemetri & status heartbeat perangkat terhubung secara real-time |
| **Custom Branches / Locations** | Pengelompokan ID perangkat berdasarkan cabang, gedung, atau pintu masuk (e.g. Gerbang Utama, Pintu Utara, Cabang Bandung). | Manajemen lokasi absensi multi-titik |
| **Custom Organizations** | Struktur hirarki organisasi, divisi, departemen, dan kelas siswa. | Pengelompokan data pengguna yang fleksibel |
| **Remote Face Template Sync** | Sinkronisasi otomatis templet wajah (vektor embedding) dari Server Pusat Laravel 13 ke seluruh terminal SBC edge via REST API. | Registrasi satu kali di server, otomatis berlaku di semua lokasi |
| **Lifetime Cloud Attendance History** | Sinkronisasi riwayat absensi lokal (SQLite) ke Laravel 13 Server Pusat secara asynchronous via REST API / JSON. Data tersimpan selamanya. | Data absensi terpusat dan dapat diakses dari mana saja secara aman |
| **Audit Logs** | Pencatatan log aktivitas sistem (pendaftaran wajah, perubahan hak akses, perubahan status koneksi perangkat, dan kesalahan sistem). | Keamanan & transparansi jejak audit operasional (Audit Trail) |
| **Excel & PDF DTR Reports** | Pembuatan laporan kehadiran Daily Time Record (DTR) otomatis dalam format terformat **Excel (.xlsx)** dan **PDF**. | Siap cetak untuk rekapitulasi kehadiran harian/bulanan |
| **Raw CSV Data Export** | Fitur ekspor data mentah absensi dalam format **CSV** untuk integrasi langsung ke sistem HRMS / Payroll eksternal. | Data interoperabilitas tinggi |

---

## 2. Package Structure





### Alur Komunikasi Antar Package



---

### 3. Bill of Materials (BOM) Detail

### 3.1 Tabel BOM Lengkap

| No | Komponen | Part Number / Model | Qty | Fungsi Utama | Vcc (V) | I Max (mA) | Interface | Catatan |
|----|----------|---------------------|-----|--------------|---------|------------|-----------|---------|
| 1 | SBC Utama | Orange Pi Lite 2 | 1 | Main processing unit | 5.0 | 2000 | USB, GPIO, WiFi, HDMI | Allwinner H6 Quad-core Cortex-A53 @1.8GHz, 1GB LPDDR3 |
| 2 | Camera USB | UVC Mini USB Web Camera | 1 | Capture wajah untuk face recognition | 5.0 (VBUS) | 500 | USB 2.0 (UVC) | Standard UVC Plug & Play; kabel retractable USB Type-A; Plug and Play di Linux/Debian (`/dev/video0`) |
| 3 | Display | TFT LCD Touch Shield 2.4" | 1 | Menampilkan status absensi, nama, dan waktu | 3.3 / 5.0 | 120 | SPI (MOSI/MISO/CLK/CS/DC/RST) | Driver IC: ILI9341 atau ST7789V; resolusi 240x320; touch controller XPT2046 |
| 4 | Storage | MicroSD 8GB SDHC | 1 | Menyimpan OS, firmware service, database wajah lokal | 3.3 | 100 | SDIO / SPI | Class 10 minimum; A1 rating direkomendasikan; endurance >= 3000 write cycles per cell |
| 5 | MCU | Arduino Uno (ATmega328P) | 1 | Offload IO: TFT shield, LED, Buzzer, Button; bridge serial ke SBC | 5.0 | 500 | USB-B Serial, SPI (TFT), I2C (Expander), GPIO | ATmega328P @16MHz; 14 digital GPIO; 6 PWM; 6 analog |
| 6 | IO Expander | Modul I2C IO Expander (PCF8574 / MCP23017) | 1 | Menambah jumlah GPIO Arduino | 3.3 / 5.0 | 25 | I2C (SDA/SCL) | PCF8574: 8-channel, I2C address 0x20-0x27; MCP23017: 16-channel |
| 7 | Adaptor | Adaptor DC 5V/3A | 1 | Sumber daya utama dari jala-jala 220V AC | Input: 220V AC; Output: 5.0 | 3000 | DC Barrel 5.5x2.1mm | Minimum output 15W; ripple < 50mV; efisiensi >= 80% |
| 8 | Kabel Jaringan | Kabel UTP Cat5e/Cat6 3m + Konektor RJ45 | 1 set | Koneksi Ethernet ke router/switch LAN | - | - | RJ45 (10/100 Mbps) | Straight-through wiring (T568B); gunakan USB-to-Ethernet dongle |
| 9 | Modul UPS | Modul UPS 18650 Step-up ke 5V | 1 | Manajemen daya: charging baterai + output 5V saat AC terputus | Input: 5V; Output: 5.0 | 2000 | Passthrough power | IC boost: XL6009 atau MT3608; proteksi over-charge (4.2V), over-discharge (3.0V); seamless switchover < 100ms |
| 10 | Baterai | Li-Ion 18650 | 2 | Sumber daya cadangan (backup power) | 3.7 nominal | 2C discharge max | Slot pada modul UPS | Kapasitas minimum 2500mAh per sel; NCR18650B / Samsung 25R atau setara |
| 11 | LED Indikator | LED 5mm (Merah, Hijau, Kuning, Biru) | 4 | Status visual: Power, Processing, Success, Fail | 3.3 / 5.0 | 20 per LED | GPIO via I2C Expander | Resistor seri: R = (Vcc - Vf) / If; 5V, Vf=2V, If=10mA -> R=300 Ohm (gunakan 330 Ohm) |
| 12 | Buzzer | Piezo Buzzer Aktif 5V | 1 | Feedback audio untuk hasil absensi | 5.0 | 30 | GPIO via I2C Expander + transistor NPN driver | Aktif (built-in oscillator); frekuensi ~2.4kHz; SPL >= 85dB @10cm |
| 13 | Tombol Tactile | Push Button Tactile 6x6mm | 2-3 | Input manual: trigger, reset, service mode | 3.3 / 5.0 | < 1 (signal) | GPIO via I2C Expander, pull-up internal | SPST Normally Open; debounce: RC 10kOhm + 100nF hardware, atau 50ms software delay |

### 3.2 Spesifikasi Hardware SBC & Kamera

| Parameter | Spesifikasi |
|-----------|-------------|
| SBC Model | Orange Pi Lite 2 (Allwinner H6 Quad-Core Cortex-A53) |
| RAM | 1GB LPDDR3 |
| Port USB | 1x USB 3.0 Host, 1x USB 2.0 Host, 1x USB OTG |
| Camera Input | Standard USB Web Camera (UVC driverless) via USB 2.0/3.0 |
| Network | Onboard WiFi 802.11 b/g/n + USB-to-Ethernet Adapter (RTL8153) |
| Operating System | Debian Buster | 

---

### 4. Pinout & Interconnection Detail




### 4.1 Orange Pi Lite 2 <-> Arduino Uno (USB Serial Bridge)

| Parameter | Nilai |
|-----------|-------|
| Interface | USB Type-A (OPi host) <-> USB Type-B (Arduino) |
| Baud Rate | 115200 bps |
| Data Bits | 8 |
| Stop Bits | 1 |
| Parity | None |
| Device Node | `/dev/ttyACM0` atau `/dev/ttyUSB0` |
| Protokol Paket | JSON line-delimited, diakhiri '\n' |

**Contoh paket JSON (SBC -> Arduino):**

json
{"cmd":"display","status":"RECOGNIZED","name":"Budi S.","dept":"Engineering","time":"2026-09-28 07:30:15"}
{"cmd":"display","status":"UNKNOWN","name":"---","dept":"---","time":"2026-09-28 07:30:20"}
{"cmd":"standby","msg":"Silakan hadapkan wajah Anda"}
{"cmd":"reboot"}


### 4.2 Arduino Uno <-> TFT LCD Shield 2.4" (SPI)

TFT LCD dipasang sebagai shield di atas Arduino Uno (header langsung terhubung).

| TFT Pin | Arduino Uno Pin | Keterangan |
|---------|-----------------|------------|
| VCC | 5V | Tegangan supply display |
| GND | GND | Ground |
| CS (LCD) | D10 | SPI Chip Select untuk LCD |
| DC / RS | D9 | Data/Command selector |
| RST | D8 | Hardware Reset LCD |
| MOSI | D11 | SPI Data Out |
| SCK | D13 | SPI Clock |
| MISO | D12 | SPI Data In |
| BL / LED | D3 (PWM) | Backlight control via PWM |
| T_CS | D4 | SPI Chip Select untuk XPT2046 touch |
| T_IRQ | D2 | Touch interrupt |

> **Library Arduino**: `Adafruit_ILI9341` + `Adafruit_GFX` + `XPT2046_Touchscreen`

### 4.3 Arduino Uno <-> I2C Expander (PCF8574 / MCP23017)

| I2C Pin | Arduino Uno Pin | Keterangan |
|---------|-----------------|------------|
| SDA | A4 | I2C Data |
| SCL | A5 | I2C Clock |
| VCC | 5V | Supply |
| GND | GND | Ground |
| A0, A1, A2 | GND | I2C Address = 0x20 |

**Pull-up resistor**: 4.7kOhm dari SDA ke VCC dan SCL ke VCC.

### 4.4 I2C Expander (PCF8574) <-> LED, Buzzer, Button

| PCF8574 Pin | Fungsi | Arah | Komponen | Keterangan |
|-------------|--------|------|----------|------------|
| P0 | LED Power (Biru) | Output | LED 5mm Biru | HIGH = ON; seri 330 Ohm |
| P1 | LED Processing (Kuning) | Output | LED 5mm Kuning | HIGH = ON; seri 330 Ohm |
| P2 | LED Success (Hijau) | Output | LED 5mm Hijau | HIGH = ON; seri 330 Ohm |
| P3 | LED Fail (Merah) | Output | LED 5mm Merah | HIGH = ON; seri 330 Ohm |
| P4 | Buzzer | Output | Piezo Buzzer via transistor 2N2222 | HIGH = aktif; transistor: Base via 1kOhm, Emitter ke GND, Collector ke Buzzer+ |
| P5 | Button Manual Trigger | Input | Tactile Button | Pull-up internal; LOW saat ditekan |
| P6 | Button Reset | Input | Tactile Button | Pull-up internal; LOW saat ditekan |
| P7 | Reserved | - | - | Ekspansi ke depan |

### 4.5 Orange Pi Lite 2 <-> USB Camera

| Parameter | Nilai |
|-----------|-------|
| Interface | USB 2.0 Type-A (Plug & Play) |
| Protocol | USB Video Class (UVC standard) |
| Power | 5V VBUS dari port USB SBC, maks 500mA |
| Device Node | `/dev/video0` |
| Format Capture | MJPEG / YUYV (MJPEG direkomendasikan untuk efisiensi CPU) |

### 4.6 Konektivitas Jaringan (Ethernet & WiFi)

| Parameter | Nilai |
|-----------|-------|
| Orange Pi Lite 2 Ethernet | Gunakan USB-to-Ethernet Dongle (chipset RTL8153 / AX88179) |
| Orange Pi Lite 2 WiFi | Integrated WiFi 802.11 b/g/n (fallback) |
| Kabel | UTP Cat5e/Cat6 Straight-through 3m, T568B |
| Wiring T568B | Pin 1=Orange+, 2=Orange, 3=Hijau+, 4=Biru, 5=Biru+, 6=Hijau, 7=Coklat+, 8=Coklat |
| IP Assignment | DHCP default atau Static IP untuk production |

### 4.7 Power Tree (Distribusi Daya)


220V AC
  |
Adaptor 5V/3A (max 15W)
  |
Modul UPS 18650 (Passthrough + Charging)
  ├── Charging 4.2V --> Baterai 18650 x4 (backup)
  │
Rail 5V Utama
  ├── Orange Pi Lite 2 (5V, max 2A) via micro-USB/USB-C
  │   └── LED, Buzzer, Button (via Expander)
  └── Arduino Uno (5V via USB dari SBC)
      ├── TFT LCD Shield (5V dari Arduino header)
      └── I2C Expander (5V dari Arduino header)

> **Catatan**: Arduino tidak perlu adaptor terpisah jika sudah terhubung via USB ke SBC. SBC menyuplai 5V VBUS ke Arduino melalui port USB.

---

## 5. Power Consumption Budget & Battery Runtime

### 5.1 Tabel Power Budget

| Komponen | Mode Idle (mA) | Mode Peak/Active (mA) | Catatan |
|----------|---------------|----------------------|---------|
| Orange Pi Lite 2 | 400 | 1500 | Peak: saat inference face recognition (H6 full load) |
| UVC USB Camera | 100 | 400 | Video capture stream |
| Arduino Uno | 50 | 100 | Idle serial listen / aktif update TFT |
| TFT LCD 2.4" (backlight penuh) | 80 | 120 | Dapat dikurangi ke 50mA dengan PWM dimming |
| I2C IO Expander | 5 | 10 | Standby / aktif output |
| LED Indikator (semua nyala) | 40 | 80 | 4 x 10mA per LED maks |
| Piezo Buzzer | 0 | 30 | Hanya saat berbunyi |
| Modul UPS (konversi rugi) | 50 | 100 | Efisiensi boost converter ~85% |
| **Total (Power Draw)** | **~725** | **~2000** | Konfigurasi operasional normal |

### 5.2 Estimasi Runtime Baterai


Formula: Runtime (jam) = (Kapasitas (mAh) x Jumlah Sel x Efisiensi Boost) / Konsumsi Rata-rata (mA)
Efisiensi UPS Boost: 0.85
Konsumsi rata-rata: 1100 mA (operasional normal)


| Konfigurasi Baterai | Kapasitas Total | Estimasi Runtime |
|---------------------|----------------|-----------------|
| 4x 18650 @ 6800mAh | 27200mAh | (27200 x 0.85) / 1100 = ~21.0 jam |

> **Rekomendasi**: Gunakan **4x 18650 @ 2500mAh** untuk runtime >= 4 jam. Target minimum >= 2 jam (dicapai dengan 1x 3000mAh).

### 5.3 Rekomendasi Kapasitas Adaptor

| Kebutuhan | Arus |
|-----------|------|
| Peak load semua komponen | ~2.2A |
| Pengisian baterai 18650 (via modul UPS) | ~1.0A |
| **Total peak** | **~3.2A** |

> Adaptor **5V/3A (15W)** adalah minimum aman. **5V/4A (20W)** direkomendasikan untuk safety margin 25%.

---

## 6. Thermal Management & Reliability

### 6.1 Profil Panas Komponen Kritis

| Komponen | Suhu Operasi Normal | Suhu Max Aman | Risiko |
|----------|--------------------|--------------------|--------|
| Orange Pi Lite 2 (H6 SoC) | 50-65 derajat C | 85 derajat C (throttle) | Tinggi |
| Modul UPS XL6009 | 40-55 derajat C | 85 derajat C | Rendah-Sedang |
| Baterai Li-Ion 18650 | 20-40 derajat C (ideal) | 45 derajat C (charge), 60 derajat C (discharge max) | Sedang |
| TFT LCD + Driver | 30-45 derajat C | 70 derajat C | Rendah |
| Arduino Uno (ATmega328P) | 25-40 derajat C | 85 derajat C | Sangat Rendah |

### 6.2 Strategi Pendinginan

1. **Heat Sink Passive Wajib**:
   - Pasang heat sink aluminium (min 14x14mm) pada H6 SoC Orange Pi Lite 2 menggunakan thermal pad/compound.
   - Thermal paste 4-8 W/(m*K) (Arctic MX-4) atau thermal pad 1mm, 6W/(m*K).

2. **Ventilasi Casing**:
   - Minimal 4 lubang ventilasi 5mm diameter di sisi atas dan bawah casing.
   - Jika enclosure tertutup, tambahkan kipas 40mm 5V sebagai exhaust.

3. **Penempatan Baterai**:
   - Pisahkan baterai 18650 dari SBC dengan jarak minimal 20mm.

4. **Monitoring Suhu Software**:
   - Pantau via: `cat /sys/class/thermal/thermal_zone0/temp`
   - Alert buzzer jika suhu > 80 derajat C (implementasi di service systemd).

### 6.3 Reliability & Estimasi Lifetime

| Komponen | Estimasi Lifetime | Mode Kegagalan Umum |
|----------|------------------|---------------------|
| SBC (Orange Pi Lite 2) | 5-10 tahun | Overheating, SD card korupsi |
| MicroSD 8GB | 3-5 tahun (heavy write) | Write endurance habis |
| Li-Ion 18650 | 300-500 siklus (2-3 tahun) | Kapasitas turun, swelling |
| TFT LCD | 20.000 jam backlight | Backlight meredup |
| Arduino Uno | > 10 tahun | Sangat minimal |
| Adaptor 5V/3A | 3-5 tahun | Kapasitor elektrolitik bocor |

> **Rekomendasi**: Gunakan MicroSD bermutu tinggi (SanDisk Endurance, Samsung Pro Endurance). Mount `/var/log` ke tmpfs (RAM) untuk mengurangi write cycle SD card.

---

## 7. Constraint, Risiko & Mitigasi Hardware

### 7.1 Tabel Risiko Hardware

| No | Risiko | Dampak | Probabilitas | Strategi Mitigasi |
|----|--------|--------|-------------|-------------------|
| R01 | Power surge saat SBC boot (inrush current ~2.5A momentary) | UPS output drop -> Arduino/TFT reset | Tinggi | Pasang kapasitor bulk **1000uF 10V** + 100uF tantalum pada rail 5V output UPS |
| R02 | USB Camera + SBC USB bandwidth contention | Frame drop, latency naik | Sedang | Gunakan UVC MJPEG compression; set resolusi 1080p; gunakan port USB 3.0 OPi |
| R03 | MicroSD korupsi akibat power loss mendadak | OS gagal boot, data hilang | Sedang-Tinggi | Aktifkan UPS seamless takeover; mount dengan `noatime`; gunakan SD endurance grade |
| R04 | Overheating H6 SoC -> CPU throttle -> face recognition lambat | Latency > 3 detik | Tinggi | Wajib heat sink; ventilasi casing; software thermal monitoring |
| R05 | I2C bus collision | Display artifact, LED/buzzer tidak respons | Rendah | TFT via SPI, hanya IO Expander yang pakai I2C -> tidak ada konflik |
| R06 | 18650 swelling akibat overcharge/over-discharge | Kebakaran / kerusakan fisik | Rendah (dengan modul UPS benar) | Pilih modul UPS dengan protection built-in; verifikasi cutoff voltage |
| R07 | USB-to-Ethernet dongle tidak dikenali Armbian | Tidak ada koneksi jaringan | Sedang | Gunakan dongle chipset **RTL8153**; verifikasi dengan `lsusb` saat komisioning |
| R08 | TFT Shield pin conflict | Display tidak berfungsi | Sedang | Verifikasi pinout terhadap library `Adafruit_ILI9341`; jangan colok shield saat daya menyala |
| R09 | Baterai 18650 palsu / kapasitas tidak sesuai klaim | Runtime jauh di bawah estimasi | Tinggi | Beli dari supplier terpercaya (Panasonic NCR, Samsung INR, LG MH1); lakukan capacity test |
| R10 | Face recognition gagal karena pencahayaan kurang | Sistem tidak dapat identifikasi wajah | Sedang | Pasang LED ring light 5V di sekitar kamera; pertimbangkan kamera dengan sensor Sony IMX |

### 7.2 Constraint Teknis

| Constraint | Nilai Batas | Alasan |
|------------|-------------|--------|
| Face recognition latency target | < 3 detik | UX requirement |
| Maximum power draw dari UPS | 2.5A (12.5W) | Batas output modul UPS XL6009 |
| Jumlah wajah yang bisa dikenali | ~500-1000 wajah | Dibatasi RAM SBC (1GB) dan latensi lookup SQLite |
| Suhu lingkungan operasi | 0 - 45 derajat C | Batas operasi SBC dan baterai 18650 |
| Kelembaban lingkungan | < 85% RH (non-kondensasi) | Komponen elektronik tanpa conformal coating |

---

## 8. Roadmap Hardware V2 — Fabrikasi PCB & Casing

### 8.1 Motivasi Hardware V2

Perangkat V1 menggunakan modul-modul terpisah yang rentan terhadap:
- Koneksi longgar (female header / dupont wire)
- Ukuran total besar dan tidak rapi
- Banyak titik kegagalan potensial

**Hardware V2** mengintegrasikan semua komponen pendukung ke dalam custom PCB carrier 2-layer dan enclosure yang didesain khusus.

### 8.2 Spesifikasi PCB Custom Carrier 2-Layer

| Parameter PCB | Spesifikasi |
|---------------|------------|
| Layer | 2-layer (Top: signal + component; Bottom: ground plane + power) |
| Dimensi | <= 100mm x 100mm (JLCPCB minimum cost tier) |
| Material | FR4, ketebalan 1.6mm |
| Copper Weight | 1oz (35um) signal; 2oz opsional untuk power trace |
| Surface Finish | HASL (lead-free) atau ENIG |
| Minimum Track Width | 0.2mm signal, 0.5mm power |
| Via Size | Drill 0.3mm, pad 0.6mm |
| Komponen Terintegrasi | UPS circuit (XL6009), PCF8574, transistor buzzer, LED resistor array, RC debounce, konektor SBC header 40-pin, Arduino Uno header, USB Type-A, DC barrel jack |
| Software EDA | KiCad 7.x |

### 8.3 Konsolidasi Fitur V1 -> V2

| Fitur V1 (Modul Terpisah) | Implementasi V2 (PCB Carrier) |
|---------------------------|-------------------------------|
| Modul UPS 18650 terpisah | XL6009 + TP4056 + DW01A onboard PCB |
| I2C Expander modul terpisah | PCF8574 SMD langsung di PCB |
| LED + resistor flying wire | Resistor array SIP + LED footprint onboard |
| Buzzer + transistor driver terpisah | 2N2222 SOT-23 + pad buzzer onboard |
| Button dengan kabel dupont | Tactile switch SMD langsung di PCB |
| Kabel power terpisah | Jalur copper terintegrasi + fuse holder + TVS dioda |

### 8.4 Desain Enclosure / Casing

**Opsi A: 3D Print (PLA+ / PETG)**

| Parameter | Spesifikasi |
|-----------|------------|
| Material | PLA+ atau PETG (lebih tahan panas dari PLA biasa) |
| Dimensi Target | 200mm (L) x 120mm (W) x 50mm (H) |
| Ketebalan Dinding | 3mm minimum |
| Desain | 2 bagian: base + top cover; clip-fit atau screwed (M3 brass insert) |
| Mounting | Wall bracket VESA 75mm atau desk stand adjustable (+-15 derajat) |
| IP Rating | IP40 |

**Opsi B: Akrilik Laser-Cut**

| Parameter | Spesifikasi |
|-----------|------------|
| Material | Akrilik 3mm (clear atau frosted black) |
| Konstruksi | Panel laser-cut, sambungan baut M3 + spacer |
| Keunggulan | Biaya murah, estetika transparan, mudah dimodifikasi |
| Kelemahan | Kurang rigid, tidak ada IP rating |

### 8.5 Estimasi Biaya Fabrikasi V2 (Per Unit)

| Item | Estimasi Biaya |
|------|----------------|
| PCB 2-layer 100x100mm (JLCPCB, 5 pcs) | ~$5 USD (~Rp 80.000) |
| Komponen SMD untuk 1 unit PCB | ~$10-15 USD (~Rp 160.000-240.000) |
| 3D Print Casing (PLA, lokal) | ~Rp 50.000-100.000 per unit |
| Assembly & Testing | ~Rp 100.000-200.000 per unit |
| **Total Estimasi V2 (hardware tambahan dari V1)** | **~Rp 390.000-620.000** |

> Harga belum termasuk komponen utama (SBC, kamera, Arduino, baterai) yang sudah ada dari V1.

---

## 9. Acceptance Criteria & Checklist Validasi Hardware

> Semua item di bawah **harus** dinyatakan [x] sebelum unit dinyatakan siap deployment.

### A. Power & Boot

- [ ] **AC-01**: Adaptor 5V/3A menghasilkan tegangan 4.9V-5.1V diukur di terminal input UPS.
- [ ] **AC-02**: Modul UPS menghasilkan output 4.9V-5.1V di terminal output saat adaptor terhubung.
- [ ] **AC-03**: SBC boot dari MicroSD dalam **< 45 detik** hingga tampil login prompt.
- [ ] **AC-04**: Service `smart-absen.service` start otomatis setelah boot: `systemctl status smart-absen` menunjukkan `active (running)`.
- [ ] **AC-05**: Saat adaptor dicabut, UPS mensuplai daya tanpa reboot SBC (switcher dalam **< 100ms**).
- [ ] **AC-06**: LED Power (Biru) menyala saat sistem aktif.

### B. Camera

- [ ] **AC-07**: USB Camera terdeteksi: `ls /dev/video*` menunjukkan device node `/dev/video0`.
- [ ] **AC-08**: `v4l2-ctl --device=/dev/video0 --list-formats-ext` menampilkan format video (MJPEG/YUYV).
- [ ] **AC-09**: Live preview & capture frame dari UVC USB Camera berjalan lancar.

### C. Display & Arduino

- [ ] **AC-11**: Arduino Uno terdeteksi di SBC: `ls /dev/ttyACM*` atau `ls /dev/ttyUSB*`.
- [ ] **AC-12**: TFT LCD menampilkan tampilan standby dalam 10 detik setelah Arduino power-on.
- [ ] **AC-13**: SBC berhasil kirim JSON ke Arduino dan TFT merespons menampilkan data yang sesuai.
- [ ] **AC-14**: Brightness TFT dapat dikontrol via PWM dari Arduino.

### D. IO Feedback (LED, Buzzer, Button)

- [ ] **AC-15**: LED Hijau menyala + Buzzer 2 beep pendek saat status `RECOGNIZED`.
- [ ] **AC-16**: LED Merah menyala + Buzzer 1 beep panjang saat status `UNKNOWN`.
- [ ] **AC-17**: LED Kuning berkedip saat status `PROCESSING`.
- [ ] **AC-18**: Tombol Manual Trigger terdeteksi dalam < 200ms setelah ditekan (debounced).
- [ ] **AC-19**: Feedback total (dari keputusan recognition hingga LED + Buzzer aktif) dalam **< 500ms**.

### E. Face Recognition

- [ ] **AC-20**: Face detection berhasil mendeteksi wajah di pencahayaan indoor normal (>= 300 lux).
- [ ] **AC-21**: Face recognition latency **< 3 detik** per frame untuk database <= 100 wajah.
- [ ] **AC-22**: False Acceptance Rate (FAR) < 0.1% pada threshold default library.
- [ ] **AC-23**: False Rejection Rate (FRR) < 5% untuk wajah yang sudah terdaftar.

### F. Jaringan & Data

- [ ] **AC-24**: SBC mendapatkan IP address via DHCP dalam < 30 detik: `ip addr show`.
- [ ] **AC-25**: Data absensi tersimpan ke SQLite lokal: `sqlite3 /var/db/absensi.db "SELECT * FROM attendance ORDER BY id DESC LIMIT 5;"`.
- [ ] **AC-26**: Data absensi tersinkronisasi ke server remote dalam < 60 detik setelah record dibuat.

### G. Stress & Endurance

- [ ] **AC-27**: Sistem berjalan stabil selama **>= 8 jam operasi kontinu** tanpa crash, reboot spontan, atau overheating shutdown.
- [ ] **AC-28**: Suhu SoC tidak melebihi **75 derajat C** selama stress test 8 jam: `cat /sys/class/thermal/thermal_zone0/temp`.
- [ ] **AC-29**: Tidak ada memory leak: RAM usage tidak terus meningkat selama 8 jam (pantau via `free -h`).
- [ ] **AC-30**: MicroSD filesystem tidak ada error setelah 8 jam: `dmesg | grep -i error`.

---

## Lampiran

### A. Versi Dokumen

| Versi | Tanggal | Perubahan | Penulis |
|-------|---------|-----------|---------|
| 1.0.0 | 2026-09-28 | Initial release — draft untuk implementasi V1 | Tim Smart Absen |
| 2.0.0 | 2026-09-30 | draft untuk implementasi V2 | Tim Smart Absen |

### B. Referensi

| Referensi | URL / Sumber |
|-----------|--------------|
| Orange Pi Lite 2 | http://www.orangepi.org/html/hardWare/computerAndMicrocontrollers/details/Orange-Pi-Lite-2.html |
| ILI9341 TFT Controller Datasheet | https://cdn-shop.adafruit.com/datasheets/ILI9341.pdf |
| XPT2046 Touch Controller Datasheet | https://datasheetspdf.com/pdf/731591/XPT/XPT2046/1 |
| PCF8574 I2C Expander Datasheet | https://www.ti.com/lit/ds/symlink/pcf8574.pdf |
| XL6009 Boost Converter Datasheet | https://www.xlsemi.com/datasheet/XL6009%20datasheet.pdf |
| Debian Documentation | https://www.debian.org/doc/|
| Facenox - open source face recognition | https://github.com/facenox/facenox |

---

*Dokumen ini dihasilkan sebagai bagian dari proyek "Sekolahkita.net"*