# Features — Smart Absen Monorepo Polyglot

Halaman ini mendokumentasikan fitur-fitur utama sistem **Smart Absen** pada tingkat Edge Node (Orange Pi Lite 2) dan Central Admin Server (Laravel 13).

---

## 1. Edge Node Features (Orange Pi Lite 2)

### Long-Distance & Multi-Face Pipeline
- **Real-Time Detection**: Menggunakan YuNet pada resolusi 640x480 @ 30 FPS untuk jangkauan 0.5m – 3.0m (long-distance walk-through).
- **Multi-Face Parallel**: Mampu memproses 3-5 wajah secara bersamaan dalam satu frame tanpa menghentikan arus jalan siswa/karyawan.

### On-Device Anti-Spoofing (Liveness Check)
- **600KB ONNX Liveness Model (Mini-FASNet)**: Memverifikasi tekstur 3D dan refleksi cahaya untuk membedakan wajah asli dari foto cetak atau layar HP.
- **Zero Extra Sensor Cost**: Menggunakan webcam USB standar tanpa perlu sensor infra merah (IR) mahal.

### Offline-First & High Capacity
- **Offline SQLite Storage**: Data presensi disimpan di SQLite lokal edge node, tetap berfungsi walau jaringan internet/LAN terputus.
- **Custom 900+ Face Capacity**: Mampu menampung dan mencocokkan hingga 900+ vektor embedding wajah dengan latensi < 30ms per frame.

### Microcontroller Display Feedback
- **TFT LCD & Buzzer Output**: Mengirim indikasi nama, departemen, dan jam presensi ke Arduino Uno + 2.4" TFT LCD Shield via USB Serial.

---

## 2. Central Admin Server Features (Laravel 13)

### Connected Devices & Multi-Location Management
- **Connected Devices Monitoring**: Pelacakan status dan heartbeat terminal Orange Pi Lite 2 terhubung.
- **Custom Branches / Locations**: Pengelompokan ID perangkat berdasarkan lokasi cabang, gedung, atau gerbang masuk.
- **Custom Organizations**: Pengelolaan struktur organisasi, divisi, departemen, dan kelas siswa.

### Data Sync & Automated Reporting
- **Remote Face Template Sync**: Sinkronisasi otomatis templet embedding wajah ke seluruh terminal edge node via `GET /api/v1/sync/templates`.
- **Cloud Attendance History**: Pengumpulan terpusat riwayat presensi dari seluruh edge node via `POST /api/v1/sync/attendance`.
- **Excel & PDF DTR Reports**: Pembuatan laporan Daily Time Record (DTR) otomatis format **Excel (.xlsx)** dan **PDF**.
- **Raw CSV Data Export**: Ekspor data mentah untuk integrasi ke sistem HRMS / Payroll eksternal.
- **Audit Logs**: Pencatatan jejak audit operasional sistem (perubahan data, pendaftaran wajah, dan aktivitas sync).
