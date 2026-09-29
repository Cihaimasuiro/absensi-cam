# Panduan Troubleshooting — Smart Absen Monorepo Polyglot

Dokumen ini memuat langkah-langkah diagnostik dan penyelesaian masalah umum pada komponen **Smart Absen**.

---

## 1. Troubleshooting Central Admin Server (`app-smart-absensi/`)

### Error: `Connection refused` atau `Database app_smart_absensi does not exist`
- **Penyebab**: Service MySQL Laragon/XAMPP belum berjalan atau DB belum dibuat.
- **Solusi**:
  1. Buka Laragon UI dan klik **Start**. Pastikan MySQL aktif di port `8081`.
  2. Jalankan perintah pembuatan DB dan migrasi:
     ```bash
     mysql -u root -P 8081 -e "CREATE DATABASE IF NOT EXISTS app_smart_absensi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     php artisan migrate --force
     php artisan db:seed --force
     ```

### Error: `Class Database\Factories\UserFactory not found`
- **Penyebab**: Namespace model Eloquent berada di `App\Domain\User\Models\User` (Modular Monolith).
- **Solusi**: Gunakan `User::firstOrCreate(...)` pada `DatabaseSeeder.php` atau atur custom factory resolution.

---

## 2. Troubleshooting Edge Node (Orange Pi Lite 2)

### Kamera USB `/dev/video0` Tidak Terdeteksi
- **Penyebab**: Koneksi kabel USB longgar atau driver UVC belum termuat di kernel.
- **Solusi**:
  1. Cek koneksi USB via terminal: `lsusb`
  2. Pastikan file device ada: `ls -l /dev/video*`
  3. Tes tangkapan frame dengan OpenCV / ffmpeg:
     ```bash
     ffplay /dev/video0
     ```

### Display Arduino TFT Tidak Menanggapi Komando
- **Penyebab**: Port serial salah atau baudrate mismatch (harus 115200 baud).
- **Solusi**:
  1. Jalankan `display_service.py` dengan auto-detect port:
     ```bash
     python3 scripts/display_service.py --port auto --demo
     ```
  2. Cek permission port serial: `sudo usermod -a -G dialout $USER`

---

## 3. Troubleshooting Sinkronisasi REST API Edge-to-Server

### Log Presensi Terpending di Edge Node (Tidak Masuk Server)
- **Penyebab**: Jaringan LAN/WiFi terputus atau URL server di `.env` salah.
- **Solusi**:
  1. Tes konektivitas HTTP dari Orange Pi ke Admin PC: `curl -I http://192.168.1.X:8000/api/v1/sync/templates`
  2. Cek file log lokal SQLite di `edge-node/storage/` untuk memastikan record tidak hilang.
