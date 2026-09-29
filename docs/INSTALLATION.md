# Panduan Instalasi — Smart Absen Monorepo Polyglot

Dokumen ini menjelaskan langkah-langkah instalasi dan konfigurasi lingkungan pengembang maupun produksi untuk seluruh komponen **Smart Absen**.

---

## 1. Persyaratan Sistem

- **Central Admin PC (Laravel 13 Server)**:
  - Windows 10/11 dengan Laragon / XAMPP (PHP 8.3+, MySQL 8.0+ di port 8081).
  - Composer 2.x & Node.js 20+ (NPM / PNPM).

- **Edge Node (Orange Pi Lite 2)**:
  - Armbian / Debian Linux for H6 SBC.
  - Python 3.11+, OpenCV 4.x dengan DNN module enabled, SQLite3.
  - UVC USB Web Camera (`/dev/video0`).

- **Display Module (Arduino Uno)**:
  - Arduino IDE 2.x dengan library `Adafruit_GFX` dan `MCUFRIEND_kbv`.
  - Arduino Uno + 2.4" TFT LCD Shield terhubung via USB kabel ke Orange Pi.

---

## 2. Instalasi Central Admin Server (`app-smart-absensi/`)

1. **Masuk ke folder backend**:
   ```bash
   cd app-smart-absensi
   ```

2. **Install dependensi PHP & buat environment**:
   ```bash
   composer install
   copy .env.example .env
   php artisan key:generate
   ```

3. **Pastikan MySQL Laragon aktif** di port 8081, lalu jalankan migrasi dan seeder:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

4. **Jalankan dev server**:
   ```bash
   php artisan serve
   ```

---

## 3. Instalasi Edge Node (`edge-node/`)

1. **Bootstrap Orange Pi Lite 2**:
   ```bash
   chmod +x scripts/setup_orangepi.sh
   ./scripts/setup_orangepi.sh
   ```

2. **Uji Kamera USB & Display Arduino**:
   ```bash
   ls -l /dev/video*
   python3 scripts/display_service.py --demo
   ```

---

## 4. Flash Firmware Arduino (`firmware/`)

1. Buka `firmware/tft_usb_display/tft_usb_display.ino` di Arduino IDE.
2. Pilih Board **Arduino Uno** dan Port COM/Serial yang sesuai.
3. Upload firmware ke Arduino.
