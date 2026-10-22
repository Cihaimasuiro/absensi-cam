<div align="center">

[English](../../README.md) · **Bahasa Indonesia**

# Absensi-Cam

### Absensi pengenalan wajah real-time yang mengutamakan edge, untuk perangkat keras terbatas.

**Menjalankan inferensi di Orange Pi. Sinkron ke Laravel saat jaringan memungkinkan.**

[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Rust](https://img.shields.io/badge/Rust-1.91-000000?style=flat-square&logo=rust&logoColor=white)](https://www.rust-lang.org)
[![ONNX Runtime](https://img.shields.io/badge/ONNX_Runtime-edge_inference-005CED?style=flat-square&logo=onnx&logoColor=white)](https://onnxruntime.ai)
[![License](https://img.shields.io/badge/license-internal-f4c430?style=flat-square)](../../LICENSE)

[Fitur](#fitur-utama) · [Arsitektur](#arsitektur) · [Perangkat Keras](#kebutuhan-perangkat-keras) · [Mulai Cepat](#mulai-cepat) · [Panduan](#panduan-pengembangan) · [Dokumentasi](#dokumentasi)

<code>composer install && php artisan migrate --seed</code>

</div>

> [!IMPORTANT]
> Embedding wajah adalah data biometrik. Data ini dienkripsi saat disimpan dengan AES-256-GCM dan
> tidak pernah dikirim ke edge dalam bentuk plaintext. Penarikan persetujuan akan melakukan
> soft-delete pada template, yang menyebar ke setiap perangkat terpasang sebagai tombstone
> `op=delete` pada delta sync berikutnya (UU PDP No. 27/2022, GDPR Art. 17).

---

Sebagian besar sistem absensi pengenalan wajah bergantung pada inferensi di cloud. Pendekatan itu
menuntut internet berbandwidth tinggi yang konstan dan menambah latensi pada setiap pemindaian.
Absensi-Cam menjalankan seluruh pipeline deteksi, liveness, dan pengenalan pada Single Board
Computer yang terjangkau, dan memperlakukan jaringan sebagai kemudahan opsional, bukan keharusan.

## Mengapa Absensi-Cam?

| Fitur                   | Absensi-Cam (Edge-First)  | Sistem Berbasis Cloud |
| :---------------------- | :-----------------------: | :-------------------: |
| **Lokasi Inferensi**    | Perangkat Edge Lokal (ONNX) | Cloud Jarak Jauh    |
| **Ketergantungan Internet** | Opsional (hanya sinkronisasi) | Wajib         |
| **Latensi**             | Real-time (< 50ms)        | Bergantung Jaringan   |
| **Biaya Perangkat Keras** | Sangat Rendah (Orange Pi) | Tinggi (Server/GPU) |

> [!NOTE]
> **Offline-first sejak awal.** Deteksi, pelacakan, dan pencocokan template seluruhnya terjadi di
> perangkat edge. Absensi ditulis ke outbox SQLite lokal lalu disinkronkan ke Laravel saat koneksi
> memungkinkan. Jika internet terputus, absensi tetap tercatat dan otomatis terkirim begitu
> koneksi kembali.

## Fitur Utama

- **Inferensi AI di perangkat** — Deteksi wajah real-time (YuNet) dan pengenalan (ArcFace/SFace)
  berjalan sepenuhnya pada perangkat keras ARM lokal melalui `onnxruntime`, digerakkan oleh Rust
  native (`clients/edge-engine-rs`).
- **Keamanan biometrik** — Embedding wajah dienkripsi saat disimpan dengan AES-256-GCM. Kunci
  dirotasi dan didistribusikan ke perangkat edge terpasang melalui perintah artisan khusus.
- **Sinkronisasi offline-first** — Daemon outbox berbasis SQLite (`smart-absensi-sync`)
  menyinkronkan catatan absensi dengan UUID v4 dan timestamp UTC ISO-8601, sehingga catatan
  bertahan melewati gangguan jaringan tanpa hilang atau terduplikasi.
- **Pembaruan over-the-air** — Terapkan model `.onnx` baru dan binary engine ke seluruh armada
  perangkat edge dari dashboard Laravel. Satu manifest di server pusat berarti satu file yang
  diterbitkan memperbarui seluruh armada.
- **Versioning latent space** — Penandaan `MODEL_VERSION` yang ketat mencegah vektor biometrik dari
  arsitektur model berbeda untuk pernah dibandingkan.
- **Pairing perangkat dinamis** — Perangkat dipasangkan ke backend dengan kode singkat dan
  menyediakan `.env`-nya secara otomatis, tanpa penanganan kredensial manual.
- **Rollback otomatis** — Engine edge memantau crash loop `systemctl` dan mengembalikan pembaruan
  OTA yang buruk secara mandiri.
- **Liveness dan anti-spoofing** — Anti-spoofing pasif Mini-FASNet dengan analisis frekuensi FFT,
  konfirmasi temporal tiga frame, veto spoof spasial, dan pencatatan margin real-time. Mengalahkan
  serangan replay foto dan layar ponsel tanpa sensor IR tambahan.
- **Cooldown absensi** — Cooldown yang dapat dikonfigurasi (default lima menit) dipulihkan dari
  SQLite saat engine restart, sehingga reboot tidak pernah menghasilkan pemindaian ganda.

## Arsitektur

Proyek ini adalah modular monolith ditambah runtime edge native.

### 1. Backend — Laravel

Modular monolith di bawah `app/Domain/{Attendance,Device,Enrollment,Report,School,Student,User}`,
bertanggung jawab untuk:

- Mengelola siswa, kelas, gedung, dan perangkat.
- Menghasilkan embedding wajah melalui worker antrean saat pendaftaran.
- Menyediakan REST API yang dipakai perangkat edge untuk menyinkronkan batch absensi secara
  idempoten (`/api/v1/attendance/batch`).
- Menyiarkan pemicu OTA dan delta konfigurasi.

### 2. Frontend — Blade, Tailwind, Alpine.js

Dashboard administrasi yang dibangun dengan Tailwind CSS v4 dan build CSP Alpine.js untuk
interaktivitas, serta ikon Lucide untuk ikonografi yang konsisten.

### 3. Edge Engine — Rust native (`clients/edge-engine-rs`)

Runtime single-binary yang dikompilasi untuk ARM Linux, menargetkan Orange Pi Lite 2:

- **Capture** — Capture YUYV zero-copy V4L2 libc murni pada sekitar 30 FPS.
- **Pipeline AI terpisah** — Deteksi YuNet, liveness MiniFASNet, dan ekstraksi ArcFace/SFace
  berjalan pada thread khusus dengan pacing termal adaptif (300 ms idle, 50 ms aktif).
- **Streaming** — Stream MJPEG berbasis `axum` plus overlay vektor SSE untuk kanvas langsung.
- **Daemon outbox** — Unit systemd independen (`smart-absensi-sync.service`) yang terus menguras
  absensi SQLite lokal ke backend.
- **Jejak sumber daya** — 10–25% CPU saat standby, ~50% saat tampilan web terbuka, dengan suhu
  stabil sekitar 68 °C setelah pacing termal menggantikan throttling 80,2 °C sebelumnya.

### 4. Engine referensi legacy — Python (`archive/edge-engine-py`)

Dipertahankan untuk prototipe laboratorium dan benchmark offline. Bagian ini bukan jalur produksi.

## Kebutuhan Perangkat Keras

Engine edge disetel untuk Single Board Computer dengan sumber daya terbatas.

- **Perangkat target** — Orange Pi Lite 2 (ARM Cortex-A53).
- **RAM** — 1 GB; engine dirancang agar tetap di bawah 150 MB RSS.
- **Kamera** — Webcam USB UVC standar. MJPEG lebih disukai karena overhead CPU lebih rendah.
- **Pendinginan** — Heatsink pasif disarankan. Engine memantau zona termal dan melaporkan
  degradasi ke backend.

## Mulai Cepat

### 1. Backend

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

**Queue worker produksi (Supervisor).** Buat
`/etc/supervisor/conf.d/absensi-cam-worker.conf`:

```ini
[program:absensi-cam-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/absensi-cam/artisan queue:work --queue=enrollments,default --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/absensi-cam/storage/logs/worker.log
```

**Scheduler produksi.** Tambahkan ke crontab server (`crontab -e`):

```bash
* * * * * cd /path/to/absensi-cam && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Pasangkan perangkat edge

> [!IMPORTANT]
> Tanpa token perangkat, edge tidak dapat menyinkronkan ke Laravel.

1. Buka admin Laravel, masuk ke **Devices**, lalu buat **pairing code** baru.
2. Salin kode yang muncul (misalnya `AB12CD`).
3. Jalankan alur pairing di perangkat edge, lalu masukkan URL backend dan kode pairing.
4. `.env` perangkat terisi otomatis dengan `SMART_ABSENSI_TOKEN` dan
   `SMART_ABSENSI_DEVICE_ID`.

### 3. Jalankan stack pengembangan

```bash
# Semua sekaligus (Laravel + Vite + edge engine)
npm run dev

# Hanya web (Laravel + Vite), tanpa edge
npm run dev:web

# Hanya edge engine
npm run dev:edge
```

> [!NOTE]
> `npm run dev` tidak memakai `--kill-others`. Engine edge tetap berjalan meski Laravel berhenti,
> sesuai perilaku offline-first yang menjadi dasar sistem ini.

### 4. Terapkan Rust edge engine

Di Orange Pi, pasang binary mandiri:

```bash
# 1. Cross-compile di host untuk target aarch64
cargo zigbuild --target aarch64-unknown-linux-gnu.2.28 --release -p engine-cli

# 2. Salin binary ke perangkat, atau ambil dari server deployment lokal
curl -fsSL http://<HOST_IP>:8888/engine-cli -o /tmp/smart-absensi-sync
sudo install -m 755 /tmp/smart-absensi-sync /usr/local/bin/smart-absensi-sync

# 3. Kelola unit systemd
sudo systemctl restart smart-absensi.service smart-absensi-sync.service
sudo systemctl status smart-absensi.service smart-absensi-sync.service
```

## Persyaratan Database dan Audit Logging

Tabel `activity_log` dilindungi oleh **SQL trigger append-only**
(`prevent_activity_log_update` dan `prevent_activity_log_delete`) untuk menjamin jejak audit yang
tidak dapat diubah untuk tindakan administratif dan absensi.

Saat deployment atau migrasi:

- **Binary logging** — Jika binary logging MySQL aktif, pembuatan trigger ini memerlukan hak
  `SUPER`, atau Anda harus menyetel `log_bin_trust_function_creators = 1`.
- **Pembersihan log** — Karena penghapusan diblokir di level database, perintah seperti
  `php artisan activitylog:clean` akan gagal dengan `PDOException`. Pengarsipan atau pemotongan
  tabel memerlukan penghapusan trigger terlebih dahulu, pembersihan sebagai administrator
  database, lalu pembuatan ulang trigger.
- **Hak akses aplikasi** — Untuk imutabilitas sejati, cabut `UPDATE` dan `DELETE` pada
  `activity_log` dari user MySQL yang dipakai aplikasi, melengkapi perlindungan trigger.

## Waktu Kelas dan Toleransi

Pada versi 2.3 dan setelahnya, boolean `late_threshold_enabled` digantikan oleh satu integer
`late_tolerance_minutes`. Kelas yang sebelumnya dikonfigurasi dengan pelacakan keterlambatan mati
(`late_threshold_enabled = false`) dimigrasikan ke toleransi maksimum `120` menit. Akibatnya, DTR
kini menandai kedatangan lebih dari dua jam sebagai "Late" padahal sebelumnya tidak pernah
ditandai. Perangkat edge menerima waktu per grup dalam array JSON `groups` pada endpoint
`/api/v1/config`, bukan sebagai nilai datar.

## Panduan Pengembangan

Repositori ini menerapkan standar rekayasa yang ketat bagi agen AI maupun pengembang manusia.
Sebelum berkontribusi, baca:

1. **[AGENTS.md](../../AGENTS.md)** — Panduan arsitektur enterprise Laravel: modular monolith,
   controller tipis, query builder, DTO, kontrak sinkronisasi batch.
2. **[EDGE-GUIDELINES.md](../EDGE-GUIDELINES.md)** — Standar edge engine: konvensi Rust native,
   keamanan memori, pacing AI terpisah.
3. **[PRD.md](../PRD.md)** — Kebutuhan produk (spesifikasi monorepo v2.5.0).
4. **[DESIGN.md](../DESIGN.md)** — Bahasa desain dan spesifikasi token.

> [!WARNING]
> Kami menerapkan **zero over-engineering**. Jangan menambahkan abstraksi spekulatif, kelas
> wrapper yang tidak perlu, microservice, atau dependensi tambahan kecuali benar-benar diperlukan
> dan terukur.

> [!NOTE]
> Seluruh komentar kode ditulis dalam Bahasa Indonesia. Seluruh dokumentasi Markdown ditulis
> dalam Bahasa Inggris. Lihat [AGENTS.md §100](../../AGENTS.md) untuk aturan lengkapnya.

## Dokumentasi

| Dokumen | Isi |
| :--- | :--- |
| [AGENTS.md](../../AGENTS.md) | Aturan rekayasa, batasan arsitektur, kebijakan komentar |
| [docs/README.md](../README.md) | Pusat dokumentasi — arsitektur, data model, konvensi |
| [docs/PRD.md](../PRD.md) | Kebutuhan produk dan kriteria penerimaan |
| [docs/DESIGN.md](../DESIGN.md) | Token desain dan bahasa UI |
| [docs/EDGE-GUIDELINES.md](../EDGE-GUIDELINES.md) | Standar rekayasa edge engine |
| [docs/ENCRYPTION.md](../ENCRYPTION.md) | Format enkripsi template dan manajemen kunci |
| [docs/SERVER_FACE_EXTRACT.md](../SERVER_FACE_EXTRACT.md) | Deployment binary face-extract server |
| [docs/TFT_STREAM.md](../TFT_STREAM.md) | Protokol streaming layar TFT |
| [docs/LITERATURE.md](../LITERATURE.md) | Pemilihan model dan catatan riset |
| [SECURITY.md](../../SECURITY.md) | Pelaporan kerentanan dan postur keamanan |
| [THIRD_PARTY_LICENSES.md](../../THIRD_PARTY_LICENSES.md) | Lisensi dependensi |

## Lisensi

Proyek ini dilisensikan untuk penggunaan internal. Lihat file [LICENSE](../../LICENSE) untuk
detailnya.

<div align="center">

**[Kembali ke atas](#absensi-cam)**

</div>
