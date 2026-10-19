# PLAN & TODO — Edge Monitor (Display Ringan & Video Interaktif)

> Proyek: `absensi-cam` · Komponen: `engine-server` (Rust / axum, port 5000)
> Target perangkat: Orange Pi Lite 2 (Allwinner H6, 1 GB RAM, tanpa akselerasi GPU yang bisa diandalkan)
> Dokumen: `plan-and-todo.md` · Versi dokumen: 1.0 · Tanggal: 19 Oktober 2026
> Bahasa: Indonesia

---

## 0. Cara Memakai Dokumen Ini

Dokumen ini menggabungkan **rencana (plan)** dan **daftar tugas (todo)** dalam satu file.
Urutan baca yang disarankan:

1. Bagian 1–4: pahami tujuan, kendala, asumsi, dan batasan.
2. Bagian 5–9: pahami arsitektur, kontrak API, dan desain modul.
3. Bagian 10: daftar tugas per fase (inilah yang dicentang saat bekerja).
4. Bagian 11–17: pengujian, performa, keamanan, deployment, risiko.
5. Lampiran: glosarium, template, dan checklist rilis.

Konvensi penulisan:

| Simbol | Arti |
| ------ | ---- |
| `[ ]`  | belum dikerjakan |
| `[x]`  | selesai dan terverifikasi |
| `[~]`  | sedang dikerjakan |
| `[!]`  | terblokir (tulis alasan di catatan) |
| `P3-04` | ID tugas: fase 3, tugas ke-4 |
| `AC`   | Acceptance Criteria (kriteria selesai) |
| `Est.` | estimasi jam kerja efektif satu orang |

### 0.1 Status Verifikasi Repositori (PENTING)

Saat dokumen ini dibuat, repositori `https://github.com/Cihaimasuiro/absensi-cam`
hanya berisi satu commit dengan satu file (`.gitattributes`). Artinya:

- Tidak ada kode `engine-server`, `Cargo.toml`, atau struktur modul yang bisa dibaca.
- Semua nama modul, struct, dan path file di dokumen ini adalah **usulan**, bukan hasil membaca kode nyata.
- Angka performa (RAM, FPS, CPU) di dokumen ini adalah **anggaran target**, bukan hasil pengukuran.
- Tugas pertama (P0) adalah mencocokkan dokumen ini dengan kode sebenarnya dan memperbaiki asumsi yang salah.

Jika kode ada di repositori lain atau di branch lain, jalankan P0-01 terlebih dahulu.

---

## 1. Tujuan Utama

Membuat halaman web pemantau internal (debug & status monitor) yang disajikan langsung oleh
program Rust (`engine-server` berbasis axum di port 5000), tanpa menambah beban GUI berat di
Orange Pi Lite 2.

### 1.1 Fitur Inti

**A. Display Status Mesin (read-only)**

- FPS kamera (capture) dan FPS inferensi AI secara langsung.
- Jumlah template wajah aktif di RAM beserta versinya.
- Status antrean pengiriman presensi (outbox sync) ke server pusat.

**B. Video Interaktif (bisa diklik)**

- Klik kotak wajah memunculkan pop-up: nama, skor kemiripan, dan skor pembanding ke-2 (top-2).
- Toggle overlay untuk menyalakan/mematikan kotak hijau deteksi.
- Pause video dan ambil snapshot instan.

### 1.2 Alasan Pendekatan Ini

| Aspek | Pendekatan GUI native (egui/wgpu) | Pendekatan web via axum |
| ----- | --------------------------------- | ----------------------- |
| RAM tambahan | tinggi, tergantung backend grafis | mendekati nol untuk aset statis |
| Ketergantungan GPU | ya | tidak (render di browser klien) |
| Akses | harus di layar perangkat | dari laptop/HP lewat jaringan |
| Kompleksitas build di ARM | tinggi | rendah |
| Debug jarak jauh | sulit | mudah |

Catatan kejujuran: klaim "~0 MB RAM" hanya berlaku untuk aset statis yang disajikan dari
segmen `.rodata`. Koneksi klien, buffer frame, dan encoder JPEG tetap memakai memori. Semua
komponen itu dianggarkan di Bagian 9 dan harus diukur di P11.

### 1.3 Kriteria Keberhasilan Tingkat Tinggi

1. Membuka `http://<IP-ORANGE-PI>:5000/` di browser LAN menampilkan video langsung dalam < 3 detik.
2. Panel status memperbarui nilai setidaknya 1 kali per detik.
3. Klik pada kotak wajah memunculkan pop-up berisi nama dan top-2 skor.
4. Dengan 1 klien terhubung, penambahan CPU di perangkat < 10% dari satu core (target; diukur di P11).
5. Dengan 0 klien terhubung, biaya tambahan hampir nol (tidak ada encode JPEG yang sia-sia).
6. Penambahan RSS proses `engine-server` akibat fitur monitor ≤ 12 MB dengan 1 klien (target).
7. Akurasi pengenalan wajah dan latensi presensi **tidak** menurun karena fitur monitor.

---

## 2. Konteks Perangkat dan Kendala

### 2.1 Orange Pi Lite 2 (ringkas)

| Item | Nilai | Dampak pada desain |
| ---- | ----- | ------------------ |
| SoC | Allwinner H6 (4x Cortex-A53) | CPU lemah; hindari kerja berulang per klien |
| RAM | 1 GB | ONNX Runtime sudah memakai sekitar 119 MB (angka dari Anda, perlu diukur ulang) |
| GPU | Mali-T720 | jangan bergantung pada GL/Vulkan |
| Jaringan | Wi-Fi/Ethernet sesuai konfigurasi | bandwidth MJPEG harus dibatasi |
| Penyimpanan | kartu SD / eMMC | hindari tulis berlebihan (log, snapshot) |
| Termal | pasif, bisa throttling | CPU tambahan = suhu naik = FPS turun |

### 2.2 Kendala Teknis yang Mengikat

- **K1.** Jalur utama (capture → deteksi → rekognisi → presensi) adalah prioritas mutlak.
  Fitur monitor hanya boleh mengambil sisa kapasitas.
- **K2.** Tidak boleh ada alokasi besar berulang per frame di jalur monitor.
- **K3.** Tidak boleh ada dependensi frontend yang butuh proses build (npm, bundler). Satu file HTML.
- **K4.** Tidak boleh menambah dependensi crate besar kecuali ada alasan terukur.
- **K5.** Monitor harus bisa dimatikan total lewat feature flag atau konfigurasi tanpa mengubah perilaku inti.
- **K6.** Data wajah dan nama adalah data pribadi. Akses monitor harus dilindungi.
- **K7.** Halaman harus berfungsi di browser modern pada HP dan laptop tanpa plugin.

### 2.3 Hal yang Dipakai Ulang (diasumsikan sudah ada)

- Capture kamera via V4L2 yang berjalan terus.
- Pipeline inferensi ONNX Runtime.
- Server axum di port 5000.
- Penyimpanan template wajah di RAM.
- Antrean outbox untuk sinkronisasi presensi.

Semua poin di atas **belum terverifikasi** terhadap repositori (lihat 0.1).

---

## 3. Asumsi dan Pertanyaan Terbuka

### 3.1 Asumsi (harus divalidasi di P0)

| ID | Asumsi | Cara validasi | Jika salah |
| -- | ------ | ------------- | ---------- |
| A1 | `engine-server` memakai axum dan tokio multi-thread | baca `Cargo.toml` | sesuaikan snippet ke framework yang ada |
| A2 | Ada satu titik di kode tempat frame terbaru tersedia | telusuri modul capture | buat titik itu (FrameHub) di P2 |
| A3 | Kamera dapat menghasilkan MJPEG native (UVC) | `v4l2-ctl --list-formats-ext` | butuh encoder JPEG software (lihat P2-05) |
| A4 | Hasil deteksi memuat bbox, id, skor | baca struct hasil | tambahkan field di P3 |
| A5 | Rekognisi dapat mengembalikan top-2 skor | baca modul matcher | ubah matcher agar menyimpan 2 kandidat teratas (P8-01) |
| A6 | Template wajah punya penanda versi | baca modul template | tambahkan versi/hash di P4-03 |
| A7 | Outbox punya hitungan pending/gagal | baca modul outbox | tambahkan counter di P4-04 |
| A8 | Jaringan LAN dipercaya sebagian, bukan sepenuhnya | tanya pemilik jaringan | naikkan tingkat keamanan di P10 |
| A9 | Resolusi kamera 640x480 atau 1280x720 | baca konfigurasi capture | sesuaikan anggaran bandwidth |
| A10 | Rust edition dan MSRV sudah ditetapkan | baca `rust-toolchain` | ikuti yang ada |

### 3.2 Pertanyaan Terbuka (jawab sebelum fase terkait)

1. Apakah kamera mengeluarkan MJPEG, YUYV, atau NV12? (menentukan P2)
2. Berapa resolusi dan FPS capture sekarang? (menentukan anggaran bandwidth)
3. Apakah nama pegawai boleh tampil di layar monitor? Siapa yang boleh melihat? (menentukan P10)
4. Apakah ada reverse proxy atau firewall di depan port 5000?
5. Apakah perangkat dipasang di jaringan yang bisa diakses publik?
6. Apakah perlu riwayat (log) metrik, atau cukup nilai langsung?
7. Apakah snapshot boleh disimpan di perangkat atau hanya diunduh ke klien?
8. Apakah ada lebih dari satu kamera?
9. Apakah mode debug perlu mengubah ambang pencocokan (threshold) secara langsung? (di luar scope awal)
10. Berapa banyak klien monitor bersamaan yang realistis? (menentukan batas koneksi)

### 3.3 Keputusan yang Sudah Diambil

- D1. Satu file HTML dengan CSS dan JS inline, disajikan lewat `include_str!` atau `include_bytes!`.
- D2. Video memakai MJPEG (`multipart/x-mixed-replace`) karena didukung semua browser tanpa JS.
- D3. Status dan kotak deteksi memakai SSE (Server-Sent Events), bukan WebSocket, karena satu arah.
- D4. Overlay digambar di `<canvas>` di atas elemen `<img>`; hit-testing dilakukan di klien.
- D5. Toggle overlay dan pause dilakukan di sisi klien agar tidak membebani server.
- D6. Snapshot diambil di server dari frame terbaru (resolusi penuh), bukan dari tampilan layar.
- D7. Fitur monitor berada di balik Cargo feature `monitor-ui` dan konfigurasi runtime.

---

## 4. Ruang Lingkup

### 4.1 Dalam Scope

- Endpoint HTTP untuk halaman, video, status, event, snapshot.
- Pengumpulan metrik ringan (FPS, template, outbox).
- Halaman HTML tunggal dengan panel status, video, overlay, pop-up.
- Pengamanan akses dasar (token, batas koneksi, bind address).
- Pengujian fungsional, beban ringan, dan pengukuran sumber daya.
- Dokumentasi operasional (systemd, troubleshooting).

### 4.2 Di Luar Scope (non-goals)

- Mengubah algoritma deteksi atau rekognisi.
- Mendaftarkan atau menghapus wajah lewat UI (hanya melihat).
- Merekam video ke penyimpanan.
- Streaming ke internet publik.
- Autentikasi multi-pengguna dengan peran.
- Aplikasi native (egui, Tauri) untuk perangkat edge.
- Mengubah ambang pencocokan lewat UI (dicatat sebagai ide lanjutan di Bagian 16).

### 4.3 Batas Kualitas

- Halaman boleh sederhana secara visual. Fokus pada kejelasan dan biaya sumber daya.
- Tidak ada animasi berat, tidak ada pustaka grafik pihak ketiga.
- Dukungan browser: dua versi mayor terakhir Chrome, Firefox, Safari, Edge.
- Tidak mendukung Internet Explorer.

---
## 5. Arsitektur

### 5.1 Gambaran Umum

```text
                         ┌──────────────────────────── Orange Pi Lite 2 ───────────────────────────┐
                         │                                                                          │
  Kamera USB/CSI ──V4L2──►  CaptureThread ──► FrameHub (latest JPEG, Arc<Bytes>) ──┐                 │
                         │        │                                               │                 │
                         │        ▼                                               ▼                 │
                         │  Inference (ONNX) ──► Matcher ──► DetectionBus     MJPEG handler          │
                         │        │                  │            │          (per klien, lazy)       │
                         │        ▼                  ▼            ▼                                  │
                         │   Metrics (atomics)   Template    SSE handler  ◄── /events               │
                         │        │              Store (RAM)      │                                  │
                         │        ▼                  ▲            │                                  │
                         │   /api/status ◄───────────┘            │                                  │
                         │        ▲                               │                                  │
                         │   Outbox (SQLite/queue) ───────────────┘                                  │
                         │                                                                          │
                         │   axum :5000  ──►  GET /  (HTML statis dari include_str!)               │
                         └──────────────────────────────────────────────────────────────────────────┘
                                                      ▲
                                                      │ HTTP (LAN)
                                             Browser laptop / HP
```

### 5.2 Prinsip Desain

1. **Satu produsen, banyak konsumen.** Frame dan hasil deteksi dihasilkan sekali, dibaca oleh sejumlah klien tanpa menggandakan kerja.
2. **Lazy work.** Jika tidak ada klien, tidak ada encode, tidak ada serialisasi event.
3. **Latest-wins.** Konsumen lambat boleh melewatkan frame lama. Tidak ada antrean yang tumbuh tanpa batas.
4. **Tidak ada blocking di jalur inti.** Pengiriman ke klien tidak boleh menahan thread capture atau inferensi.
5. **Atomics untuk metrik.** Penulisan metrik tidak memakai mutex di jalur panas.
6. **Fail-open untuk inti, fail-closed untuk monitor.** Jika monitor error, inti tetap jalan. Jika token salah, monitor menolak.

### 5.3 Komponen dan Tanggung Jawab

| Komponen | Tanggung jawab | Usulan lokasi |
| -------- | -------------- | ------------- |
| `FrameHub` | menyimpan frame JPEG terbaru, memberi tahu pelanggan | `src/monitor/frame_hub.rs` |
| `Metrics` | menyimpan counter dan gauge atomik | `src/monitor/metrics.rs` |
| `DetectionBus` | menyiarkan hasil deteksi per frame | `src/monitor/detection_bus.rs` |
| `StatusSnapshot` | merakit status dari metrik, template, outbox | `src/monitor/status.rs` |
| `routes` | mendaftarkan endpoint axum | `src/monitor/routes.rs` |
| `auth` | memeriksa token dan batas koneksi | `src/monitor/auth.rs` |
| `assets` | menyimpan HTML statis | `src/monitor/assets/index.html` |
| `config` | opsi runtime monitor | `src/monitor/config.rs` |

### 5.4 Alur Data per Frame

1. Thread capture membaca satu buffer dari V4L2.
2. Jika format MJPEG: bungkus byte menjadi `Bytes` tanpa decode. Jika bukan: tandai perlu encode (lazy).
3. Thread capture memanggil `FrameHub::publish(frame_id, bytes_or_raw)`.
4. Inferensi mengambil frame (jalur terpisah) dan menghasilkan daftar `Detection`.
5. Matcher menghitung skor dan top-2 per wajah.
6. `DetectionBus::publish(FrameDetections)` dipanggil hanya jika ada pelanggan SSE.
7. `Metrics` diperbarui: `capture_frames`, `infer_frames`, `last_infer_ms`.
8. Handler MJPEG membaca frame terbaru dari `FrameHub` dan menulis ke klien pada ritme target.
9. Handler SSE menulis event `detections` dan `status` ke klien.

### 5.5 Model Konkurensi

- `FrameHub`: `tokio::sync::watch::Sender<Option<Arc<Frame>>>`. Konsumen memakai `Receiver::changed()`.
- `DetectionBus`: `tokio::sync::broadcast::Sender<Arc<FrameDetections>>` dengan kapasitas kecil (mis. 4).
  Konsumen yang tertinggal menerima `Lagged` dan melompat ke data terbaru.
- `Metrics`: `Arc<Metrics>` berisi `AtomicU64`/`AtomicU32`. Tidak ada lock.
- `StatusSnapshot`: dirakit di handler dengan membaca atomik dan memanggil fungsi ringkas milik
  template store dan outbox (keduanya harus menyediakan metode baca murah).
- Penghitung klien aktif: `AtomicUsize`, dipakai untuk keputusan lazy encode.

### 5.6 Sinkronisasi Video dan Overlay

Masalah: `<img>` MJPEG tidak mengekspos nomor frame ke JavaScript. Kotak dari SSE bisa
terlambat atau mendahului gambar yang terlihat.

| Opsi | Cara | Kelebihan | Kekurangan |
| ---- | ---- | --------- | ---------- |
| S1 | `<img>` MJPEG + SSE terpisah, gambar kotak terbaru | paling sederhana dan ringan | selisih beberapa puluh ms mungkin terlihat |
| S2 | Parse MJPEG via `fetch` + `ReadableStream`, cocokkan `frame_id` dari header part | sinkron tepat | JS lebih besar, CPU klien naik |
| S3 | Polling `/api/frame.jpg?id=N` + JSON detail | sinkron tepat | request per frame, overhead HTTP |

Keputusan awal: **S1**. Untuk kalibrasi, selisih kecil dapat diterima karena pop-up memakai
data event terakhir yang valid. Ada tugas opsional P7-12 untuk mengevaluasi S2 jika selisih
mengganggu. Mitigasi S1: kotak diberi masa berlaku (TTL) 300 ms; lewat itu kotak dihapus agar
tidak tertinggal di layar.

### 5.7 Diagram Urutan: Membuka Halaman

```text
Browser            axum                FrameHub        DetectionBus      Metrics
  │ GET /            │                    │                 │               │
  │─────────────────►│                    │                 │               │
  │◄── 200 HTML ─────│                    │                 │               │
  │ GET /api/status  │                    │                 │               │
  │─────────────────►│───── baca ────────────────────────────────────────►│
  │◄── 200 JSON ─────│                    │                 │               │
  │ GET /events (SSE)│                    │                 │               │
  │─────────────────►│── subscribe ───────────────────────►│               │
  │ GET /stream.mjpg │                    │                 │               │
  │─────────────────►│── subscribe ──────►│                 │               │
  │◄── part JPEG ────│◄── frame baru ─────│                 │               │
  │◄── event detections ───────────────────────────────────│               │
```

### 5.8 Diagram Urutan: Pause dan Snapshot

```text
Klien: tekan Pause
  1. JS menggambar frame <img> saat ini ke <canvas> beku.
  2. JS menyetel img.src = "" (menutup koneksi MJPEG) -> klien aktif turun.
  3. Overlay tetap menampilkan kotak terakhir (opsional) atau disembunyikan.
Klien: tekan Resume
  4. JS menyetel img.src = "/stream.mjpg?t=<token>&r=<random>".
Klien: tekan Snapshot
  5. JS memanggil GET /api/snapshot.jpg (frame terbaru, resolusi penuh).
  6. Browser mengunduh berkas dengan nama monitor-YYYYMMDD-HHMMSS.jpg.
```

---

## 6. Spesifikasi API

Semua endpoint berada di bawah prefiks yang sama dengan server utama. Respons error memakai
JSON `{ "error": "<kode>", "message": "<teks>" }` kecuali untuk stream.

### 6.1 Ringkasan Endpoint

| Metode | Path | Tipe respons | Fungsi |
| ------ | ---- | ------------ | ------ |
| GET | `/` | `text/html` | halaman monitor |
| GET | `/healthz` | `text/plain` | cek hidup (tanpa auth, tanpa data sensitif) |
| GET | `/api/status` | `application/json` | status mesin sekali ambil |
| GET | `/events` | `text/event-stream` | SSE: `status`, `detections` |
| GET | `/stream.mjpg` | `multipart/x-mixed-replace` | video langsung |
| GET | `/api/snapshot.jpg` | `image/jpeg` | foto instan |
| GET | `/api/version` | `application/json` | versi build dan fitur aktif |

### 6.2 `GET /api/status`

Contoh respons:

```json
{
  "ts_ms": 1792400000000,
  "uptime_s": 86412,
  "camera": {
    "fps": 14.8,
    "width": 640,
    "height": 480,
    "format": "MJPG",
    "frames_total": 1280033,
    "dropped_total": 12
  },
  "ai": {
    "fps": 9.6,
    "last_infer_ms": 71.4,
    "p95_infer_ms": 94.0,
    "faces_last_frame": 2,
    "model": "det+rec",
    "model_version": "unknown"
  },
  "templates": {
    "count": 148,
    "version": "2026-10-12T08:00:00Z#a91c",
    "loaded_at_ms": 1792390000000,
    "ram_bytes_est": 303104
  },
  "outbox": {
    "pending": 3,
    "failed": 0,
    "oldest_pending_age_s": 12,
    "last_sync_ok_ms": 1792399990000,
    "last_error": null,
    "server_reachable": true
  },
  "system": {
    "rss_mb": 212.4,
    "cpu_temp_c": 58.0,
    "load1": 1.92,
    "monitor_clients": 1
  }
}
```

Aturan field:

| Field | Tipe | Wajib | Catatan |
| ----- | ---- | ----- | ------- |
| `ts_ms` | integer | ya | epoch ms server |
| `camera.fps` | number | ya | rata-rata bergerak eksponensial |
| `ai.fps` | number | ya | frame yang selesai diinferensi per detik |
| `ai.p95_infer_ms` | number | tidak | boleh `null` bila belum cukup sampel |
| `templates.version` | string | ya | `unknown` bila tidak tersedia |
| `outbox.last_error` | string/null | ya | dipotong 200 karakter, tanpa rahasia |
| `system.cpu_temp_c` | number/null | tidak | dari `/sys/class/thermal` bila ada |
| `system.rss_mb` | number | tidak | dari `/proc/self/statm` |

### 6.3 `GET /events` (SSE)

Header respons: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`.
Keep-alive komentar tiap 15 detik agar koneksi tidak diputus perantara.

Event `status` (tiap 1 detik, isi sama dengan `/api/status` tetapi tanpa field statis):

```text
event: status
data: {"ts_ms":1792400000000,"camera":{"fps":14.8},"ai":{"fps":9.6,"last_infer_ms":71.4},"outbox":{"pending":3,"failed":0}}

```

Event `detections` (per frame hasil inferensi, dibatasi maksimal 10 per detik per klien):

```text
event: detections
data: {"frame_id":128003,"ts_ms":1792400000123,"w":640,"h":480,"faces":[{"id":1,"box":[212,98,96,120],"name":"Budi Santoso","score":0.83,"score2":0.41,"name2":"Andi Wijaya","matched":true,"threshold":0.60}]}

```

Aturan:

- `box` berformat `[x, y, w, h]` dalam piksel sumber (`w`, `h` frame disertakan).
- `id` adalah indeks wajah dalam frame, bukan identitas orang.
- `name` boleh `null` jika tidak cocok (`matched=false`).
- `score` dan `score2` adalah skor kemiripan 0..1 (atau skala asli matcher; tulis skalanya di P8-01).
- `name2` hanya disertakan jika monitor dikonfigurasi menampilkan kandidat kedua.
- Jika `monitor.show_names=false`, `name` dan `name2` diganti `"•••"` dan hanya skor yang dikirim.

### 6.4 `GET /stream.mjpg`

Format:

```text
HTTP/1.1 200 OK
Content-Type: multipart/x-mixed-replace; boundary=frame
Cache-Control: no-store, no-cache
Pragma: no-cache

--frame
Content-Type: image/jpeg
Content-Length: 18342
X-Frame-Id: 128003
X-Timestamp-Ms: 1792400000100

<bytes JPEG>
--frame
...
```

Parameter query opsional:

| Parameter | Nilai | Default | Fungsi |
| --------- | ----- | ------- | ------ |
| `fps` | 1..15 | 8 | batas laju kirim per klien |
| `q` | 30..90 | 60 | kualitas JPEG jika perlu encode ulang |
| `w` | 160..1280 | asli | lebar tujuan jika perlu resize |

Aturan beban:

- Server tidak mengirim frame yang sama dua kali.
- Jika klien lambat (buffer TCP penuh), frame dilewati, bukan ditumpuk.
- Batas klien stream bersamaan: default 2. Klien ke-3 mendapat `503` dengan header `Retry-After: 5`.

### 6.5 `GET /api/snapshot.jpg`

- Mengembalikan frame terbaru sebagai `image/jpeg`.
- Header: `Content-Disposition: attachment; filename="monitor-<UTC>.jpg"`, `Cache-Control: no-store`.
- Jika belum ada frame: `503` dengan JSON error `no_frame`.
- Tidak menyimpan ke disk.
- Batas laju: 2 permintaan per detik per alamat klien.

### 6.6 `GET /healthz`

- Mengembalikan `ok` jika proses hidup. Tidak membaca kamera, tidak membaca outbox.
- Dipakai oleh systemd watchdog atau skrip pemantau.

### 6.7 `GET /api/version`

```json
{ "name": "engine-server", "version": "0.0.0", "git": "abcdef0", "features": ["monitor-ui"], "build_ts": "2026-10-19T00:00:00Z" }
```

### 6.8 Kode Error

| Kode | HTTP | Arti |
| ---- | ---- | ---- |
| `unauthorized` | 401 | token hilang atau salah |
| `forbidden` | 403 | IP tidak diizinkan |
| `no_frame` | 503 | belum ada frame dari kamera |
| `too_many_clients` | 503 | batas klien stream tercapai |
| `rate_limited` | 429 | terlalu sering |
| `monitor_disabled` | 404 | fitur dimatikan lewat konfigurasi |
| `internal` | 500 | kesalahan tak terduga (detail hanya di log) |

---

## 7. Desain Sisi Rust

> Semua kode di bagian ini adalah **sketsa** untuk menyatukan pemahaman. Belum dikompilasi
> terhadap proyek nyata. Versi axum/tokio mengikuti `Cargo.toml` proyek.

### 7.1 Struktur Modul Usulan

```text
engine-server/
└── src/
    └── monitor/
        ├── mod.rs              # pub fn router(state) -> Router, pub struct MonitorState
        ├── config.rs           # MonitorConfig (dari config file/env)
        ├── metrics.rs          # Metrics, EmaFps, Histogram ringan
        ├── frame_hub.rs        # FrameHub, Frame
        ├── detection_bus.rs    # DetectionBus, FrameDetections, FaceDet
        ├── status.rs           # StatusSnapshot + builder
        ├── routes.rs           # handler: index, status, events, stream, snapshot, health, version
        ├── auth.rs             # middleware token, batas klien
        ├── sysinfo.rs          # rss, suhu, load (baca /proc, /sys)
        └── assets/
            └── index.html      # UI satu file
```

### 7.2 Cargo Feature

```toml
[features]
default = []
monitor-ui = []        # tidak menambah dependensi baru bila memungkinkan

# di kode:
# #[cfg(feature = "monitor-ui")] mod monitor;
```

Kebijakan dependensi tambahan (harus diputuskan di P0-07):

| Kebutuhan | Pilihan utama | Alternatif |
| --------- | ------------- | ---------- |
| JSON | `serde`, `serde_json` (kemungkinan sudah ada) | tulis manual untuk payload kecil |
| Bytes | `bytes` (kemungkinan sudah ada via axum/hyper) | — |
| Stream util | `tokio-stream`, `futures-util` | — |
| Encode JPEG bila perlu | `jpeg-encoder` (ringan, pure Rust) | `turbojpeg` (cepat, butuh libjpeg-turbo) |
| Resize | `fast_image_resize` | resize manual nearest-neighbor |

### 7.3 Metrics

```rust
use std::sync::atomic::{AtomicU32, AtomicU64, Ordering::Relaxed};

#[derive(Default)]
pub struct Metrics {
    pub cam_frames_total: AtomicU64,
    pub cam_dropped_total: AtomicU64,
    pub infer_frames_total: AtomicU64,
    pub last_infer_us: AtomicU64,
    pub faces_last_frame: AtomicU32,
    pub monitor_clients: AtomicU32,
    // FPS disimpan sebagai bit f32 agar atomik
    cam_fps_bits: AtomicU32,
    ai_fps_bits: AtomicU32,
}

impl Metrics {
    pub fn set_cam_fps(&self, v: f32) { self.cam_fps_bits.store(v.to_bits(), Relaxed) }
    pub fn cam_fps(&self) -> f32 { f32::from_bits(self.cam_fps_bits.load(Relaxed)) }
    pub fn set_ai_fps(&self, v: f32) { self.ai_fps_bits.store(v.to_bits(), Relaxed) }
    pub fn ai_fps(&self) -> f32 { f32::from_bits(self.ai_fps_bits.load(Relaxed)) }
}
```

EMA FPS (dipanggil dari thread capture/inferensi):

```rust
pub struct EmaFps { last: std::time::Instant, ema: f32, alpha: f32 }

impl EmaFps {
    pub fn new(alpha: f32) -> Self { Self { last: std::time::Instant::now(), ema: 0.0, alpha } }
    /// Panggil sekali per frame. Mengembalikan FPS terhaluskan.
    pub fn tick(&mut self) -> f32 {
        let now = std::time::Instant::now();
        let dt = now.duration_since(self.last).as_secs_f32().max(1e-6);
        self.last = now;
        let inst = 1.0 / dt;
        self.ema = if self.ema == 0.0 { inst } else { self.alpha * inst + (1.0 - self.alpha) * self.ema };
        self.ema
    }
}
```

Catatan: p95 latensi inferensi memakai ring buffer 128 sampel yang diurutkan hanya saat
`/api/status` diminta (bukan di jalur panas).

### 7.4 FrameHub

```rust
use bytes::Bytes;
use std::sync::Arc;
use tokio::sync::watch;

pub struct Frame {
    pub id: u64,
    pub ts_ms: u64,
    pub width: u32,
    pub height: u32,
    pub jpeg: Bytes,          // sudah JPEG; kosong bila belum di-encode (lazy)
    pub raw: Option<Bytes>,   // mentah (YUYV/NV12) bila kamera bukan MJPEG
}

pub struct FrameHub {
    tx: watch::Sender<Option<Arc<Frame>>>,
}

impl FrameHub {
    pub fn new() -> (Self, watch::Receiver<Option<Arc<Frame>>>) {
        let (tx, rx) = watch::channel(None);
        (Self { tx }, rx)
    }
    pub fn publish(&self, f: Arc<Frame>) { let _ = self.tx.send(Some(f)); }
    pub fn subscribe(&self) -> watch::Receiver<Option<Arc<Frame>>> { self.tx.subscribe() }
    pub fn latest(&self) -> Option<Arc<Frame>> { self.tx.borrow().clone() }
}
```

Catatan desain: bila kamera bukan MJPEG, encode JPEG dilakukan oleh satu tugas "encoder" yang
berlangganan `FrameHub`, hanya aktif saat `monitor_clients > 0`, dan menaruh hasilnya ke
`watch` kedua (`JpegHub`). Dengan begitu N klien tidak memicu N encode.

### 7.5 DetectionBus

```rust
use serde::Serialize;
use std::sync::Arc;
use tokio::sync::broadcast;

#[derive(Clone, Serialize)]
pub struct FaceDet {
    pub id: u32,
    pub r#box: [i32; 4],          // x, y, w, h
    pub name: Option<String>,
    pub score: f32,
    pub score2: Option<f32>,
    pub name2: Option<String>,
    pub matched: bool,
    pub threshold: f32,
}

#[derive(Clone, Serialize)]
pub struct FrameDetections {
    pub frame_id: u64,
    pub ts_ms: u64,
    pub w: u32,
    pub h: u32,
    pub faces: Vec<FaceDet>,
}

pub struct DetectionBus { tx: broadcast::Sender<Arc<FrameDetections>> }

impl DetectionBus {
    pub fn new() -> Self { let (tx, _) = broadcast::channel(4); Self { tx } }
    pub fn has_listeners(&self) -> bool { self.tx.receiver_count() > 0 }
    pub fn publish(&self, d: Arc<FrameDetections>) { let _ = self.tx.send(d); }
    pub fn subscribe(&self) -> broadcast::Receiver<Arc<FrameDetections>> { self.tx.subscribe() }
}
```

Aturan: pemanggil di jalur inferensi **wajib** mengecek `has_listeners()` sebelum membangun
`FrameDetections` (alokasi `Vec` dan `String` dihindari saat tidak ada klien).

### 7.6 Handler MJPEG (sketsa)

```rust
use axum::{body::Body, extract::State, http::{header, StatusCode}, response::Response};
use futures_util::stream::unfold;
use std::{convert::Infallible, time::Duration};

pub async fn stream_mjpg(State(st): State<MonitorState>) -> Result<Response, StatusCode> {
    let guard = st.clients.try_acquire_stream().ok_or(StatusCode::SERVICE_UNAVAILABLE)?;
    let mut rx = st.jpeg_hub.subscribe();
    let min_gap = Duration::from_millis(1000 / st.cfg.stream_fps_default as u64);

    let body = Body::from_stream(unfold((rx, guard, tokio::time::Instant::now()), move |(mut rx, guard, last)| async move {
        // tunggu frame baru
        if rx.changed().await.is_err() { return None; }
        // batasi laju
        let wait = min_gap.saturating_sub(last.elapsed());
        if !wait.is_zero() { tokio::time::sleep(wait).await; }
        let frame = rx.borrow().clone()?;
        let mut part = Vec::with_capacity(frame.jpeg.len() + 128);
        part.extend_from_slice(b"--frame\r\nContent-Type: image/jpeg\r\n");
        part.extend_from_slice(format!("Content-Length: {}\r\nX-Frame-Id: {}\r\n\r\n", frame.jpeg.len(), frame.id).as_bytes());
        part.extend_from_slice(&frame.jpeg);
        part.extend_from_slice(b"\r\n");
        Some((Ok::<_, Infallible>(bytes::Bytes::from(part)), (rx, guard, tokio::time::Instant::now())))
    }));

    Ok(Response::builder()
        .header(header::CONTENT_TYPE, "multipart/x-mixed-replace; boundary=frame")
        .header(header::CACHE_CONTROL, "no-store, no-cache")
        .body(body).unwrap())
}
```

Catatan: salinan `Vec` per part menambah satu salinan memori. Optimasi di P11: kirim header dan
payload sebagai dua `Bytes` terpisah (tanpa menyalin JPEG).

### 7.7 Handler SSE (sketsa)

```rust
use axum::response::sse::{Event, KeepAlive, Sse};
use futures_util::stream::Stream;
use tokio_stream::{wrappers::BroadcastStream, StreamExt};

pub async fn events(State(st): State<MonitorState>) -> Sse<impl Stream<Item = Result<Event, Infallible>>> {
    let _guard = st.clients.sse_guard();
    let det = BroadcastStream::new(st.det_bus.subscribe()).filter_map(|r| r.ok()).map(|d| {
        Ok(Event::default().event("detections").json_data(&*d).unwrap())
    });
    let status = tokio_stream::wrappers::IntervalStream::new(tokio::time::interval(Duration::from_secs(1)))
        .map({ let st = st.clone(); move |_| Ok(Event::default().event("status").json_data(st.status_light()).unwrap()) });
    Sse::new(det.merge(status)).keep_alive(KeepAlive::new().interval(Duration::from_secs(15)))
}
```

Catatan: `_guard` harus hidup selama stream; pada sketsa nyata, pindahkan guard ke dalam
stream agar baru dilepas saat koneksi putus.

### 7.8 Penyajian HTML Statis

```rust
const INDEX_HTML: &str = include_str!("assets/index.html");

pub async fn index() -> impl IntoResponse {
    (
        [(header::CONTENT_TYPE, "text/html; charset=utf-8"),
         (header::CACHE_CONTROL, "no-cache"),
         (header::CONTENT_SECURITY_POLICY, "default-src 'self'; img-src 'self' data: blob:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'")],
        INDEX_HTML,
    )
}
```

Opsi kompresi: simpan `index.html.gz` hasil build dan sajikan dengan `Content-Encoding: gzip`
(tugas P5-04). Jika klien tidak menyatakan `Accept-Encoding: gzip`, sajikan versi mentah.

### 7.9 Info Sistem Murah

- RSS: baca `/proc/self/statm` (kolom ke-2 × ukuran halaman).
- Suhu: `/sys/class/thermal/thermal_zone0/temp` (milidegrad), tangani file tidak ada.
- Load: `/proc/loadavg`.
- Pembacaan dilakukan di handler status dan di-cache 1 detik agar banyak klien tidak memicu banyak baca file.

### 7.10 Konfigurasi

```toml
[monitor]
enabled = true
bind = "0.0.0.0:5000"        # ikuti bind server yang ada
token = ""                   # kosong = tanpa token (hanya untuk LAN terpercaya)
show_names = true
show_second_candidate = true
max_stream_clients = 2
max_sse_clients = 4
stream_fps_default = 8
stream_fps_max = 15
jpeg_quality = 60
snapshot_rate_per_s = 2
allow_cidrs = ["192.168.0.0/16", "10.0.0.0/8"]
```

Prioritas: variabel lingkungan `MONITOR_*` mengalahkan berkas konfigurasi.

---

## 8. Desain Sisi Frontend

### 8.1 Batasan

- Satu berkas `index.html`, target ukuran mentah ≤ 40 KB, ≤ 12 KB setelah gzip.
- Tanpa framework, tanpa CDN, tanpa font eksternal (pakai font sistem).
- JavaScript modern (ES2019) tanpa transpile.
- Mode terang dan gelap mengikuti `prefers-color-scheme`.
- Tata letak responsif: satu kolom di HP, dua kolom di layar ≥ 900 px.

### 8.2 Tata Letak

```text
┌──────────────────────────────────────────────────────────────┐
│ ● Terhubung   engine-server v0.x   uptime 1h 23m   [Token]   │  <- header
├───────────────────────────────┬──────────────────────────────┤
│                               │  KAMERA   14.8 fps           │
│                               │  AI       9.6 fps  71 ms     │
│        VIDEO + OVERLAY        │  TEMPLATE 148  v...a91c      │
│                               │  OUTBOX   3 pending 0 gagal  │
│                               │  SISTEM   RSS 212MB  58°C    │
│  [Overlay] [Pause] [Snapshot] │  KLIEN    1                  │
├───────────────────────────────┴──────────────────────────────┤
│ Log kejadian singkat (maks 50 baris, hanya di memori klien)  │
└──────────────────────────────────────────────────────────────┘
```

### 8.3 Struktur DOM

```html
<main>
  <section id="video-wrap">
    <img id="cam" alt="Video kamera" />
    <canvas id="overlay"></canvas>
    <canvas id="frozen" hidden></canvas>
    <div id="popup" hidden></div>
    <div id="controls">
      <button id="btn-overlay" aria-pressed="true">Overlay</button>
      <button id="btn-pause" aria-pressed="false">Pause</button>
      <button id="btn-snap">Snapshot</button>
    </div>
  </section>
  <aside id="status">...</aside>
  <section id="log"></section>
</main>
```

### 8.4 Alur JavaScript

1. Baca token dari `location.hash` (`#t=...`) atau `localStorage`, simpan, lalu bersihkan hash.
2. Panggil `GET /api/status` untuk isi awal.
3. Buka `EventSource('/events?...')`; pasang pendengar `status` dan `detections`.
4. Atur `img.src = '/stream.mjpg?...'`.
5. Saat `detections` tiba: simpan ke `lastDet = {faces, w, h, at: performance.now()}`; jadwalkan satu `requestAnimationFrame` bila belum terjadwal.
6. `draw()`: bersihkan canvas, skalakan koordinat sumber ke ukuran tampilan, gambar kotak bila overlay aktif dan `now - at < 300 ms`.
7. Klik pada canvas: hitung koordinat sumber, cari wajah dengan `hitTest`, tampilkan pop-up.
8. Pause: salin frame ke `frozen`, kosongkan `img.src`, tandai tombol.
9. Resume: kembalikan `img.src`.
10. Snapshot: `fetch('/api/snapshot.jpg')` lalu unduh via `Blob` + `<a download>`.
11. Saat `visibilitychange` ke hidden: tutup stream dan SSE; saat visible: buka kembali.
12. Saat `EventSource` error: coba ulang dengan backoff 1, 2, 4, 8 detik (maks 15).

### 8.5 Hit-Testing

```js
function toSrc(ev, canvas, srcW, srcH) {
  const r = canvas.getBoundingClientRect();
  const sx = srcW / r.width, sy = srcH / r.height;
  return [(ev.clientX - r.left) * sx, (ev.clientY - r.top) * sy];
}
function hitTest(faces, x, y) {
  // pilih kotak terkecil yang memuat titik agar kotak bertumpuk memilih yang paling spesifik
  let best = null, bestArea = Infinity;
  for (const f of faces) {
    const [bx, by, bw, bh] = f.box;
    if (x >= bx && x <= bx + bw && y >= by && y <= by + bh && bw * bh < bestArea) { best = f; bestArea = bw * bh; }
  }
  return best;
}
```

Catatan: letterboxing. Jika `<img>` memakai `object-fit: contain`, hitung offset area gambar
sebenarnya; sebaiknya paksa `object-fit: fill` dengan rasio kontainer = rasio sumber supaya
transformasi sederhana (tugas P7-04).

### 8.6 Pop-Up Kalibrasi

Isi pop-up:

| Baris | Isi |
| ----- | --- |
| Judul | nama (atau "Tidak dikenal") |
| Skor 1 | nilai `score` dengan bar horizontal dan garis `threshold` |
| Skor 2 | nilai `score2` dan `name2` bila ada |
| Selisih | `score - score2` (margin) |
| Status | "Cocok" bila `score >= threshold` dan margin memadai; "Ambigu" bila margin kecil |
| Kotak | `x,y,w,h` dalam piksel sumber |
| Waktu | frame id dan umur data (ms) |

Pop-up membeku pada data saat diklik (tidak ikut berubah tiap frame) dengan tombol "Perbarui"
untuk mengambil data terbaru wajah terdekat. Ini memudahkan membaca angka.

### 8.7 Aksesibilitas

- Tombol bisa difokus dengan keyboard (`Tab`, `Enter`, `Space`); pintasan: `O` overlay, `P` pause, `S` snapshot, `Esc` tutup pop-up.
- `aria-pressed` pada tombol toggle.
- Kontras warna memenuhi minimal 4.5:1 untuk teks.
- Status koneksi memakai teks, bukan hanya warna.

### 8.8 Penanganan Kesalahan di UI

| Kondisi | Tampilan |
| ------- | -------- |
| 401 | dialog meminta token |
| 503 `too_many_clients` | pesan "Batas penonton tercapai, coba lagi" dengan hitung mundur |
| SSE putus | titik status merah, "Menyambung ulang..." |
| Tidak ada frame | placeholder "Menunggu kamera" |
| Snapshot gagal | toast 3 detik dengan pesan error |

---

## 9. Anggaran Sumber Daya (Target, Belum Terukur)

### 9.1 Anggaran Memori

| Komponen | Target (MB) | Catatan |
| -------- | ----------- | ------- |
| HTML statis (`.rodata`) | 0.04 | ukuran berkas |
| Frame JPEG terbaru (`Arc<Bytes>`) | 0.05–0.15 | 640x480 q60 sekitar 30–60 KB |
| Buffer per klien MJPEG | 0.2 | tergantung buffer TCP |
| Buffer per klien SSE | 0.05 | |
| Encoder JPEG (jika perlu) | 1–2 | working set |
| Ring buffer latensi | < 0.01 | |
| Total tambahan (1 klien) | ≤ 3 | target internal; batas keras 12 |

### 9.2 Anggaran CPU

| Skenario | Target |
| -------- | ------ |
| 0 klien | selisih < 0.5% core |
| 1 klien, MJPEG passthrough, 8 fps | < 3% core |
| 1 klien, encode JPEG dari YUYV, 8 fps | < 12% core |
| 2 klien (passthrough) | < 5% core |

### 9.3 Anggaran Jaringan

- 640x480, q60, ~40 KB/frame × 8 fps ≈ 320 KB/s ≈ 2.6 Mbit/s per klien.
- 2 klien ≈ 5.2 Mbit/s. Wi-Fi lemah mungkin tidak sanggup; sediakan parameter `fps` dan `w`.

### 9.4 Anggaran Latensi

- Glass-to-glass (kamera ke layar browser): target < 400 ms di LAN kabel.
- Pembaruan status: ≤ 1.2 detik dari kejadian.

### 9.5 Aturan Emas

> Jika ada konflik antara fitur monitor dan FPS inferensi, FPS inferensi menang.

Mekanisme pengaman: jika `ai.fps` turun di bawah ambang selama 10 detik berturut-turut dan
`monitor_clients > 0`, server menurunkan `fps` stream otomatis (shed load) dan mencatat peringatan.
Tugas terkait: P11-05.

---
## 10. Rencana Fase dan Daftar Tugas (TODO)

Total fase: 15 · Total tugas: 140 · Total estimasi: 288 jam kerja efektif (sekitar 48 hari kerja pada 6 jam efektif per hari untuk satu orang).

Estimasi adalah tebakan awal sebelum kode nyata dibaca. Perbarui setelah P0 selesai.

### 10.1 Ringkasan Fase

| Fase | Nama | Jumlah tugas | Estimasi (jam) | Tujuan singkat |
| ---- | ---- | ------------ | -------------- | -------------- |
| P0 | Persiapan dan Validasi Asumsi | 10 | 15 | Menyamakan dokumen dengan kode nyata dan menyiapkan alat ukur dasar sebelum menulis fitur. |
| P1 | Inti Metrik | 10 | 17 | Menyediakan penghitung ringan tanpa lock yang dapat dibaca fitur monitor tanpa mengganggu jalur inti. |
| P2 | Frame Hub dan Stream MJPEG | 12 | 30 | Menyajikan video langsung ke banyak klien tanpa menggandakan kerja dan tanpa menghambat jalur inti. |
| P3 | Bus Deteksi dan SSE | 10 | 21 | Mengalirkan hasil deteksi dan status ke klien secara hemat, hanya saat ada pendengar. |
| P4 | Status Mesin (Template dan Outbox) | 8 | 17 | Mengekspos status read-only: FPS, template di RAM, dan antrean outbox. |
| P5 | Penyajian Aset Statis | 8 | 12 | Menyajikan satu berkas HTML dengan biaya minimum dan header yang benar. |
| P6 | UI Shell dan Panel Status | 12 | 20 | Membangun kerangka halaman, koneksi data, dan tampilan status read-only. |
| P7 | Video Interaktif dan Overlay | 14 | 23 | Menampilkan video, menggambar kotak deteksi, dan menangani klik secara akurat. |
| P8 | Pop-Up Kalibrasi (Top-2 Scores) | 8 | 16 | Memberi alat bantu kalibrasi akurasi dengan menampilkan skor dan pesaing terdekat. |
| P9 | Pause dan Snapshot | 8 | 12 | Menjeda tampilan dan mengambil foto tanpa membebani server. |
| P10 | Keamanan dan Kontrol Akses | 8 | 20 | Melindungi data wajah dan nama serta membatasi penyalahgunaan sumber daya. |
| P11 | Optimasi dan Penegakan Anggaran | 8 | 21 | Mengukur dan memangkas biaya sampai memenuhi anggaran bagian 9. |
| P12 | Pengujian dan QA | 10 | 40 | Memastikan fungsi, kompatibilitas browser, dan ketahanan. |
| P13 | Deployment dan Operasi | 8 | 14 | Menjalankan dengan aman di perangkat nyata dan mudah dirawat. |
| P14 | Dokumentasi dan Serah Terima | 6 | 10 | Memastikan orang lain dapat mengoperasikan dan memelihara fitur ini. |

### 10.2 Urutan dan Ketergantungan Antar-Fase

```text
P0 Persiapan ─┬─► P1 Metrik ──┬─► P2 Frame/MJPEG ──┬─► P7 Video interaktif ─► P9 Pause/Snapshot
              │               │                    │
              │               ├─► P3 Bus/SSE ──────┼─► P8 Pop-up top-2
              │               │                    │
              │               └─► P4 Status ───────┴─► P6 UI shell ─┘
              └─► P5 Aset statis ──────────────────────► P6
P10 Keamanan (paralel sejak P2) ──► P12 QA ──► P13 Deploy ──► P14 Dokumentasi
P11 Optimasi (setelah P2–P9 fungsional) ──► P12
```

Jalur kritis (perkiraan): P0 → P1 → P2 → P3 → P7 → P8 → P11 → P12 → P13.
Pekerjaan frontend (P5–P9) dapat dimulai dengan data tiruan sambil menunggu backend (P2–P4).

### 10.3 Jadwal Usulan (satu pengembang, 6 jam efektif per hari)

| Minggu | Fase | Fokus | Keluaran yang dapat didemokan |
| ------ | ---- | ----- | ----------------------------- |
| 1 | P0, P1 | Validasi asumsi dan metrik | Metrik tercatat di log; baseline terukur |
| 2 | P2 | Frame hub dan MJPEG | Video terlihat di browser |
| 3 | P3, P4 | SSE dan status | curl melihat event dan status |
| 4 | P5, P6 | Aset statis dan panel status | Halaman status hidup |
| 5 | P7 | Overlay dan klik | Kotak sejajar, klik menandai wajah |
| 6 | P8, P9 | Pop-up top-2, pause, snapshot | Alat kalibrasi dapat dipakai |
| 7 | P10, P11 | Keamanan dan optimasi | Anggaran sumber daya terpenuhi |
| 8 | P12 | QA menyeluruh | Laporan uji |
| 9 | P13, P14 | Deploy dan dokumentasi | Rilis dan serah terima |

Cadangan waktu (buffer): tambahkan 20–30% untuk kejutan di perangkat ARM dan perilaku browser iOS.

### 10.4 Indeks Centang Cepat

Gunakan daftar ini untuk memantau kemajuan. Detail tiap tugas ada di 10.5.

**P0 — Persiapan dan Validasi Asumsi**

- [ ] `P0-01` Temukan dan kunci sumber kode engine-server _(Est. 1 j)_
- [ ] `P0-02` Audit Cargo.toml dan versi toolchain _(Est. 1 j)_
- [ ] `P0-03` Petakan jalur capture sampai inferensi _(Est. 2 j)_
- [ ] `P0-04` Cek format kamera dan mode yang didukung _(Est. 1 j)_
- [ ] `P0-05` Ukur baseline tanpa monitor _(Est. 3 j)_
- [ ] `P0-06` Siapkan perangkat uji dan jaringan _(Est. 1 j)_
- [ ] `P0-07` Putuskan kebijakan dependensi baru _(Est. 2 j)_
- [ ] `P0-08` Jawab pertanyaan terbuka prioritas _(Est. 1 j)_
- [ ] `P0-09` Buat branch dan konvensi commit _(Est. 1 j)_
- [ ] `P0-10` Siapkan skrip cross-build dan deploy cepat _(Est. 2 j)_

**P1 — Inti Metrik**

- [ ] `P1-01` Buat modul monitor dan feature flag _(Est. 1 j)_
- [ ] `P1-02` Implementasi struct Metrics (atomik) _(Est. 2 j)_
- [ ] `P1-03` Implementasi EmaFps _(Est. 1 j)_
- [ ] `P1-04` Pasang hook di thread capture _(Est. 2 j)_
- [ ] `P1-05` Pasang hook di inferensi _(Est. 2 j)_
- [ ] `P1-06` Ring buffer latensi dan p95 _(Est. 2 j)_
- [ ] `P1-07` Pembaca info sistem (RSS, suhu, load) _(Est. 2 j)_
- [ ] `P1-08` Penghitung klien monitor _(Est. 2 j)_
- [ ] `P1-09` Uji beban penulisan metrik _(Est. 2 j)_
- [ ] `P1-10` Dokumentasi metrik _(Est. 1 j)_

**P2 — Frame Hub dan Stream MJPEG**

- [ ] `P2-01` Implementasi FrameHub berbasis watch _(Est. 2 j)_
- [ ] `P2-02` Hubungkan capture ke FrameHub _(Est. 3 j)_
- [ ] `P2-03` Mode passthrough MJPEG _(Est. 2 j)_
- [ ] `P2-04` Validasi frame MJPG (SOI/EOI) _(Est. 1 j)_
- [ ] `P2-05` Encoder JPEG lazy untuk format non-MJPG _(Est. 4 j)_
- [ ] `P2-06` Handler /stream.mjpg _(Est. 4 j)_
- [ ] `P2-07` Pembatasan laju per klien _(Est. 2 j)_
- [ ] `P2-08` Tangani klien lambat (backpressure) _(Est. 3 j)_
- [ ] `P2-09` Hentikan stream bersih saat shutdown _(Est. 2 j)_
- [ ] `P2-10` Endpoint /api/snapshot.jpg _(Est. 2 j)_
- [ ] `P2-11` Batas laju snapshot _(Est. 2 j)_
- [ ] `P2-12` Uji stabilitas stream 2 jam _(Est. 3 j)_

**P3 — Bus Deteksi dan SSE**

- [ ] `P3-01` Definisikan struct FaceDet dan FrameDetections _(Est. 2 j)_
- [ ] `P3-02` Implementasi DetectionBus (broadcast) _(Est. 1 j)_
- [ ] `P3-03` Publish dari inferensi (dengan guard has_listeners) _(Est. 3 j)_
- [ ] `P3-04` Pemetaan nama dan privasi _(Est. 2 j)_
- [ ] `P3-05` Handler /events (SSE) _(Est. 4 j)_
- [ ] `P3-06` Batas laju detections per klien _(Est. 2 j)_
- [ ] `P3-07` Event status periodik _(Est. 2 j)_
- [ ] `P3-08` Header anti-buffering _(Est. 1 j)_
- [ ] `P3-09` Uji kehilangan koneksi dan sambung ulang _(Est. 2 j)_
- [ ] `P3-10` Uji dengan banyak wajah _(Est. 2 j)_

**P4 — Status Mesin (Template dan Outbox)**

- [ ] `P4-01` Builder StatusSnapshot _(Est. 3 j)_
- [ ] `P4-02` Handler /api/status _(Est. 2 j)_
- [ ] `P4-03` Ekspos jumlah dan versi template _(Est. 3 j)_
- [ ] `P4-04` Ekspos statistik outbox _(Est. 3 j)_
- [ ] `P4-05` Status 'server reachable' _(Est. 1 j)_
- [ ] `P4-06` Endpoint /healthz dan /api/version _(Est. 2 j)_
- [ ] `P4-07` Pengujian unit status _(Est. 2 j)_
- [ ] `P4-08` Dokumentasi kontrak status _(Est. 1 j)_

**P5 — Penyajian Aset Statis**

- [ ] `P5-01` Kerangka index.html minimal _(Est. 1 j)_
- [ ] `P5-02` Handler GET / dengan include_str! _(Est. 1 j)_
- [ ] `P5-03` ETag berbasis hash saat kompilasi _(Est. 2 j)_
- [ ] `P5-04` Kompresi gzip saat build _(Est. 3 j)_
- [ ] `P5-05` Skrip validasi ukuran aset _(Est. 1 j)_
- [ ] `P5-06` Minify ringan tanpa alat eksternal _(Est. 2 j)_
- [ ] `P5-07` Favicon dan manifest inline _(Est. 1 j)_
- [ ] `P5-08` Mode tema terang/gelap _(Est. 1 j)_

**P6 — UI Shell dan Panel Status**

- [ ] `P6-01` Tata letak responsif _(Est. 2 j)_
- [ ] `P6-02` Header status koneksi _(Est. 1 j)_
- [ ] `P6-03` Modul fetch status awal _(Est. 1 j)_
- [ ] `P6-04` Koneksi EventSource dengan backoff _(Est. 2 j)_
- [ ] `P6-05` Kartu Kamera dan AI _(Est. 2 j)_
- [ ] `P6-06` Kartu Template _(Est. 1 j)_
- [ ] `P6-07` Kartu Outbox _(Est. 2 j)_
- [ ] `P6-08` Kartu Sistem _(Est. 1 j)_
- [ ] `P6-09` Sparkline FPS ringan (opsional) _(Est. 3 j)_
- [ ] `P6-10` Log kejadian di klien _(Est. 2 j)_
- [ ] `P6-11` Penanganan token di UI _(Est. 2 j)_
- [ ] `P6-12` Pintasan keyboard dan fokus _(Est. 1 j)_

**P7 — Video Interaktif dan Overlay**

- [ ] `P7-01` Elemen img + canvas bertumpuk _(Est. 1 j)_
- [ ] `P7-02` Sinkronisasi ukuran canvas _(Est. 2 j)_
- [ ] `P7-03` Penerima event detections _(Est. 1 j)_
- [ ] `P7-04` Transformasi koordinat sumber→tampilan _(Est. 2 j)_
- [ ] `P7-05` Gambar kotak dan label _(Est. 3 j)_
- [ ] `P7-06` TTL kotak 300 ms _(Est. 1 j)_
- [ ] `P7-07` Toggle overlay _(Est. 1 j)_
- [ ] `P7-08` Hit-testing klik _(Est. 2 j)_
- [ ] `P7-09` Dukungan sentuh _(Est. 1 j)_
- [ ] `P7-10` Penanganan perubahan resolusi sumber _(Est. 1 j)_
- [ ] `P7-11` Penghentian rAF saat tab tersembunyi _(Est. 2 j)_
- [ ] `P7-12` Evaluasi S2 (sinkron per frame) — opsional _(Est. 4 j)_
- [ ] `P7-13` Penanganan error gambar _(Est. 1 j)_
- [ ] `P7-14` Placeholder 'menunggu kamera' _(Est. 1 j)_

**P8 — Pop-Up Kalibrasi (Top-2 Scores)**

- [ ] `P8-01` Matcher mengembalikan top-2 _(Est. 4 j)_
- [ ] `P8-02` Isi score2 dan name2 di FaceDet _(Est. 1 j)_
- [ ] `P8-03` Komponen pop-up _(Est. 3 j)_
- [ ] `P8-04` Logika status Cocok / Ambigu / Tidak dikenal _(Est. 2 j)_
- [ ] `P8-05` Bekukan data saat pop-up terbuka _(Est. 2 j)_
- [ ] `P8-06` Salin data ke papan klip _(Est. 1 j)_
- [ ] `P8-07` Mode privasi di pop-up _(Est. 1 j)_
- [ ] `P8-08` Dokumen panduan kalibrasi _(Est. 2 j)_

**P9 — Pause dan Snapshot**

- [ ] `P9-01` Bekukan frame ke canvas _(Est. 2 j)_
- [ ] `P9-02` Tutup stream saat pause _(Est. 1 j)_
- [ ] `P9-03` Resume dengan cache-buster _(Est. 1 j)_
- [ ] `P9-04` Overlay saat pause _(Est. 2 j)_
- [ ] `P9-05` Tombol Snapshot _(Est. 2 j)_
- [ ] `P9-06` Snapshot dengan kotak (opsional) _(Est. 2 j)_
- [ ] `P9-07` Umpan balik visual snapshot _(Est. 1 j)_
- [ ] `P9-08` Penanganan snapshot gagal _(Est. 1 j)_

**P10 — Keamanan dan Kontrol Akses**

- [ ] `P10-01` Konfigurasi bind dan CIDR _(Est. 3 j)_
- [ ] `P10-02` Autentikasi token _(Est. 4 j)_
- [ ] `P10-03` Cookie sesi sederhana _(Est. 3 j)_
- [ ] `P10-04` Batas laju login _(Est. 2 j)_
- [ ] `P10-05` Header keamanan _(Est. 1 j)_
- [ ] `P10-06` Pencegahan XSS _(Est. 2 j)_
- [ ] `P10-07` Masking data saat show_names=false _(Est. 2 j)_
- [ ] `P10-08` Ulasan model ancaman singkat _(Est. 3 j)_

**P11 — Optimasi dan Penegakan Anggaran**

- [ ] `P11-01` Ukur penambahan RSS _(Est. 3 j)_
- [ ] `P11-02` Ukur penambahan CPU _(Est. 3 j)_
- [ ] `P11-03` Hilangkan salinan di handler MJPEG _(Est. 3 j)_
- [ ] `P11-04` Pooling buffer encoder _(Est. 3 j)_
- [ ] `P11-05` Shed load otomatis _(Est. 4 j)_
- [ ] `P11-06` Prioritas thread _(Est. 2 j)_
- [ ] `P11-07` Profil dan ukuran biner _(Est. 1 j)_
- [ ] `P11-08` Uji termal 1 jam _(Est. 2 j)_

**P12 — Pengujian dan QA**

- [ ] `P12-01` Uji unit modul monitor _(Est. 6 j)_
- [ ] `P12-02` Uji integrasi HTTP _(Est. 5 j)_
- [ ] `P12-03` Sumber kamera sintetis _(Est. 4 j)_
- [ ] `P12-04` Matriks uji browser _(Est. 6 j)_
- [ ] `P12-05` Uji beban klien _(Est. 3 j)_
- [ ] `P12-06` Uji ketahanan 24 jam _(Est. 4 j)_
- [ ] `P12-07` Uji kegagalan _(Est. 4 j)_
- [ ] `P12-08` Uji regresi jalur inti _(Est. 3 j)_
- [ ] `P12-09` Uji aksesibilitas dasar _(Est. 2 j)_
- [ ] `P12-10` Uji keamanan dasar _(Est. 3 j)_

**P13 — Deployment dan Operasi**

- [ ] `P13-01` Unit systemd dan batas sumber daya _(Est. 2 j)_
- [ ] `P13-02` Konfigurasi produksi _(Est. 1 j)_
- [ ] `P13-03` Rotasi log _(Est. 1 j)_
- [ ] `P13-04` Prosedur rollback _(Est. 2 j)_
- [ ] `P13-05` Pemantauan eksternal sederhana _(Est. 2 j)_
- [ ] `P13-06` Pengaturan jaringan _(Est. 1 j)_
- [ ] `P13-07` Hardening OS minimal _(Est. 2 j)_
- [ ] `P13-08` Uji penerimaan di lokasi _(Est. 3 j)_

**P14 — Dokumentasi dan Serah Terima**

- [ ] `P14-01` README fitur monitor _(Est. 2 j)_
- [ ] `P14-02` Panduan pemecahan masalah _(Est. 2 j)_
- [ ] `P14-03` Referensi API final _(Est. 1 j)_
- [ ] `P14-04` Catatan keputusan arsitektur (ADR) _(Est. 2 j)_
- [ ] `P14-05` Laporan akhir performa _(Est. 2 j)_
- [ ] `P14-06` Retrospektif dan ide lanjutan _(Est. 1 j)_

### 10.5 Detail Tugas per Fase

#### Fase P0 — Persiapan dan Validasi Asumsi

**Tujuan fase:** Menyamakan dokumen dengan kode nyata dan menyiapkan alat ukur dasar sebelum menulis fitur.

**Kriteria keluar fase:**

- [ ] Semua asumsi A1–A10 berstatus terkonfirmasi atau dikoreksi.
- [ ] Baseline FPS, RSS, dan CPU tanpa monitor tercatat.
- [ ] Keputusan dependensi (P0-07) ditulis di dokumen ini.

##### P0-01 — Temukan dan kunci sumber kode engine-server

- **Status:** [ ]
- **Deskripsi:** Repositori absensi-cam saat ini hanya berisi .gitattributes. Tentukan lokasi kode sebenarnya (branch lain, repo lain, atau belum di-push) lalu kunci commit acuan.
- **Berkas/lokasi:** `README atau catatan di dokumen ini`
- **Bergantung pada:** -
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] URL repo/branch/commit acuan tertulis di bagian 0.1.
  - [ ] Struktur direktori level atas tercatat.
- **Verifikasi:** Clone bersih lalu `cargo metadata` berjalan tanpa error.
- **Catatan/risiko:** Jika kode belum ada, P0 ditunda dan fase ini jadi panduan pembuatan kerangka.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-02 — Audit Cargo.toml dan versi toolchain

- **Status:** [ ]
- **Deskripsi:** Catat versi axum, tokio, hyper, serde, onnxruntime binding, dan crate V4L2. Catat rustc, edition, dan target ARM.
- **Berkas/lokasi:** `Cargo.toml, rust-toolchain.toml`
- **Bergantung pada:** P0-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tabel versi dependensi ditempel di lampiran.
  - [ ] Target `aarch64-unknown-linux-gnu` terkonfirmasi.
- **Verifikasi:** `cargo tree --depth 1` disimpan sebagai artefak.
- **Catatan/risiko:** Versi axum 0.7 vs 0.8 mengubah sintaks route dan extractor.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-03 — Petakan jalur capture sampai inferensi

- **Status:** [ ]
- **Deskripsi:** Baca kode dan gambar alur: siapa membaca V4L2, di thread mana, format apa, bagaimana frame diserahkan ke inferensi.
- **Berkas/lokasi:** `catatan di docs/architecture-notes.md`
- **Bergantung pada:** P0-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Diagram alur aktual dibuat.
  - [ ] Titik publish frame (kandidat FrameHub) ditandai.
- **Verifikasi:** Review oleh pemilik kode.
- **Catatan/risiko:** Menjawab asumsi A2 dan A3.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-04 — Cek format kamera dan mode yang didukung

- **Status:** [ ]
- **Deskripsi:** Jalankan `v4l2-ctl --list-formats-ext` di perangkat. Catat MJPG/YUYV/NV12, resolusi, dan FPS tersedia.
- **Berkas/lokasi:** `docs/camera-formats.txt`
- **Bergantung pada:** -
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Daftar format tersimpan.
  - [ ] Format yang dipakai engine saat ini teridentifikasi.
- **Verifikasi:** Output perintah ditempel apa adanya.
- **Catatan/risiko:** Jika MJPG tersedia di resolusi yang dipakai, jalur passthrough jadi mungkin.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-05 — Ukur baseline tanpa monitor

- **Status:** [ ]
- **Deskripsi:** Dengan build saat ini, ukur selama 10 menit: RSS, CPU per thread, FPS kamera, FPS AI, suhu, throttling.
- **Berkas/lokasi:** `scripts/bench/baseline.sh, docs/baseline.md`
- **Bergantung pada:** P0-03
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Tabel baseline lengkap dengan median dan p95.
  - [ ] Kondisi uji (suhu ruang, catu daya, pendingin) tercatat.
- **Verifikasi:** Ulangi sekali; selisih antar-run < 10%.
- **Catatan/risiko:** Tanpa baseline, klaim 'tidak membebani' tidak bisa dibuktikan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-06 — Siapkan perangkat uji dan jaringan

- **Status:** [ ]
- **Deskripsi:** Tentukan 1 laptop, 1 HP Android, 1 HP iOS (bila ada), dan jaringan uji (kabel/Wi-Fi). Catat IP statis.
- **Berkas/lokasi:** `docs/test-devices.md`
- **Bergantung pada:** -
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Daftar perangkat uji dan versi browser tercatat.
- **Verifikasi:** Setiap perangkat berhasil ping Orange Pi.
- **Catatan/risiko:** Wi-Fi 2.4 GHz sering jadi bottleneck stream.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-07 — Putuskan kebijakan dependensi baru

- **Status:** [ ]
- **Deskripsi:** Evaluasi crate tambahan (tokio-stream, futures-util, jpeg-encoder, fast_image_resize). Catat ukuran biner dan waktu build tiap opsi.
- **Berkas/lokasi:** `bagian 7.2 dokumen ini`
- **Bergantung pada:** P0-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Setiap crate baru punya alasan tertulis.
  - [ ] Perubahan ukuran biner release tercatat.
- **Verifikasi:** `cargo bloat` atau perbandingan ukuran berkas.
- **Catatan/risiko:** Prinsip: pakai yang sudah ada dulu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-08 — Jawab pertanyaan terbuka prioritas

- **Status:** [ ]
- **Deskripsi:** Dapatkan jawaban pemilik untuk pertanyaan 3.2 nomor 3, 4, 5, 7, dan 10.
- **Berkas/lokasi:** `bagian 3.2 dokumen ini`
- **Bergantung pada:** -
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Jawaban tertulis di dokumen.
  - [ ] Dampak ke P10 ditandai.
- **Verifikasi:** Persetujuan tertulis pemilik.
- **Catatan/risiko:** Nama pegawai di layar adalah isu privasi.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-09 — Buat branch dan konvensi commit

- **Status:** [ ]
- **Deskripsi:** Buat branch `feat/monitor-ui`. Tetapkan format commit dan checklist PR.
- **Berkas/lokasi:** `.github/pull_request_template.md`
- **Bergantung pada:** P0-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Branch dibuat.
  - [ ] Template PR berisi checklist performa.
- **Verifikasi:** PR percobaan memakai template.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P0-10 — Siapkan skrip cross-build dan deploy cepat

- **Status:** [ ]
- **Deskripsi:** Skrip untuk build aarch64 dan salin ke perangkat via scp atau rsync, plus restart layanan.
- **Berkas/lokasi:** `scripts/deploy.sh`
- **Bergantung pada:** P0-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Satu perintah membangun dan menyalin biner.
  - [ ] Rollback ke biner sebelumnya terdokumentasi.
- **Verifikasi:** Deploy dua kali berturut-turut tanpa error.
- **Catatan/risiko:** Simpan biner lama sebagai engine-server.prev.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P1 — Inti Metrik

**Tujuan fase:** Menyediakan penghitung ringan tanpa lock yang dapat dibaca fitur monitor tanpa mengganggu jalur inti.

**Kriteria keluar fase:**

- [ ] Metrics terpasang di capture dan inferensi.
- [ ] Overhead pencatatan terukur < 1 µs per panggilan.
- [ ] Unit test lulus.

##### P1-01 — Buat modul monitor dan feature flag

- **Status:** [ ]
- **Deskripsi:** Tambahkan `mod monitor` di balik `cfg(feature = "monitor-ui")` dan fitur Cargo kosong.
- **Berkas/lokasi:** `src/monitor/mod.rs, Cargo.toml`
- **Bergantung pada:** P0-09
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Build dengan dan tanpa fitur sama-sama sukses.
  - [ ] Tanpa fitur, tidak ada simbol monitor di biner.
- **Verifikasi:** `cargo build` dan `cargo build --features monitor-ui`.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-02 — Implementasi struct Metrics (atomik)

- **Status:** [ ]
- **Deskripsi:** Counter dan gauge atomik sesuai bagian 7.3. FPS disimpan sebagai bit f32.
- **Berkas/lokasi:** `src/monitor/metrics.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Semua field tanpa Mutex.
  - [ ] Dokumentasi tiap field (satuan, siapa penulisnya).
- **Verifikasi:** Unit test baca-tulis multi-thread.
- **Catatan/risiko:** Gunakan Ordering::Relaxed; tidak perlu ordering ketat.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-03 — Implementasi EmaFps

- **Status:** [ ]
- **Deskripsi:** Penghitung FPS terhaluskan dengan alpha dapat dikonfigurasi (default 0.1).
- **Berkas/lokasi:** `src/monitor/metrics.rs`
- **Bergantung pada:** P1-02
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Nilai konvergen ke FPS sebenarnya pada sumber sintetis 10 fps.
  - [ ] Tidak NaN/Inf saat dt sangat kecil.
- **Verifikasi:** Unit test dengan jam palsu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-04 — Pasang hook di thread capture

- **Status:** [ ]
- **Deskripsi:** Setiap frame sukses: tambah `cam_frames_total`, update EMA, simpan lebar/tinggi/format. Frame gagal/dilewati: tambah `cam_dropped_total`.
- **Berkas/lokasi:** `modul capture (nama sesuai P0-03)`
- **Bergantung pada:** P1-02, P0-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] FPS kamera di metrik sesuai pengamatan manual ±5%.
  - [ ] Tidak ada alokasi baru di jalur ini.
- **Verifikasi:** Bandingkan dengan `v4l2-ctl --stream-mmap --stream-count`.
- **Catatan/risiko:** Jangan memanggil format! di jalur panas.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-05 — Pasang hook di inferensi

- **Status:** [ ]
- **Deskripsi:** Ukur durasi inferensi per frame dengan `Instant`; simpan `last_infer_us`, jumlah wajah, update EMA FPS AI.
- **Berkas/lokasi:** `modul inferensi`
- **Bergantung pada:** P1-02, P0-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] `ai.fps` dan `last_infer_ms` terisi benar.
  - [ ] Overhead pengukuran < 2 µs.
- **Verifikasi:** Benchmark mikro dengan dan tanpa hook.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-06 — Ring buffer latensi dan p95

- **Status:** [ ]
- **Deskripsi:** Simpan 128 sampel latensi terakhir; hitung p50/p95 hanya saat status diminta.
- **Berkas/lokasi:** `src/monitor/metrics.rs`
- **Bergantung pada:** P1-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] p95 benar pada data uji.
  - [ ] Penulisan sampel tanpa lock berat (gunakan indeks atomik).
- **Verifikasi:** Unit test dengan distribusi diketahui.
- **Catatan/risiko:** Boleh diterima sedikit race; ini metrik debug.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-07 — Pembaca info sistem (RSS, suhu, load)

- **Status:** [ ]
- **Deskripsi:** Baca /proc/self/statm, thermal_zone0, /proc/loadavg dengan cache 1 detik dan penanganan file tidak ada.
- **Berkas/lokasi:** `src/monitor/sysinfo.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Tidak panic jika file hilang.
  - [ ] Banyak pemanggil dalam 1 detik hanya memicu satu pembacaan.
- **Verifikasi:** Unit test dengan direktori palsu.
- **Catatan/risiko:** Di sistem non-Linux (dev di Windows), kembalikan None.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-08 — Penghitung klien monitor

- **Status:** [ ]
- **Deskripsi:** Struct ClientCounter dengan guard RAII untuk stream dan SSE, batas maksimum dari konfigurasi.
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Guard melepas hitungan saat drop, termasuk saat koneksi putus mendadak.
  - [ ] Batas keras ditegakkan.
- **Verifikasi:** Test: 3 percobaan acquire dengan batas 2.
- **Catatan/risiko:** Kebocoran hitungan = klien terkunci keluar; uji drop path.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-09 — Uji beban penulisan metrik

- **Status:** [ ]
- **Deskripsi:** Jalankan 4 thread menulis metrik 1 juta kali sambil 1 thread membaca; pastikan tidak ada penurunan berarti.
- **Berkas/lokasi:** `benches/metrics.rs`
- **Bergantung pada:** P1-02..P1-06
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Throughput tercatat.
  - [ ] Tidak ada kontensi terukur.
- **Verifikasi:** `cargo bench` (criterion bila sudah ada, atau manual).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P1-10 — Dokumentasi metrik

- **Status:** [ ]
- **Deskripsi:** Tabel nama metrik, satuan, penulis, pembaca, dan arti di docs/metrics.md.
- **Berkas/lokasi:** `docs/metrics.md`
- **Bergantung pada:** P1-01..P1-08
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Setiap metrik terdokumentasi.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P2 — Frame Hub dan Stream MJPEG

**Tujuan fase:** Menyajikan video langsung ke banyak klien tanpa menggandakan kerja dan tanpa menghambat jalur inti.

**Kriteria keluar fase:**

- [ ] Video tampil di browser dari /stream.mjpg.
- [ ] N klien tidak memicu N encode.
- [ ] Klien lambat tidak menahan produsen.

##### P2-01 — Implementasi FrameHub berbasis watch

- **Status:** [ ]
- **Deskripsi:** Sesuai 7.4. Sediakan publish, subscribe, latest.
- **Berkas/lokasi:** `src/monitor/frame_hub.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Konsumen mendapat frame terbaru, bukan antrean.
  - [ ] publish tidak pernah blocking.
- **Verifikasi:** Unit test dengan produsen cepat, konsumen lambat.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-02 — Hubungkan capture ke FrameHub

- **Status:** [ ]
- **Deskripsi:** Bungkus buffer V4L2 menjadi Arc<Frame>. Hindari salinan bila format MJPEG.
- **Berkas/lokasi:** `modul capture`
- **Bergantung pada:** P2-01, P0-03
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Satu salinan paling banyak per frame.
  - [ ] Buffer V4L2 dikembalikan ke driver tepat waktu.
- **Verifikasi:** Periksa dengan perf/valgrind-massif di dev; cek RSS stabil 30 menit.
- **Catatan/risiko:** Salah kelola buffer mmap memicu frame rusak.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-03 — Mode passthrough MJPEG

- **Status:** [ ]
- **Deskripsi:** Jika format kamera MJPG, kirim byte apa adanya sebagai JPEG ke klien.
- **Berkas/lokasi:** `src/monitor/frame_hub.rs`
- **Bergantung pada:** P2-02, P0-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada encode saat format MJPG.
  - [ ] JPEG valid terbuka di browser.
- **Verifikasi:** Simpan satu frame dan buka dengan `file` dan penampil gambar.
- **Catatan/risiko:** Beberapa kamera mengirim frame MJPG tanpa tabel Huffman; browser biasanya tetap menerima.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-04 — Validasi frame MJPG (SOI/EOI)

- **Status:** [ ]
- **Deskripsi:** Periksa awalan FFD8 dan akhiran FFD9; buang frame terpotong agar tidak merusak stream.
- **Berkas/lokasi:** `src/monitor/frame_hub.rs`
- **Bergantung pada:** P2-03
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Frame rusak tidak pernah dikirim.
  - [ ] Jumlah frame dibuang masuk metrik.
- **Verifikasi:** Unit test dengan data rusak sintetis.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-05 — Encoder JPEG lazy untuk format non-MJPG

- **Status:** [ ]
- **Deskripsi:** Jika kamera YUYV/NV12, jalankan satu tugas encoder hanya saat klien > 0; hasil ke JpegHub (watch kedua).
- **Berkas/lokasi:** `src/monitor/encoder.rs`
- **Bergantung pada:** P2-02, P0-07
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Encode berhenti dalam 2 detik setelah klien terakhir pergi.
  - [ ] Kualitas dan resize dari konfigurasi.
- **Verifikasi:** Ukur CPU dengan 0, 1, 2 klien.
- **Catatan/risiko:** Prioritaskan thread dengan nice rendah.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-06 — Handler /stream.mjpg

- **Status:** [ ]
- **Deskripsi:** Sesuai 7.6: multipart, batas fps, header X-Frame-Id, guard klien.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P2-01, P1-08
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Chrome, Firefox, Safari menampilkan video.
  - [ ] Klien ke-3 mendapat 503 dengan Retry-After.
- **Verifikasi:** Uji manual tiga browser; curl melihat boundary.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-07 — Pembatasan laju per klien

- **Status:** [ ]
- **Deskripsi:** Parameter fps (1..15) dan frame yang sama tidak dikirim dua kali.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P2-06
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] fps=2 menghasilkan sekitar 2 frame/detik.
  - [ ] Nilai di luar rentang dijepit.
- **Verifikasi:** Hitung part per detik via curl/awk.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-08 — Tangani klien lambat (backpressure)

- **Status:** [ ]
- **Deskripsi:** Jika tulis ke socket tertunda, lewati frame; jangan menumpuk. Putus klien yang tidak menerima > 10 detik.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P2-06
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Memori tidak tumbuh saat klien dijeda (SIGSTOP pada curl).
  - [ ] Klien macet diputus.
- **Verifikasi:** Uji dengan `curl --limit-rate 10k`.
- **Catatan/risiko:** Ini sumber kebocoran memori paling umum pada stream.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-09 — Hentikan stream bersih saat shutdown

- **Status:** [ ]
- **Deskripsi:** Sinyal shutdown menutup semua stream dan SSE tanpa menggantung.
- **Berkas/lokasi:** `src/monitor/mod.rs`
- **Bergantung pada:** P2-06
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] `systemctl stop` selesai < 3 detik dengan klien terhubung.
- **Verifikasi:** Uji dengan klien aktif.
- **Catatan/risiko:** Gunakan with_graceful_shutdown pada axum.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-10 — Endpoint /api/snapshot.jpg

- **Status:** [ ]
- **Deskripsi:** Kembalikan frame JPEG terbaru dengan header unduhan; 503 bila belum ada frame.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P2-03 atau P2-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Berkas valid terunduh dengan nama bertanda waktu.
  - [ ] Tidak ada penulisan disk.
- **Verifikasi:** curl -o dan verifikasi dengan `file`.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-11 — Batas laju snapshot

- **Status:** [ ]
- **Deskripsi:** Maksimum 2 permintaan per detik per IP; lebih dari itu 429.
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P2-10
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Burst 10 permintaan menghasilkan sebagian 429.
- **Verifikasi:** Uji skrip.
- **Catatan/risiko:** Implementasi token bucket sederhana di memori, dibatasi 64 IP.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P2-12 — Uji stabilitas stream 2 jam

- **Status:** [ ]
- **Deskripsi:** Satu klien terus-menerus 2 jam; pantau RSS, FPS, suhu.
- **Berkas/lokasi:** `docs/soak-test.md`
- **Bergantung pada:** P2-06..P2-10
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] RSS tidak naik > 5% setelah 15 menit pertama.
  - [ ] Tidak ada frame rusak.
- **Verifikasi:** Log metrik tiap menit ke CSV.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P3 — Bus Deteksi dan SSE

**Tujuan fase:** Mengalirkan hasil deteksi dan status ke klien secara hemat, hanya saat ada pendengar.

**Kriteria keluar fase:**

- [ ] Event detections tiba di browser < 150 ms setelah inferensi.
- [ ] Tanpa pendengar, tidak ada alokasi payload.

##### P3-01 — Definisikan struct FaceDet dan FrameDetections

- **Status:** [ ]
- **Deskripsi:** Sesuai 7.5 dan kontrak 6.3. Tambahkan serde dan dokumentasi satuan.
- **Berkas/lokasi:** `src/monitor/detection_bus.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] JSON sesuai contoh 6.3.
  - [ ] Skala skor terdokumentasi.
- **Verifikasi:** Test serialisasi snapshot (insta atau manual).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-02 — Implementasi DetectionBus (broadcast)

- **Status:** [ ]
- **Deskripsi:** Kapasitas 4, has_listeners, publish tanpa blocking.
- **Berkas/lokasi:** `src/monitor/detection_bus.rs`
- **Bergantung pada:** P3-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Pelanggan tertinggal menerima Lagged dan lanjut.
- **Verifikasi:** Unit test.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-03 — Publish dari inferensi (dengan guard has_listeners)

- **Status:** [ ]
- **Deskripsi:** Bangun FrameDetections hanya bila ada pendengar. Hitung koordinat dalam piksel sumber.
- **Berkas/lokasi:** `modul inferensi/matcher`
- **Bergantung pada:** P3-02, P0-03
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Tanpa klien: tidak ada alokasi tambahan (cek dengan counter alokasi di test).
  - [ ] Kotak sesuai gambar.
- **Verifikasi:** Bandingkan visual dengan gambar tes berkotak.
- **Catatan/risiko:** Perhatikan padding/letterbox dari preprocess model; kembalikan ke koordinat frame asli.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-04 — Pemetaan nama dan privasi

- **Status:** [ ]
- **Deskripsi:** Terapkan show_names dan show_second_candidate di titik serialisasi, bukan di UI.
- **Berkas/lokasi:** `src/monitor/detection_bus.rs`
- **Bergantung pada:** P3-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Dengan show_names=false, nama tidak pernah muncul di jaringan.
- **Verifikasi:** Uji dengan curl pada /events.
- **Catatan/risiko:** Keputusan privasi harus di server.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-05 — Handler /events (SSE)

- **Status:** [ ]
- **Deskripsi:** Sesuai 7.7. Gabungkan stream detections dan status; keep-alive 15 detik.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P3-02, P1-08
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] EventSource di browser menerima kedua jenis event.
  - [ ] Guard klien dilepas saat koneksi putus.
- **Verifikasi:** Uji dengan DevTools dan curl -N.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-06 — Batas laju detections per klien

- **Status:** [ ]
- **Deskripsi:** Maksimal 10 event/detik per klien; event berlebih dibuang (latest-wins).
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P3-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Dengan inferensi 30 fps sintetis, klien menerima ≤ 10/detik.
- **Verifikasi:** Hitung event per detik.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-07 — Event status periodik

- **Status:** [ ]
- **Deskripsi:** Kirim ringkasan status tiap 1 detik; hindari serialisasi bila tidak ada klien SSE.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P3-05, P4-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Interval stabil 1 s ±100 ms.
- **Verifikasi:** Rekam timestamp sisi klien.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-08 — Header anti-buffering

- **Status:** [ ]
- **Deskripsi:** Setel Cache-Control no-cache dan X-Accel-Buffering no; uji di balik proxy bila ada.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P3-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Event tiba tanpa penundaan lewat proxy uji.
- **Verifikasi:** Uji dengan nginx lokal (opsional).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-09 — Uji kehilangan koneksi dan sambung ulang

- **Status:** [ ]
- **Deskripsi:** Cabut kabel jaringan klien 10 detik lalu sambungkan; pastikan server tidak menyisakan koneksi hantu.
- **Berkas/lokasi:** `docs/test-reports/`
- **Bergantung pada:** P3-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Hitungan klien kembali ke 0 dalam 30 detik setelah klien hilang.
- **Verifikasi:** Pantau monitor_clients.
- **Catatan/risiko:** Aktifkan TCP keepalive pada listener bila perlu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P3-10 — Uji dengan banyak wajah

- **Status:** [ ]
- **Deskripsi:** Simulasikan 10 wajah per frame; ukur ukuran payload dan waktu serialisasi.
- **Berkas/lokasi:** `benches/detections.rs`
- **Bergantung pada:** P3-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Payload < 4 KB; serialisasi < 100 µs.
- **Verifikasi:** Benchmark.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P4 — Status Mesin (Template dan Outbox)

**Tujuan fase:** Mengekspos status read-only: FPS, template di RAM, dan antrean outbox.

**Kriteria keluar fase:**

- [ ] /api/status sesuai kontrak 6.2.
- [ ] Semua nilai dapat dibaca tanpa mengunci jalur inti.

##### P4-01 — Builder StatusSnapshot

- **Status:** [ ]
- **Deskripsi:** Rakit JSON dari Metrics, sysinfo, template store, dan outbox. Versi ringan untuk SSE.
- **Berkas/lokasi:** `src/monitor/status.rs`
- **Bergantung pada:** P1-02, P1-07
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Struktur sama dengan contoh 6.2.
  - [ ] Tidak ada unwrap yang bisa panic.
- **Verifikasi:** Test snapshot JSON.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-02 — Handler /api/status

- **Status:** [ ]
- **Deskripsi:** Kembalikan StatusSnapshot penuh; cache 500 ms agar banyak klien murah.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P4-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Dua permintaan beruntun dalam 500 ms memakai cache.
- **Verifikasi:** Hitung pembacaan sysinfo.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-03 — Ekspos jumlah dan versi template

- **Status:** [ ]
- **Deskripsi:** Tambahkan metode ringan `stats()` pada template store: count, version/hash, loaded_at, perkiraan byte.
- **Berkas/lokasi:** `modul template store`
- **Bergantung pada:** P0-03
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Metode tidak mengunci lebih dari beberapa mikrodetik.
  - [ ] Versi berubah saat template di-reload.
- **Verifikasi:** Test: reload mengubah versi.
- **Catatan/risiko:** Jika belum ada versi, gunakan hash (mis. FNV/xxhash) dari daftar id + timestamp.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-04 — Ekspos statistik outbox

- **Status:** [ ]
- **Deskripsi:** Metode `stats()` pada outbox: pending, failed, umur tertua, last_ok, last_error, server_reachable.
- **Berkas/lokasi:** `modul outbox`
- **Bergantung pada:** P0-03
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Query ringan (indeks atau counter), bukan scan penuh.
  - [ ] last_error dipotong dan tanpa rahasia.
- **Verifikasi:** Test dengan outbox berisi 10 ribu baris.
- **Catatan/risiko:** Jika outbox SQLite, simpan counter di memori dan sinkronkan saat start.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-05 — Status 'server reachable'

- **Status:** [ ]
- **Deskripsi:** Tentukan dari hasil sinkronisasi terakhir (bukan ping baru) agar tidak menambah lalu lintas.
- **Berkas/lokasi:** `modul outbox`
- **Bergantung pada:** P4-04
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Nilai berubah dalam 1 siklus sinkron setelah server mati/hidup.
- **Verifikasi:** Matikan server pusat dan amati.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-06 — Endpoint /healthz dan /api/version

- **Status:** [ ]
- **Deskripsi:** Sederhana, tanpa data sensitif; version memuat git hash saat build.
- **Berkas/lokasi:** `src/monitor/routes.rs, build.rs`
- **Bergantung pada:** P1-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] healthz < 1 ms.
  - [ ] git hash muncul di version.
- **Verifikasi:** curl.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-07 — Pengujian unit status

- **Status:** [ ]
- **Deskripsi:** Uji builder dengan nilai null, nol, dan ekstrem (NaN FPS).
- **Berkas/lokasi:** `tests/status.rs`
- **Bergantung pada:** P4-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] NaN dan Inf diubah menjadi null atau 0.
- **Verifikasi:** cargo test.
- **Catatan/risiko:** serde_json menolak NaN; tangani sebelum serialisasi.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P4-08 — Dokumentasi kontrak status

- **Status:** [ ]
- **Deskripsi:** Salin kontrak final 6.2 ke docs/api.md dengan contoh respons nyata dari perangkat.
- **Berkas/lokasi:** `docs/api.md`
- **Bergantung pada:** P4-02
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Contoh nyata menggantikan contoh sintetis.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P5 — Penyajian Aset Statis

**Tujuan fase:** Menyajikan satu berkas HTML dengan biaya minimum dan header yang benar.

**Kriteria keluar fase:**

- [ ] GET / menyajikan UI.
- [ ] Ukuran terkompresi ≤ 12 KB.

##### P5-01 — Kerangka index.html minimal

- **Status:** [ ]
- **Deskripsi:** Dokumen HTML dengan CSS dan JS inline kosong yang memuat struktur DOM 8.3.
- **Berkas/lokasi:** `src/monitor/assets/index.html`
- **Bergantung pada:** P1-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Halaman terbuka tanpa error konsol.
- **Verifikasi:** Buka di browser.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-02 — Handler GET / dengan include_str!

- **Status:** [ ]
- **Deskripsi:** Sesuai 7.8 termasuk CSP.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P5-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Header CSP terpasang.
  - [ ] Content-Type benar.
- **Verifikasi:** curl -I.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-03 — ETag berbasis hash saat kompilasi

- **Status:** [ ]
- **Deskripsi:** Hitung hash di build.rs dan kirim ETag; dukung If-None-Match → 304.
- **Berkas/lokasi:** `build.rs, src/monitor/routes.rs`
- **Bergantung pada:** P5-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Muat ulang menghasilkan 304.
- **Verifikasi:** DevTools Network.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-04 — Kompresi gzip saat build

- **Status:** [ ]
- **Deskripsi:** build.rs menghasilkan index.html.gz; server memilih berdasarkan Accept-Encoding.
- **Berkas/lokasi:** `build.rs`
- **Bergantung pada:** P5-02
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Ukuran terkirim ≤ 12 KB.
  - [ ] Klien tanpa gzip tetap bekerja.
- **Verifikasi:** curl --compressed dan tanpa.
- **Catatan/risiko:** Jika build.rs memperlambat build lintas-kompilasi, pindahkan ke skrip.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-05 — Skrip validasi ukuran aset

- **Status:** [ ]
- **Deskripsi:** CI/skrip gagal bila index.html > 40 KB atau .gz > 12 KB.
- **Berkas/lokasi:** `scripts/check-assets.sh`
- **Bergantung pada:** P5-04
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Skrip gagal pada berkas sengaja diperbesar.
- **Verifikasi:** Uji negatif.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-06 — Minify ringan tanpa alat eksternal

- **Status:** [ ]
- **Deskripsi:** Hapus komentar dan spasi berlebih lewat skrip sederhana (opsional), simpan sumber terbaca terpisah.
- **Berkas/lokasi:** `scripts/minify.py`
- **Bergantung pada:** P5-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Fungsi UI identik sebelum dan sesudah.
- **Verifikasi:** Tes manual penuh.
- **Catatan/risiko:** Boleh dilewati bila gzip sudah cukup.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-07 — Favicon dan manifest inline

- **Status:** [ ]
- **Deskripsi:** Favicon data: URI agar tidak ada permintaan tambahan; tanpa manifest PWA.
- **Berkas/lokasi:** `src/monitor/assets/index.html`
- **Bergantung pada:** P5-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada 404 favicon di log.
- **Verifikasi:** DevTools.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P5-08 — Mode tema terang/gelap

- **Status:** [ ]
- **Deskripsi:** Variabel CSS dan prefers-color-scheme.
- **Berkas/lokasi:** `src/monitor/assets/index.html`
- **Bergantung pada:** P5-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Kedua tema terbaca jelas.
- **Verifikasi:** Uji manual kedua mode.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P6 — UI Shell dan Panel Status

**Tujuan fase:** Membangun kerangka halaman, koneksi data, dan tampilan status read-only.

**Kriteria keluar fase:**

- [ ] Panel status hidup dan memperbarui tiap detik.
- [ ] Indikator koneksi akurat.

##### P6-01 — Tata letak responsif

- **Status:** [ ]
- **Deskripsi:** Grid dua kolom ≥ 900 px, satu kolom di bawahnya. Video menjaga rasio sumber.
- **Berkas/lokasi:** `index.html (CSS)`
- **Bergantung pada:** P5-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada scroll horizontal di 360 px.
  - [ ] Video tidak terdistorsi.
- **Verifikasi:** Uji DevTools device mode.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-02 — Header status koneksi

- **Status:** [ ]
- **Deskripsi:** Titik hijau/merah, teks koneksi, uptime, versi.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Teks status berubah saat SSE putus.
- **Verifikasi:** Matikan server saat halaman terbuka.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-03 — Modul fetch status awal

- **Status:** [ ]
- **Deskripsi:** Panggil /api/status saat load; tampilkan skeleton sampai data tiba.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P4-02
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada kedipan nilai 'undefined'.
- **Verifikasi:** Throttle jaringan Slow 3G.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-04 — Koneksi EventSource dengan backoff

- **Status:** [ ]
- **Deskripsi:** Sambung ulang 1,2,4,8,15 detik; reset saat sukses.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P3-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Setelah server hidup kembali, halaman pulih otomatis < 20 detik.
- **Verifikasi:** Restart layanan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-05 — Kartu Kamera dan AI

- **Status:** [ ]
- **Deskripsi:** Tampilkan FPS kamera, FPS AI, latensi inferensi, p95, jumlah wajah.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Angka berformat 1 desimal.
  - [ ] Warna peringatan jika FPS AI < ambang.
- **Verifikasi:** Simulasikan nilai rendah lewat mock.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-06 — Kartu Template

- **Status:** [ ]
- **Deskripsi:** Jumlah, versi (dipendekkan dengan tooltip penuh), waktu muat terakhir.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-03
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Versi panjang tidak merusak tata letak.
- **Verifikasi:** Uji string panjang.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-07 — Kartu Outbox

- **Status:** [ ]
- **Deskripsi:** Pending, gagal, umur tertua, sinkron terakhir, status server, error terakhir.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Gagal > 0 ditandai merah.
  - [ ] Umur tertua > 5 menit ditandai kuning.
- **Verifikasi:** Uji dengan data mock.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-08 — Kartu Sistem

- **Status:** [ ]
- **Deskripsi:** RSS, suhu, load, jumlah klien. Tampilkan '—' jika null.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-03
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada 'null' atau 'NaN' tampil.
- **Verifikasi:** Mock null.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-09 — Sparkline FPS ringan (opsional)

- **Status:** [ ]
- **Deskripsi:** Grafik garis kecil 60 detik terakhir dengan canvas 2D, tanpa pustaka.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P6-05
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Tidak menambah > 1 KB gzip.
  - [ ] Menggambar ≤ 1 ms per pembaruan.
- **Verifikasi:** Profiler browser.
- **Catatan/risiko:** Boleh dibatalkan jika melewati batas ukuran.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-10 — Log kejadian di klien

- **Status:** [ ]
- **Deskripsi:** Maksimum 50 baris di memori: sambung/putus, error, snapshot. Tidak dikirim ke server.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Baris lama terbuang otomatis.
- **Verifikasi:** Hasilkan 200 kejadian.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-11 — Penanganan token di UI

- **Status:** [ ]
- **Deskripsi:** Dialog token saat 401; simpan di localStorage hanya bila pengguna mencentang 'ingat'.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P10-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Token tidak muncul di URL setelah dimuat (hash dibersihkan).
- **Verifikasi:** Periksa riwayat browser.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P6-12 — Pintasan keyboard dan fokus

- **Status:** [ ]
- **Deskripsi:** O, P, S, Esc; indikator fokus terlihat.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Semua kontrol dapat dioperasikan tanpa mouse.
- **Verifikasi:** Uji tab order.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P7 — Video Interaktif dan Overlay

**Tujuan fase:** Menampilkan video, menggambar kotak deteksi, dan menangani klik secara akurat.

**Kriteria keluar fase:**

- [ ] Kotak sejajar dengan wajah pada gerakan normal.
- [ ] Toggle overlay seketika.
- [ ] Klik memilih wajah yang benar.

##### P7-01 — Elemen img + canvas bertumpuk

- **Status:** [ ]
- **Deskripsi:** Posisi absolut, ukuran sama, pointer-events hanya pada canvas.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P6-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Canvas selalu menutupi gambar persis.
- **Verifikasi:** Ubah ukuran jendela.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-02 — Sinkronisasi ukuran canvas

- **Status:** [ ]
- **Deskripsi:** Sesuaikan resolusi canvas dengan ukuran tampil × devicePixelRatio (dibatasi 2).
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Garis kotak tajam di layar HiDPI.
  - [ ] Memori canvas tidak melebihi 2 MP.
- **Verifikasi:** Uji di HP.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-03 — Penerima event detections

- **Status:** [ ]
- **Deskripsi:** Simpan data terakhir dan waktunya; jadwalkan satu rAF.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P3-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada penggambaran ganda per event.
- **Verifikasi:** Hitung panggilan draw.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-04 — Transformasi koordinat sumber→tampilan

- **Status:** [ ]
- **Deskripsi:** Gunakan object-fit: fill dan rasio kontainer tetap; sediakan fungsi toSrc dan toView.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Kotak tepat di wajah pada 3 ukuran jendela.
- **Verifikasi:** Uji visual dengan gambar uji bergaris.
- **Catatan/risiko:** Hindari letterbox agar matematika sederhana.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-05 — Gambar kotak dan label

- **Status:** [ ]
- **Deskripsi:** Hijau untuk cocok, kuning untuk ambigu, merah untuk tidak dikenal; label nama + skor.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-03, P7-04
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Label tidak keluar dari canvas.
  - [ ] Warna tidak hanya satu-satunya pembeda (tambahkan ikon/teks).
- **Verifikasi:** Uji tepi kiri/kanan/atas.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-06 — TTL kotak 300 ms

- **Status:** [ ]
- **Deskripsi:** Hapus kotak bila data lebih tua dari TTL agar tidak tertinggal saat inferensi berhenti.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Kotak hilang ≤ 400 ms setelah wajah pergi.
- **Verifikasi:** Tutup kamera dengan tangan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-07 — Toggle overlay

- **Status:** [ ]
- **Deskripsi:** Tombol dan tombol O; status disimpan di sessionStorage.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P7-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Saat mati, canvas dibersihkan dan rAF berhenti.
- **Verifikasi:** Profiler: CPU klien turun.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-08 — Hit-testing klik

- **Status:** [ ]
- **Deskripsi:** Sesuai 8.5; pilih kotak terkecil. Toleransi 6 px di sekitar kotak untuk HP.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Klik di dalam dua kotak bertumpuk memilih yang lebih kecil.
- **Verifikasi:** Uji dengan data sintetis.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-09 — Dukungan sentuh

- **Status:** [ ]
- **Deskripsi:** Gunakan pointer events; hindari klik ganda dan zoom tak sengaja (touch-action).
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P7-08
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tap membuka pop-up di iOS dan Android.
- **Verifikasi:** Uji perangkat nyata.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-10 — Penanganan perubahan resolusi sumber

- **Status:** [ ]
- **Deskripsi:** Jika w/h pada event berubah, hitung ulang rasio dan atur ulang kontainer.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-04
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada kotak salah tempat setelah ganti resolusi.
- **Verifikasi:** Ubah resolusi kamera lalu restart.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-11 — Penghentian rAF saat tab tersembunyi

- **Status:** [ ]
- **Deskripsi:** Gunakan visibilitychange; tutup stream dan SSE saat tersembunyi.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Klien aktif turun ke 0 saat tab di latar belakang > 5 detik.
- **Verifikasi:** Pantau monitor_clients.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-12 — Evaluasi S2 (sinkron per frame) — opsional

- **Status:** [ ]
- **Deskripsi:** Prototipe parser MJPEG via fetch untuk mencocokkan X-Frame-Id dengan event. Putuskan layak atau tidak.
- **Berkas/lokasi:** `docs/s2-evaluation.md`
- **Bergantung pada:** P7-05
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Laporan: selisih S1 vs S2, biaya CPU klien, ukuran JS.
- **Verifikasi:** Rekam video layar dan bandingkan.
- **Catatan/risiko:** Hanya dikerjakan bila selisih S1 mengganggu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-13 — Penanganan error gambar

- **Status:** [ ]
- **Deskripsi:** Jika <img> error, tampilkan placeholder dan coba lagi dengan backoff.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P6-04
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Setelah server pulih, video kembali tanpa muat ulang.
- **Verifikasi:** Restart layanan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P7-14 — Placeholder 'menunggu kamera'

- **Status:** [ ]
- **Deskripsi:** Tampil saat belum ada frame atau 503 no_frame.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P7-13
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Pesan jelas dan tidak berkedip.
- **Verifikasi:** Cabut kamera.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P8 — Pop-Up Kalibrasi (Top-2 Scores)

**Tujuan fase:** Memberi alat bantu kalibrasi akurasi dengan menampilkan skor dan pesaing terdekat.

**Kriteria keluar fase:**

- [ ] Pop-up memuat nama, skor, skor ke-2, margin, ambang.
- [ ] Data top-2 benar dibanding keluaran matcher.

##### P8-01 — Matcher mengembalikan top-2

- **Status:** [ ]
- **Deskripsi:** Ubah atau bungkus matcher agar menyimpan dua kandidat teratas tanpa mengubah hasil keputusan. Dokumentasikan skala skor.
- **Berkas/lokasi:** `modul matcher`
- **Bergantung pada:** P0-03
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Keputusan presensi identik sebelum/sesudah (uji regresi).
  - [ ] Biaya tambahan < 5% waktu matcher.
- **Verifikasi:** Uji dengan set wajah tetap; bandingkan keluaran lama vs baru.
- **Catatan/risiko:** Ini satu-satunya perubahan di jalur inti; harus dilindungi uji regresi.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-02 — Isi score2 dan name2 di FaceDet

- **Status:** [ ]
- **Deskripsi:** Petakan dari top-2 matcher; null bila hanya ada satu template.
- **Berkas/lokasi:** `src/monitor/detection_bus.rs`
- **Bergantung pada:** P8-01, P3-03
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Nilai muncul di SSE.
- **Verifikasi:** curl -N.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-03 — Komponen pop-up

- **Status:** [ ]
- **Deskripsi:** Elemen mengambang dengan panah ke kotak, bar skor, garis ambang, teks margin.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P7-08
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Tidak keluar dari viewport.
  - [ ] Esc dan klik di luar menutup.
- **Verifikasi:** Uji tepi layar.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-04 — Logika status Cocok / Ambigu / Tidak dikenal

- **Status:** [ ]
- **Deskripsi:** Cocok: score ≥ ambang dan margin ≥ m_min. Ambigu: score ≥ ambang tetapi margin < m_min. Lainnya: tidak dikenal. m_min dari konfigurasi.
- **Berkas/lokasi:** `index.html (JS), config`
- **Bergantung pada:** P8-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Label konsisten dengan keputusan server (bukan hitung ulang berbeda).
- **Verifikasi:** Bandingkan dengan log keputusan.
- **Catatan/risiko:** Sebaiknya server mengirim flag `ambiguous` agar satu sumber kebenaran.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-05 — Bekukan data saat pop-up terbuka

- **Status:** [ ]
- **Deskripsi:** Salin data wajah saat klik; tombol 'Perbarui' mengambil data terbaru terdekat.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P8-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Angka tidak berubah sendiri saat dibaca.
- **Verifikasi:** Uji manual.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-06 — Salin data ke papan klip

- **Status:** [ ]
- **Deskripsi:** Tombol 'Salin' menghasilkan teks ringkas (frame, kotak, skor) untuk laporan kalibrasi.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P8-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Teks terformat konsisten.
- **Verifikasi:** Tempel ke editor.
- **Catatan/risiko:** Nama dapat dimasking bila show_names=false.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-07 — Mode privasi di pop-up

- **Status:** [ ]
- **Deskripsi:** Hormati show_names dan show_second_candidate dari server; UI tidak menebak.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P3-04
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Dengan show_names=false pop-up hanya menampilkan skor.
- **Verifikasi:** Uji konfigurasi.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P8-08 — Dokumen panduan kalibrasi

- **Status:** [ ]
- **Deskripsi:** Langkah memakai pop-up untuk menilai ambang: kumpulkan skor genuine vs impostor, lihat margin.
- **Berkas/lokasi:** `docs/calibration-guide.md`
- **Bergantung pada:** P8-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Panduan memuat contoh tabel pencatatan.
- **Verifikasi:** Review oleh operator.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P9 — Pause dan Snapshot

**Tujuan fase:** Menjeda tampilan dan mengambil foto tanpa membebani server.

**Kriteria keluar fase:**

- [ ] Pause menutup stream.
- [ ] Snapshot mengunduh JPEG valid.

##### P9-01 — Bekukan frame ke canvas

- **Status:** [ ]
- **Deskripsi:** Saat Pause, drawImage dari <img> ke canvas beku sebelum img.src dikosongkan.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P7-01
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Gambar beku tidak berkedip hitam.
- **Verifikasi:** Uji di tiga browser.
- **Catatan/risiko:** Safari kadang membatalkan drawImage pada MJPEG; siapkan fallback ambil snapshot server.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-02 — Tutup stream saat pause

- **Status:** [ ]
- **Deskripsi:** Setel img.src ke string kosong dan konfirmasi hitungan klien turun.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P9-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] monitor_clients turun 1 dalam 2 detik.
- **Verifikasi:** Pantau status.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-03 — Resume dengan cache-buster

- **Status:** [ ]
- **Deskripsi:** Tambahkan parameter acak agar browser tidak memakai koneksi lama.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P9-02
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Video lanjut < 2 detik.
- **Verifikasi:** Ukur waktu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-04 — Overlay saat pause

- **Status:** [ ]
- **Deskripsi:** Opsi: pertahankan kotak terakhir dan tetap bisa diklik untuk analisis.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P9-01, P8-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Klik pada kotak beku membuka pop-up dengan data beku.
- **Verifikasi:** Uji manual.
- **Catatan/risiko:** Berguna untuk kalibrasi: bekukan lalu telaah.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-05 — Tombol Snapshot

- **Status:** [ ]
- **Deskripsi:** fetch /api/snapshot.jpg, Blob, unduh dengan nama monitor-YYYYMMDD-HHMMSS.jpg.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P2-10
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Berkas terunduh dan terbuka.
- **Verifikasi:** Uji di desktop dan HP.
- **Catatan/risiko:** Di iOS, unduhan dapat membuka tab baru; terima perilaku itu.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-06 — Snapshot dengan kotak (opsional)

- **Status:** [ ]
- **Deskripsi:** Pilihan menggabungkan overlay ke gambar via canvas; default tanpa overlay.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P9-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Kotak sejajar pada hasil gabungan.
- **Verifikasi:** Bandingkan visual.
- **Catatan/risiko:** Canvas tidak boleh ter-taint; gambar same-origin sehingga aman.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-07 — Umpan balik visual snapshot

- **Status:** [ ]
- **Deskripsi:** Kilas putih singkat dan toast 'tersimpan'.
- **Berkas/lokasi:** `index.html`
- **Bergantung pada:** P9-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak mengganggu aksesibilitas (tanpa kedip cepat).
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P9-08 — Penanganan snapshot gagal

- **Status:** [ ]
- **Deskripsi:** Tampilkan alasan (no_frame, 429, 401) dengan pesan ramah.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P9-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Setiap kode error punya pesan.
- **Verifikasi:** Simulasikan tiap error.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P10 — Keamanan dan Kontrol Akses

**Tujuan fase:** Melindungi data wajah dan nama serta membatasi penyalahgunaan sumber daya.

**Kriteria keluar fase:**

- [ ] Tanpa token yang benar, tidak ada data sensitif keluar (bila token diaktifkan).
- [ ] Batas klien dan laju ditegakkan.

##### P10-01 — Konfigurasi bind dan CIDR

- **Status:** [ ]
- **Deskripsi:** Terapkan allow_cidrs; tolak alamat di luar daftar dengan 403.
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P1-08
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Alamat luar diblokir.
  - [ ] Alamat loopback selalu diizinkan.
- **Verifikasi:** Uji dari dua subnet.
- **Catatan/risiko:** Perhatikan jika ada proxy (X-Forwarded-For jangan dipercaya secara default).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-02 — Autentikasi token

- **Status:** [ ]
- **Deskripsi:** Token statis dari konfigurasi; terima lewat header Authorization: Bearer atau cookie HttpOnly setelah login, atau query ?t= untuk <img>/EventSource.
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P10-01
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Tanpa token: 401 pada semua endpoint selain /healthz dan halaman login.
  - [ ] Perbandingan token konstan-waktu.
- **Verifikasi:** Uji negatif dan positif.
- **Catatan/risiko:** EventSource dan <img> tidak bisa mengirim header kustom; cookie lebih baik daripada query.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-03 — Cookie sesi sederhana

- **Status:** [ ]
- **Deskripsi:** Endpoint POST /api/login menukar token dengan cookie HttpOnly SameSite=Strict (tanpa Secure bila HTTP LAN).
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P10-02
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Cookie tidak terbaca JS.
  - [ ] Logout menghapus cookie.
- **Verifikasi:** Periksa DevTools Application.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-04 — Batas laju login

- **Status:** [ ]
- **Deskripsi:** Maksimal 5 percobaan per menit per IP; setelahnya 429 selama 60 detik.
- **Berkas/lokasi:** `src/monitor/auth.rs`
- **Bergantung pada:** P10-03
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Percobaan ke-6 ditolak.
- **Verifikasi:** Skrip.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-05 — Header keamanan

- **Status:** [ ]
- **Deskripsi:** CSP ketat, X-Content-Type-Options nosniff, Referrer-Policy no-referrer, X-Frame-Options DENY.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P5-02
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Pemindai header (manual) tidak melaporkan kelemahan dasar.
- **Verifikasi:** curl -I.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-06 — Pencegahan XSS

- **Status:** [ ]
- **Deskripsi:** Semua teks dari server (nama, error) disisipkan lewat textContent, bukan innerHTML.
- **Berkas/lokasi:** `index.html (JS)`
- **Bergantung pada:** P6-05
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Nama berisi `<script>` ditampilkan sebagai teks.
- **Verifikasi:** Uji dengan nama uji berbahaya.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-07 — Masking data saat show_names=false

- **Status:** [ ]
- **Deskripsi:** Verifikasi bahwa tidak ada jalur lain (log, status, error) yang membocorkan nama.
- **Berkas/lokasi:** `seluruh monitor`
- **Bergantung pada:** P3-04
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Pencarian string nama di semua respons nol hasil.
- **Verifikasi:** Grep trafik yang direkam.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P10-08 — Ulasan model ancaman singkat

- **Status:** [ ]
- **Deskripsi:** Tulis STRIDE ringkas: siapa bisa mengakses, apa yang bocor, bagaimana DoS, dampak ke jalur inti.
- **Berkas/lokasi:** `docs/threat-model.md`
- **Bergantung pada:** P10-01..P10-07
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Setiap ancaman punya mitigasi atau risiko diterima tertulis.
- **Verifikasi:** Review pemilik.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P11 — Optimasi dan Penegakan Anggaran

**Tujuan fase:** Mengukur dan memangkas biaya sampai memenuhi anggaran bagian 9.

**Kriteria keluar fase:**

- [ ] Semua target di bagian 9 terpenuhi atau pengecualian disetujui.
- [ ] Mekanisme shed load aktif.

##### P11-01 — Ukur penambahan RSS

- **Status:** [ ]
- **Deskripsi:** Bandingkan baseline P0-05 dengan monitor aktif: 0, 1, 2 klien.
- **Berkas/lokasi:** `docs/perf-report.md`
- **Bergantung pada:** P2-12, P3-09
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Tabel RSS lengkap.
  - [ ] Selisih 1 klien ≤ 12 MB.
- **Verifikasi:** Skrip bench.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-02 — Ukur penambahan CPU

- **Status:** [ ]
- **Deskripsi:** pidstat atau top per thread untuk skenario 9.2.
- **Berkas/lokasi:** `docs/perf-report.md`
- **Bergantung pada:** P11-01
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Semua skenario dibandingkan dengan target.
- **Verifikasi:** Skrip bench.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-03 — Hilangkan salinan di handler MJPEG

- **Status:** [ ]
- **Deskripsi:** Kirim header dan payload sebagai Bytes terpisah (chunk) tanpa menyalin JPEG.
- **Berkas/lokasi:** `src/monitor/routes.rs`
- **Bergantung pada:** P11-02
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Salinan per frame nol di jalur passthrough.
- **Verifikasi:** Ukur alokasi dengan counter.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-04 — Pooling buffer encoder

- **Status:** [ ]
- **Deskripsi:** Gunakan buffer berulang untuk output JPEG agar tidak mengalokasi tiap frame.
- **Berkas/lokasi:** `src/monitor/encoder.rs`
- **Bergantung pada:** P11-02
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Alokasi per frame turun signifikan.
- **Verifikasi:** Counter alokasi.
- **Catatan/risiko:** Hanya relevan bila kamera non-MJPG.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-05 — Shed load otomatis

- **Status:** [ ]
- **Deskripsi:** Jika ai.fps < ambang 10 detik berturut-turut dan ada klien, turunkan fps stream; pulihkan bertahap.
- **Berkas/lokasi:** `src/monitor/mod.rs`
- **Bergantung pada:** P11-02
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Beban stream turun saat AI melambat.
  - [ ] Peringatan tercatat satu kali per episode.
- **Verifikasi:** Simulasikan beban CPU (stress).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-06 — Prioritas thread

- **Status:** [ ]
- **Deskripsi:** Turunkan prioritas (nice/sched) thread monitor; pastikan inferensi tidak terganggu.
- **Berkas/lokasi:** `src/monitor/mod.rs`
- **Bergantung pada:** P11-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] FPS AI tidak turun > 3% dengan 2 klien.
- **Verifikasi:** Bandingkan dengan baseline.
- **Catatan/risiko:** Di Linux gunakan setpriority per thread (libc).
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-07 — Profil dan ukuran biner

- **Status:** [ ]
- **Deskripsi:** Bandingkan ukuran biner dengan/ tanpa fitur; target kenaikan ≤ 300 KB.
- **Berkas/lokasi:** `docs/perf-report.md`
- **Bergantung pada:** P11-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Angka tercatat.
- **Verifikasi:** ls -l, cargo bloat.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P11-08 — Uji termal 1 jam

- **Status:** [ ]
- **Deskripsi:** Jalankan dengan 1 klien selama 1 jam di ruangan biasa; catat suhu dan throttling.
- **Berkas/lokasi:** `docs/perf-report.md`
- **Bergantung pada:** P11-02
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada throttling baru dibanding baseline.
- **Verifikasi:** Log suhu per menit.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P12 — Pengujian dan QA

**Tujuan fase:** Memastikan fungsi, kompatibilitas browser, dan ketahanan.

**Kriteria keluar fase:**

- [ ] Semua kasus uji prioritas tinggi lulus.
- [ ] Tidak ada bug blocker terbuka.

##### P12-01 — Uji unit modul monitor

- **Status:** [ ]
- **Deskripsi:** Cakupan metrics, frame_hub, detection_bus, status, auth.
- **Berkas/lokasi:** `tests/`
- **Bergantung pada:** P1-*..P4-*
- **Est.:** 6 jam
- **AC (kriteria selesai):**
  - [ ] Semua lulus di CI dan di perangkat.
- **Verifikasi:** cargo test.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-02 — Uji integrasi HTTP

- **Status:** [ ]
- **Deskripsi:** Jalankan server uji dengan sumber frame sintetis; panggil semua endpoint.
- **Berkas/lokasi:** `tests/http.rs`
- **Bergantung pada:** P12-01
- **Est.:** 5 jam
- **AC (kriteria selesai):**
  - [ ] Setiap endpoint punya uji sukses dan gagal.
- **Verifikasi:** cargo test --features monitor-ui.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-03 — Sumber kamera sintetis

- **Status:** [ ]
- **Deskripsi:** Generator frame (pola gerak + kotak) agar uji tidak butuh kamera nyata.
- **Berkas/lokasi:** `src/monitor/testsrc.rs`
- **Bergantung pada:** P12-02
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Dapat menghasilkan 10–30 fps dengan 0–10 wajah.
- **Verifikasi:** Dipakai di uji integrasi.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-04 — Matriks uji browser

- **Status:** [ ]
- **Deskripsi:** Jalankan daftar uji di bagian 11 pada Chrome, Firefox, Safari, Edge; desktop dan HP.
- **Berkas/lokasi:** `docs/test-reports/browser-matrix.md`
- **Bergantung pada:** P7-*..P9-*
- **Est.:** 6 jam
- **AC (kriteria selesai):**
  - [ ] Matriks terisi lulus/gagal.
- **Verifikasi:** Uji manual.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-05 — Uji beban klien

- **Status:** [ ]
- **Deskripsi:** Tambah klien hingga batas; pastikan penolakan bersih dan inti tidak terganggu.
- **Berkas/lokasi:** `scripts/bench/clients.sh`
- **Bergantung pada:** P2-06
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Klien ke-3 ditolak 503; FPS AI stabil.
- **Verifikasi:** Skrip.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-06 — Uji ketahanan 24 jam

- **Status:** [ ]
- **Deskripsi:** Satu klien terus terhubung 24 jam dengan sambung ulang acak.
- **Berkas/lokasi:** `docs/soak-test.md`
- **Bergantung pada:** P11-*
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada kebocoran RSS (> 5%).
- **Verifikasi:** Log metrik.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-07 — Uji kegagalan

- **Status:** [ ]
- **Deskripsi:** Kamera dicabut, server pusat mati, disk penuh, klien putus mendadak, SIGTERM.
- **Berkas/lokasi:** `docs/test-reports/failure.md`
- **Bergantung pada:** P12-02
- **Est.:** 4 jam
- **AC (kriteria selesai):**
  - [ ] Inti tetap hidup di semua skenario.
  - [ ] Monitor menampilkan status yang masuk akal.
- **Verifikasi:** Uji manual terstruktur.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-08 — Uji regresi jalur inti

- **Status:** [ ]
- **Deskripsi:** Bandingkan keputusan presensi dan FPS sebelum/sesudah fitur.
- **Berkas/lokasi:** `docs/test-reports/regression.md`
- **Bergantung pada:** P8-01
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Keputusan identik pada set uji tetap.
- **Verifikasi:** Skrip perbandingan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-09 — Uji aksesibilitas dasar

- **Status:** [ ]
- **Deskripsi:** Navigasi keyboard, kontras, pembaca layar untuk status.
- **Berkas/lokasi:** `docs/test-reports/a11y.md`
- **Bergantung pada:** P6-12
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada masalah blocker.
- **Verifikasi:** Uji manual + alat kontras.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P12-10 — Uji keamanan dasar

- **Status:** [ ]
- **Deskripsi:** Uji negatif token, CIDR, XSS, laju, header.
- **Berkas/lokasi:** `docs/test-reports/security.md`
- **Bergantung pada:** P10-*
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Semua uji negatif lulus.
- **Verifikasi:** Skrip curl.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P13 — Deployment dan Operasi

**Tujuan fase:** Menjalankan dengan aman di perangkat nyata dan mudah dirawat.

**Kriteria keluar fase:**

- [ ] Layanan systemd berjalan stabil setelah reboot.
- [ ] Prosedur rollback teruji.

##### P13-01 — Unit systemd dan batas sumber daya

- **Status:** [ ]
- **Deskripsi:** MemoryMax, CPUQuota untuk menahan lonjakan, Restart=on-failure.
- **Berkas/lokasi:** `deploy/engine-server.service`
- **Bergantung pada:** P11-*
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Layanan hidup setelah reboot.
  - [ ] Batas memori tidak memicu OOM pada beban normal.
- **Verifikasi:** Reboot uji.
- **Catatan/risiko:** CPUQuota di seluruh layanan bisa merugikan inti; uji hati-hati.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-02 — Konfigurasi produksi

- **Status:** [ ]
- **Deskripsi:** Berkas konfigurasi contoh dengan token acak, CIDR, show_names sesuai keputusan.
- **Berkas/lokasi:** `deploy/engine.toml.example`
- **Bergantung pada:** P10-*
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Tidak ada rahasia di repo.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-03 — Rotasi log

- **Status:** [ ]
- **Deskripsi:** Pastikan log tidak memenuhi kartu SD; batasi ukuran jurnal.
- **Berkas/lokasi:** `deploy/journald.conf`
- **Bergantung pada:** P13-01
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Jurnal ≤ ukuran yang ditetapkan.
- **Verifikasi:** journalctl --disk-usage.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-04 — Prosedur rollback

- **Status:** [ ]
- **Deskripsi:** Skrip mengembalikan biner sebelumnya dan konfigurasi.
- **Berkas/lokasi:** `scripts/rollback.sh`
- **Bergantung pada:** P0-10
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Rollback < 1 menit.
- **Verifikasi:** Latihan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-05 — Pemantauan eksternal sederhana

- **Status:** [ ]
- **Deskripsi:** Skrip cron memeriksa /healthz dan /api/status; kirim peringatan bila outbox pending lama.
- **Berkas/lokasi:** `scripts/watch.sh`
- **Bergantung pada:** P4-06
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Peringatan terkirim pada skenario uji.
- **Verifikasi:** Simulasi.
- **Catatan/risiko:** Opsional.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-06 — Pengaturan jaringan

- **Status:** [ ]
- **Deskripsi:** IP statis atau reservasi DHCP; catat mDNS (mis. absensi-cam.local) bila tersedia.
- **Berkas/lokasi:** `docs/network.md`
- **Bergantung pada:** P0-06
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Alamat stabil terdokumentasi.
- **Verifikasi:** Reboot router uji.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-07 — Hardening OS minimal

- **Status:** [ ]
- **Deskripsi:** Matikan layanan tak perlu, firewall hanya buka port yang diperlukan.
- **Berkas/lokasi:** `docs/hardening.md`
- **Bergantung pada:** P13-06
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Port terbuka sesuai daftar.
- **Verifikasi:** nmap dari klien.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P13-08 — Uji penerimaan di lokasi

- **Status:** [ ]
- **Deskripsi:** Pasang di lokasi nyata, uji dari HP operator, kumpulkan masukan.
- **Berkas/lokasi:** `docs/test-reports/uat.md`
- **Bergantung pada:** P13-01..P13-07
- **Est.:** 3 jam
- **AC (kriteria selesai):**
  - [ ] Operator dapat membuka halaman dan membaca status tanpa bantuan.
- **Verifikasi:** Sesi uji dengan operator.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

#### Fase P14 — Dokumentasi dan Serah Terima

**Tujuan fase:** Memastikan orang lain dapat mengoperasikan dan memelihara fitur ini.

**Kriteria keluar fase:**

- [ ] Dokumentasi lengkap dan diuji oleh orang yang belum pernah melihat proyek.
- [ ] Checklist rilis terisi.

##### P14-01 — README fitur monitor

- **Status:** [ ]
- **Deskripsi:** Cara mengaktifkan, mengakses, mengonfigurasi, dan mematikan.
- **Berkas/lokasi:** `docs/monitor.md`
- **Bergantung pada:** P13-*
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Orang baru berhasil membuka halaman hanya dari README.
- **Verifikasi:** Uji oleh rekan.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P14-02 — Panduan pemecahan masalah

- **Status:** [ ]
- **Deskripsi:** Salin dan rapikan Bagian 14 dokumen ini.
- **Berkas/lokasi:** `docs/troubleshooting.md`
- **Bergantung pada:** P12-*
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Gejala umum punya langkah diagnosis.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P14-03 — Referensi API final

- **Status:** [ ]
- **Deskripsi:** Perbarui dengan contoh nyata.
- **Berkas/lokasi:** `docs/api.md`
- **Bergantung pada:** P4-08
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Sesuai perilaku aktual.
- **Verifikasi:** Uji kontrak.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P14-04 — Catatan keputusan arsitektur (ADR)

- **Status:** [ ]
- **Deskripsi:** ADR untuk MJPEG, SSE, S1 vs S2, token, shed load.
- **Berkas/lokasi:** `docs/adr/`
- **Bergantung pada:** P11-*
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Lima ADR singkat.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P14-05 — Laporan akhir performa

- **Status:** [ ]
- **Deskripsi:** Satu halaman: target vs hasil, pengecualian, rekomendasi.
- **Berkas/lokasi:** `docs/perf-report.md`
- **Bergantung pada:** P11-*
- **Est.:** 2 jam
- **AC (kriteria selesai):**
  - [ ] Semua target punya status.
- **Verifikasi:** Review.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

##### P14-06 — Retrospektif dan ide lanjutan

- **Status:** [ ]
- **Deskripsi:** Catat apa yang berhasil, yang tidak, dan daftar ide (lihat Bagian 16).
- **Berkas/lokasi:** `docs/retro.md`
- **Bergantung pada:** P14-05
- **Est.:** 1 jam
- **AC (kriteria selesai):**
  - [ ] Daftar ide diprioritaskan.
- **Verifikasi:** Diskusi tim.
- **Log pengerjaan:** _(isi tanggal, siapa, hasil, tautan commit)_

---

## 11. Rencana dan Matriks Pengujian

### 11.1 Strategi Uji

| Lapisan | Alat | Dijalankan di | Kapan |
| ------- | ---- | ------------- | ----- |
| Unit (Rust) | `cargo test` | dev + perangkat | tiap commit |
| Integrasi HTTP | `cargo test --features monitor-ui` dengan sumber sintetis | dev | tiap PR |
| Kontrak API | skrip curl + jq | dev + perangkat | tiap PR yang menyentuh API |
| UI manual | daftar centang 11.3 | browser nyata | tiap rilis kandidat |
| Beban dan ketahanan | skrip bench | perangkat | P11, P12 |
| Keamanan | skrip negatif | dev + perangkat | P10, P12 |

### 11.2 Lingkungan Uji

| ID | Lingkungan | Jaringan |
| -- | ---------- | -------- |
| E1 | Chrome desktop (Windows/Linux) | kabel |
| E2 | Firefox desktop | kabel |
| E3 | Edge desktop | kabel |
| E4 | Safari macOS | Wi-Fi |
| E5 | Chrome Android | Wi-Fi 2.4 GHz |
| E6 | Safari iOS | Wi-Fi 5 GHz |

### 11.3 Daftar Kasus Uji Fitur

Setiap kasus dijalankan di semua lingkungan E1–E6 (lihat 11.4). Kolom 'Harapan' berlaku untuk semuanya,
kecuali ada catatan khusus.

| ID | Kasus | Langkah | Harapan |
| -- | ----- | ------- | ------- |
| T01 | Halaman terbuka | Buka `http://<ip>:5000/` | Halaman tampil < 3 s tanpa error konsol |
| T02 | Video tampil | Tunggu 5 detik | Gambar bergerak, tidak hitam, tidak membeku |
| T03 | Status kamera | Lihat kartu Kamera | FPS terlihat dan mendekati pengamatan manual |
| T04 | Status AI | Lihat kartu AI | FPS AI dan latensi terisi |
| T05 | Template | Lihat kartu Template | Jumlah dan versi terisi |
| T06 | Outbox | Lihat kartu Outbox | Pending/gagal terisi |
| T07 | Status berubah | Buat presensi baru | Pending naik lalu turun setelah sinkron |
| T08 | Kotak deteksi | Hadapkan wajah ke kamera | Kotak muncul di wajah |
| T09 | Sejajar kotak | Gerakkan kepala pelan | Kotak mengikuti dengan selisih tidak mengganggu |
| T10 | Toggle overlay | Tekan tombol Overlay | Kotak hilang/muncul seketika |
| T11 | Klik kotak | Klik kotak wajah | Pop-up muncul dengan nama dan skor |
| T12 | Top-2 | Buka pop-up wajah dikenal | Skor ke-2 dan margin tampil |
| T13 | Wajah tak dikenal | Hadapkan wajah tak terdaftar | Kotak merah; pop-up 'Tidak dikenal' |
| T14 | Banyak wajah | Dua orang di depan kamera | Dua kotak; klik memilih yang benar |
| T15 | Kotak bertumpuk | Dua wajah berimpit | Klik memilih kotak terkecil |
| T16 | Tutup pop-up | Tekan Esc / klik di luar | Pop-up menutup |
| T17 | Pause | Tekan Pause | Video membeku; klien aktif turun |
| T18 | Resume | Tekan Resume | Video lanjut < 2 s |
| T19 | Snapshot | Tekan Snapshot | Berkas JPEG valid terunduh |
| T20 | Snapshot saat pause | Pause lalu Snapshot | Berkas tetap valid |
| T21 | Server restart | Restart layanan saat halaman terbuka | Halaman pulih otomatis < 20 s |
| T22 | Jaringan putus | Putuskan jaringan 10 s | Status 'menyambung ulang', lalu pulih |
| T23 | Tab di latar belakang | Ganti tab 10 s | Klien aktif turun; saat kembali video lanjut |
| T24 | Rotasi layar (HP) | Putar layar | Tata letak menyesuaikan; kotak tetap sejajar |
| T25 | Batas klien | Buka 3 tab stream | Tab ke-3 menampilkan pesan batas |
| T26 | Token salah | Masukkan token salah | Ditolak 401; pesan jelas |
| T27 | Mode gelap | Ubah tema OS | Halaman mengikuti dan terbaca |
| T28 | Keyboard | Operasikan dengan Tab/O/P/S/Esc | Semua fungsi bisa dijangkau |
| T29 | Privasi nama | Setel show_names=false | Tidak ada nama di UI maupun jaringan |
| T30 | Kamera dicabut | Cabut kamera | Placeholder 'menunggu kamera'; inti tidak crash |

### 11.4 Matriks Hasil (isi saat QA)

Tandai `L` lulus, `G` gagal, `N` tidak berlaku, `-` belum diuji. Catat bug di Bagian 15 bila `G`.

| Kasus | E1 | E2 | E3 | E4 | E5 | E6 | Catatan |
| ----- | -- | -- | -- | -- | -- | -- | ------- |
| T01 Halaman terbuka | - | - | - | - | - | - |  |
| T02 Video tampil | - | - | - | - | - | - |  |
| T03 Status kamera | - | - | - | - | - | - |  |
| T04 Status AI | - | - | - | - | - | - |  |
| T05 Template | - | - | - | - | - | - |  |
| T06 Outbox | - | - | - | - | - | - |  |
| T07 Status berubah | - | - | - | - | - | - |  |
| T08 Kotak deteksi | - | - | - | - | - | - |  |
| T09 Sejajar kotak | - | - | - | - | - | - |  |
| T10 Toggle overlay | - | - | - | - | - | - |  |
| T11 Klik kotak | - | - | - | - | - | - |  |
| T12 Top-2 | - | - | - | - | - | - |  |
| T13 Wajah tak dikenal | - | - | - | - | - | - |  |
| T14 Banyak wajah | - | - | - | - | - | - |  |
| T15 Kotak bertumpuk | - | - | - | - | - | - |  |
| T16 Tutup pop-up | - | - | - | - | - | - |  |
| T17 Pause | - | - | - | - | - | - |  |
| T18 Resume | - | - | - | - | - | - |  |
| T19 Snapshot | - | - | - | - | - | - |  |
| T20 Snapshot saat pause | - | - | - | - | - | - |  |
| T21 Server restart | - | - | - | - | - | - |  |
| T22 Jaringan putus | - | - | - | - | - | - |  |
| T23 Tab di latar belakang | - | - | - | - | - | - |  |
| T24 Rotasi layar (HP) | - | - | - | - | - | - |  |
| T25 Batas klien | - | - | - | - | - | - |  |
| T26 Token salah | - | - | - | - | - | - |  |
| T27 Mode gelap | - | - | - | - | - | - |  |
| T28 Keyboard | - | - | - | - | - | - |  |
| T29 Privasi nama | - | - | - | - | - | - |  |
| T30 Kamera dicabut | - | - | - | - | - | - |  |

### 11.5 Kasus Uji Mendetail (per kasus, dengan data pendukung)

Bagian ini menjabarkan setiap kasus pada 11.3 menjadi prasyarat, langkah, dan bukti yang harus dilampirkan.

#### T01 — Halaman terbuka

- **Prasyarat:** Layanan hidup; jaringan sama dengan perangkat.
- **Langkah:**
  1. Buka URL di tab baru.
  2. Buka DevTools Console dan Network.
- **Harapan:** Halaman tampil < 3 s tanpa error konsol
- **Bukti:** Tangkapan layar halaman dan Network (waktu muat).
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T02 — Video tampil

- **Prasyarat:** Kamera terpasang dan menyala.
- **Langkah:**
  1. Lambaikan tangan di depan kamera.
  2. Amati selama 5 detik.
- **Harapan:** Gambar bergerak, tidak hitam, tidak membeku
- **Bukti:** Rekaman layar singkat.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T03 — Status kamera

- **Prasyarat:** Kamera aktif.
- **Langkah:**
  1. Bandingkan FPS kartu dengan hasil `v4l2-ctl --stream-mmap`.
- **Harapan:** FPS terlihat dan mendekati pengamatan manual
- **Bukti:** Dua angka berdampingan.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T04 — Status AI

- **Prasyarat:** Ada wajah di depan kamera.
- **Langkah:**
  1. Catat FPS AI dan latensi.
  2. Bandingkan dengan log inti.
- **Harapan:** FPS AI dan latensi terisi
- **Bukti:** Angka dan potongan log.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T05 — Template

- **Prasyarat:** Template terisi.
- **Langkah:**
  1. Catat jumlah dan versi.
  2. Bandingkan dengan sumber data template.
- **Harapan:** Jumlah dan versi terisi
- **Bukti:** Angka dan sumber.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T06 — Outbox

- **Prasyarat:** Outbox punya data uji.
- **Langkah:**
  1. Catat pending/gagal.
  2. Bandingkan dengan query langsung ke outbox.
- **Harapan:** Pending/gagal terisi
- **Bukti:** Angka dan hasil query.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T07 — Status berubah

- **Prasyarat:** Server pusat dapat dijangkau.
- **Langkah:**
  1. Catat pending.
  2. Lakukan presensi.
  3. Amati perubahan 30 detik.
- **Harapan:** Pending naik lalu turun setelah sinkron
- **Bukti:** Grafik atau catatan waktu.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T08 — Kotak deteksi

- **Prasyarat:** Satu orang terdaftar.
- **Langkah:**
  1. Hadapkan wajah.
  2. Amati kotak.
- **Harapan:** Kotak muncul di wajah
- **Bukti:** Tangkapan layar.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T09 — Sejajar kotak

- **Prasyarat:** Overlay aktif.
- **Langkah:**
  1. Gerakkan kepala kiri-kanan.
  2. Nilai selisih visual.
- **Harapan:** Kotak mengikuti dengan selisih tidak mengganggu
- **Bukti:** Rekaman layar.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T10 — Toggle overlay

- **Prasyarat:** Kotak terlihat.
- **Langkah:**
  1. Tekan Overlay dua kali.
- **Harapan:** Kotak hilang/muncul seketika
- **Bukti:** Rekaman layar.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T11 — Klik kotak

- **Prasyarat:** Kotak terlihat.
- **Langkah:**
  1. Klik di tengah kotak.
  2. Klik di tepi kotak.
  3. Klik di luar kotak.
- **Harapan:** Pop-up muncul dengan nama dan skor
- **Bukti:** Tangkapan tiga kondisi.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T12 — Top-2

- **Prasyarat:** Wajah dikenal.
- **Langkah:**
  1. Buka pop-up.
  2. Catat skor, skor ke-2, margin.
  3. Bandingkan dengan log matcher.
- **Harapan:** Skor ke-2 dan margin tampil
- **Bukti:** Tangkapan dan log.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T13 — Wajah tak dikenal

- **Prasyarat:** Wajah tak terdaftar tersedia.
- **Langkah:**
  1. Hadapkan wajah.
  2. Klik kotak.
- **Harapan:** Kotak merah; pop-up 'Tidak dikenal'
- **Bukti:** Tangkapan layar.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T14 — Banyak wajah

- **Prasyarat:** Dua orang tersedia.
- **Langkah:**
  1. Hadapkan keduanya.
  2. Klik masing-masing.
- **Harapan:** Dua kotak; klik memilih yang benar
- **Bukti:** Tangkapan layar.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T15 — Kotak bertumpuk

- **Prasyarat:** Dua orang berdiri berdekatan.
- **Langkah:**
  1. Cari posisi kotak berimpit.
  2. Klik di area impitan.
- **Harapan:** Klik memilih kotak terkecil
- **Bukti:** Tangkapan layar dan koordinat.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T16 — Tutup pop-up

- **Prasyarat:** Pop-up terbuka.
- **Langkah:**
  1. Tekan Esc.
  2. Buka lagi, klik di luar.
- **Harapan:** Pop-up menutup
- **Bukti:** Catatan hasil.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T17 — Pause

- **Prasyarat:** Video berjalan.
- **Langkah:**
  1. Tekan Pause.
  2. Cek `monitor_clients` di status.
- **Harapan:** Video membeku; klien aktif turun
- **Bukti:** Angka sebelum dan sesudah.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T18 — Resume

- **Prasyarat:** Video dijeda.
- **Langkah:**
  1. Tekan Resume.
  2. Ukur waktu sampai bergerak.
- **Harapan:** Video lanjut < 2 s
- **Bukti:** Waktu.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T19 — Snapshot

- **Prasyarat:** Frame tersedia.
- **Langkah:**
  1. Tekan Snapshot.
  2. Buka berkas.
- **Harapan:** Berkas JPEG valid terunduh
- **Bukti:** Berkas dan ukuran.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T20 — Snapshot saat pause

- **Prasyarat:** Video dijeda.
- **Langkah:**
  1. Tekan Snapshot.
- **Harapan:** Berkas tetap valid
- **Bukti:** Berkas.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T21 — Server restart

- **Prasyarat:** Halaman terbuka.
- **Langkah:**
  1. `systemctl restart engine-server`.
  2. Amati pemulihan.
- **Harapan:** Halaman pulih otomatis < 20 s
- **Bukti:** Waktu pemulihan.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T22 — Jaringan putus

- **Prasyarat:** Halaman terbuka.
- **Langkah:**
  1. Matikan Wi-Fi klien 10 s.
  2. Nyalakan kembali.
- **Harapan:** Status 'menyambung ulang', lalu pulih
- **Bukti:** Waktu pemulihan.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T23 — Tab di latar belakang

- **Prasyarat:** Halaman terbuka.
- **Langkah:**
  1. Ganti tab 10 s.
  2. Cek `monitor_clients`.
  3. Kembali.
- **Harapan:** Klien aktif turun; saat kembali video lanjut
- **Bukti:** Angka.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T24 — Rotasi layar (HP)

- **Prasyarat:** HP dengan rotasi aktif.
- **Langkah:**
  1. Putar layar.
  2. Cek kotak.
- **Harapan:** Tata letak menyesuaikan; kotak tetap sejajar
- **Bukti:** Tangkapan dua orientasi.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T25 — Batas klien

- **Prasyarat:** Batas stream=2.
- **Langkah:**
  1. Buka tiga tab.
  2. Amati tab ketiga.
- **Harapan:** Tab ke-3 menampilkan pesan batas
- **Bukti:** Tangkapan pesan.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T26 — Token salah

- **Prasyarat:** Token aktif.
- **Langkah:**
  1. Masukkan token salah.
  2. Masukkan token benar.
- **Harapan:** Ditolak 401; pesan jelas
- **Bukti:** Tangkapan dua kondisi.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T27 — Mode gelap

- **Prasyarat:** Tema OS dapat diubah.
- **Langkah:**
  1. Ubah tema.
  2. Amati halaman.
- **Harapan:** Halaman mengikuti dan terbaca
- **Bukti:** Tangkapan dua tema.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T28 — Keyboard

- **Prasyarat:** Keyboard tersedia.
- **Langkah:**
  1. Operasikan hanya dengan keyboard.
- **Harapan:** Semua fungsi bisa dijangkau
- **Bukti:** Catatan fungsi yang gagal.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T29 — Privasi nama

- **Prasyarat:** Konfigurasi dapat diubah.
- **Langkah:**
  1. Setel show_names=false.
  2. Rekam trafik /events.
  3. Cari nama.
- **Harapan:** Tidak ada nama di UI maupun jaringan
- **Bukti:** Rekaman trafik tanpa nama.
- **Hasil:** _(L/G/N, tanggal, penguji)_

#### T30 — Kamera dicabut

- **Prasyarat:** Kamera dapat dicabut.
- **Langkah:**
  1. Cabut kamera.
  2. Amati UI dan log.
  3. Pasang kembali.
- **Harapan:** Placeholder 'menunggu kamera'; inti tidak crash
- **Bukti:** Log dan tangkapan.
- **Hasil:** _(L/G/N, tanggal, penguji)_

---

## 12. Prosedur Pengukuran Performa

Tujuan: membuat klaim "ringan" dapat dibuktikan. Semua pengukuran dijalankan di Orange Pi
Lite 2 asli, dengan catu daya stabil, pada suhu ruang yang dicatat.

### 12.1 Prinsip

1. Ukur **sebelum** (baseline P0-05) dan **sesudah** dengan kondisi sama.
2. Jalankan minimal 3 kali per skenario; laporkan median dan rentang.
3. Catat versi biner (git hash), konfigurasi, dan suhu awal.
4. Jangan ukur saat kompilasi atau pembaruan paket berjalan.
5. Jangan membuka browser di perangkat itu sendiri saat mengukur.

### 12.2 Skenario Standar

| ID | Skenario | Klien | Durasi |
| -- | -------- | ----- | ------ |
| M0 | Baseline tanpa fitur monitor (build tanpa feature) | 0 | 10 menit |
| M1 | Fitur aktif, tidak ada klien | 0 | 10 menit |
| M2 | Satu klien, stream 8 fps, SSE aktif | 1 | 10 menit |
| M3 | Dua klien, stream 8 fps | 2 | 10 menit |
| M4 | Satu klien, stream 15 fps | 1 | 10 menit |
| M5 | Satu klien lalu pause 5 menit | 1→0 | 10 menit |
| M6 | Klien lambat (dibatasi 100 kbit/s) | 1 | 10 menit |
| M7 | Tiga percobaan klien (batas 2) | 3 | 5 menit |

### 12.3 Metrik yang Dicatat

| Metrik | Cara ambil | Satuan |
| ------ | ---------- | ------ |
| RSS proses | `/proc/<pid>/status` (VmRSS) | MB |
| CPU per thread | `top -H -p <pid>` atau `pidstat -t` | % core |
| FPS kamera | `/api/status` | fps |
| FPS AI | `/api/status` | fps |
| Latensi inferensi p50/p95 | `/api/status` | ms |
| Suhu CPU | `/sys/class/thermal/thermal_zone0/temp` | °C |
| Frekuensi CPU | `/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq` | MHz |
| Bandwidth keluar | `ip -s link` atau `nethogs` | KB/s |
| Jumlah koneksi | `ss -tn state established '( sport = :5000 )'` | koneksi |

### 12.4 Skrip Sampling Sederhana

```bash
#!/usr/bin/env bash
# scripts/bench/sample.sh <pid> <detik> > out.csv
PID=$1; DUR=${2:-600}
echo "t,rss_kb,temp_mc,freq_khz,cam_fps,ai_fps,clients"
for ((i=0;i<DUR;i++)); do
  RSS=$(awk '/VmRSS/{print $2}' /proc/$PID/status)
  TMP=$(cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null || echo 0)
  FRQ=$(cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq 2>/dev/null || echo 0)
  J=$(curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:5000/api/status)
  CAM=$(echo "$J" | jq -r '.camera.fps')
  AI=$(echo "$J" | jq -r '.ai.fps')
  CL=$(echo "$J" | jq -r '.system.monitor_clients')
  echo "$i,$RSS,$TMP,$FRQ,$CAM,$AI,$CL"
  sleep 1
done
```

Catatan: pemanggilan `/api/status` itu sendiri menambah beban kecil. Untuk M0 (tanpa fitur) ambil FPS dari log inti.

### 12.5 Ambang Lulus/Gagal

| Metrik | Lulus | Peringatan | Gagal |
| ------ | ----- | ---------- | ----- |
| Δ RSS (M2 − M0) | ≤ 3 MB | 3–12 MB | > 12 MB |
| Δ CPU (M1 − M0) | < 0.5% core | 0.5–1.5% | > 1.5% |
| Δ CPU (M2 − M0, passthrough) | < 3% core | 3–6% | > 6% |
| Δ FPS AI (M2 vs M0) | ≤ 2% | 2–5% | > 5% |
| Δ suhu stabil | ≤ 2 °C | 2–5 °C | > 5 °C |
| Pertumbuhan RSS 1 jam | < 2% | 2–5% | > 5% |
| Waktu pemulihan klien | < 20 s | 20–40 s | > 40 s |

### 12.6 Template Laporan Pengukuran

```text
Tanggal:            ______
Git hash:           ______
Konfigurasi:        ______ (lampirkan berkas)
Suhu ruang:         ______ °C
Catu daya:          ______ V / ______ A
Skenario:           M_
Run:                1 / 2 / 3

Hasil (median):
  RSS            : ____ MB   (Δ ____ )
  CPU total      : ____ %    (Δ ____ )
  FPS kamera     : ____
  FPS AI         : ____      (Δ ____ )
  p95 inferensi  : ____ ms
  Suhu akhir     : ____ °C
  Bandwidth      : ____ KB/s

Kesimpulan: LULUS / PERINGATAN / GAGAL
Catatan:
```

---

## 13. Deployment dan Operasi

### 13.1 Unit systemd (contoh)

```ini
[Unit]
Description=Absensi engine-server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=absensi
ExecStart=/opt/absensi/engine-server --config /etc/absensi/engine.toml
Restart=on-failure
RestartSec=3
MemoryMax=700M
LimitNOFILE=4096
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/var/lib/absensi
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

Catatan: `MemoryMax` ditetapkan setelah mengukur; nilai di atas hanya contoh. Akses kamera
membutuhkan keanggotaan grup `video` (`SupplementaryGroups=video`).

### 13.2 Langkah Deploy

1. Bangun biner `aarch64` dengan `--release --features monitor-ui`.
2. Salin ke perangkat sebagai `engine-server.new`.
3. Pindahkan biner lama ke `engine-server.prev`.
4. Ganti nama `engine-server.new` menjadi `engine-server`.
5. `systemctl restart engine-server`.
6. Periksa `/healthz` dan `/api/version`.
7. Buka halaman dari laptop; konfirmasi video dan status.
8. Catat versi di jurnal operasi.

### 13.3 Rollback

1. `systemctl stop engine-server`.
2. Tukar `engine-server.prev` kembali ke `engine-server`.
3. Kembalikan berkas konfigurasi dari cadangan jika diubah.
4. `systemctl start engine-server`.
5. Verifikasi `/api/version`.

### 13.4 Konfigurasi Jaringan

- Gunakan IP statis atau reservasi DHCP (contoh `192.168.10.50`).
- Buka hanya port 5000 untuk subnet tepercaya.
- Jangan melakukan port-forward ke internet.
- Bila perlu akses jarak jauh, gunakan VPN (WireGuard/Tailscale) alih-alih membuka port.

### 13.5 Cadangan dan Data

- Fitur monitor tidak menyimpan data persisten.
- Snapshot hanya diunduh ke klien, tidak disimpan di perangkat.
- Cadangkan konfigurasi dan template wajah mengikuti prosedur sistem utama (di luar scope dokumen ini).

### 13.6 Daftar Periksa Operator Harian

- [ ] Halaman terbuka dan video bergerak.
- [ ] FPS kamera dan AI dalam rentang normal.
- [ ] Outbox pending tidak menumpuk (< ambang yang disepakati).
- [ ] Suhu di bawah 70 °C.
- [ ] Tidak ada pesan error merah di log kejadian.

---

## 14. Panduan Pemecahan Masalah

Format: **Gejala → Kemungkinan penyebab → Langkah diagnosis → Perbaikan**.

### 14.1 Halaman tidak terbuka

- **Kemungkinan penyebab:** layanan mati; IP salah; firewall; fitur dimatikan; CIDR menolak.
- **Diagnosis:**
  1. `systemctl status engine-server`.
  2. `curl -v http://127.0.0.1:5000/healthz` dari perangkat.
  3. `ss -ltnp | grep 5000`.
  4. Dari laptop: `ping <ip>` lalu `curl http://<ip>:5000/healthz`.
- **Perbaikan:** nyalakan layanan; perbaiki IP/firewall; periksa `monitor.enabled` dan `allow_cidrs`.

### 14.2 Halaman terbuka tetapi video hitam

- **Kemungkinan penyebab:** kamera belum memberi frame; format tidak didukung; encoder tidak aktif.
- **Diagnosis:** `curl -o /tmp/s.jpg http://<ip>:5000/api/snapshot.jpg` lalu `file /tmp/s.jpg`; periksa log kamera.
- **Perbaikan:** cek kabel/izin `/dev/video*`; pastikan P2-03/P2-05 sesuai format kamera.

### 14.3 Video tersendat

- **Kemungkinan penyebab:** Wi-Fi lemah; fps terlalu tinggi; CPU penuh; shed load aktif.
- **Diagnosis:** turunkan `?fps=4`; lihat `ai.fps` dan suhu; lihat peringatan shed load di log.
- **Perbaikan:** gunakan kabel; turunkan `fps`/`w`; kurangi klien; periksa pendinginan.

### 14.4 Kotak tidak sejajar dengan wajah

- **Kemungkinan penyebab:** rasio kontainer tidak sama dengan rasio sumber; koordinat model belum dikembalikan ke frame asli; selisih waktu S1.
- **Diagnosis:** bandingkan `w`,`h` pada event dengan ukuran gambar; uji dengan gambar bergaris.
- **Perbaikan:** perbaiki transformasi (P7-04); periksa pemetaan letterbox (P3-03); evaluasi P7-12.

### 14.5 Kotak tertinggal beberapa frame

- **Kemungkinan penyebab:** inferensi lebih lambat dari kamera; antrean SSE; klien lambat.
- **Diagnosis:** bandingkan `ai.fps` dan `camera.fps`; lihat `Lagged` di log.
- **Perbaikan:** terima sebagai sifat S1; turunkan resolusi; pertimbangkan S2.

### 14.6 Klik tidak memunculkan pop-up

- **Kemungkinan penyebab:** overlay mati; data kedaluwarsa (TTL); canvas tidak menerima pointer; kotak di luar toleransi.
- **Diagnosis:** nyalakan overlay; klik tepat di tengah; periksa konsol.
- **Perbaikan:** perbaiki `pointer-events`; sesuaikan TTL/toleransi.

### 14.7 Pop-up tidak menampilkan skor ke-2

- **Kemungkinan penyebab:** hanya satu template; `show_second_candidate=false`; matcher belum mengisi top-2.
- **Diagnosis:** lihat payload `/events` dengan `curl -N`.
- **Perbaikan:** atur konfigurasi; selesaikan P8-01.

### 14.8 Status 'server reachable' false padahal server hidup

- **Kemungkinan penyebab:** sinkron gagal karena sertifikat/DNS/waktu; nilai belum diperbarui.
- **Diagnosis:** lihat `outbox.last_error`; cek jam perangkat (NTP).
- **Perbaikan:** sinkronkan jam; perbaiki DNS/sertifikat.

### 14.9 Outbox pending terus naik

- **Kemungkinan penyebab:** server pusat mati; jaringan putus; kredensial salah; batas laju server pusat.
- **Diagnosis:** `last_error`, `oldest_pending_age_s`; uji `curl` ke server pusat.
- **Perbaikan:** pulihkan koneksi; kredensial; tinjau kebijakan retry di inti.

### 14.10 401 Unauthorized

- **Penyebab:** token salah/tidak ada; cookie kedaluwarsa.
- **Perbaikan:** masukkan token; periksa `monitor.token`; hapus cookie lama.

### 14.11 503 too_many_clients

- **Penyebab:** batas stream tercapai (tab lama masih terbuka).
- **Perbaikan:** tutup tab lain; tunggu `Retry-After`; naikkan `max_stream_clients` hanya setelah mengukur.

### 14.12 429 rate_limited

- **Penyebab:** snapshot/login terlalu sering.
- **Perbaikan:** tunggu beberapa detik; periksa skrip otomatis yang berulang.

### 14.13 RSS naik terus

- **Kemungkinan penyebab:** klien lambat menumpuk buffer; guard tidak dilepas; encoder tidak membebaskan buffer.
- **Diagnosis:** pantau `monitor_clients`; uji P2-08; periksa `ss -tn` untuk koneksi hantu.
- **Perbaikan:** terapkan batas buffer dan timeout tulis; perbaiki jalur drop guard.

### 14.14 FPS AI turun saat ada klien

- **Kemungkinan penyebab:** encode JPEG bersaing CPU; prioritas thread; throttling termal.
- **Diagnosis:** M1 vs M2; `top -H`; suhu dan frekuensi.
- **Perbaikan:** passthrough MJPG; turunkan `fps`; prioritas rendah; pendingin; shed load.

### 14.15 Safari iOS: video tidak tampil atau pause gagal

- **Kemungkinan penyebab:** perilaku MJPEG/drawImage di WebKit; batas koneksi.
- **Diagnosis:** uji `/api/snapshot.jpg` langsung; lihat konsol Safari.
- **Perbaikan:** fallback pause memakai snapshot server (P9-01).

### 14.16 SSE terputus tiap beberapa menit

- **Kemungkinan penyebab:** proxy/WiFi memutus koneksi idle.
- **Diagnosis:** hitung interval putus; cek keep-alive.
- **Perbaikan:** perpendek interval keep-alive; periksa header anti-buffering.

### 14.17 Suhu tinggi

- **Penyebab:** beban AI + encode; tanpa heatsink; catu daya lemah.
- **Perbaikan:** heatsink/kipas; kurangi fps stream; matikan monitor saat tidak perlu.

### 14.18 Build gagal setelah menambah fitur

- **Penyebab:** versi dependensi bentrok; fitur tidak disetel; target salah.
- **Diagnosis:** `cargo tree -d`; periksa `--features`.
- **Perbaikan:** samakan versi; perbaiki `cfg`.

### 14.19 Nama muncul padahal harus disamarkan

- **Penyebab:** `show_names` tidak diterapkan di salah satu jalur (log, error, pop-up).
- **Diagnosis:** rekam trafik; cari string nama.
- **Perbaikan:** terapkan masking di titik serialisasi (P3-04, P10-07).

### 14.20 Snapshot kosong atau rusak

- **Penyebab:** frame terpotong; passthrough tanpa validasi.
- **Perbaikan:** validasi SOI/EOI (P2-04).

---

## 15. Register Risiko dan Log Bug

### 15.1 Register Risiko

Skala: Kemungkinan (K) dan Dampak (D): 1 rendah – 3 tinggi. Skor = K × D.

| ID | Risiko | K | D | Skor | Mitigasi | Pemilik |
| -- | ------ | - | - | ---- | -------- | ------- |
| R01 | Repositori tidak berisi kode sehingga asumsi salah | 3 | 3 | 9 | P0-01..P0-03 sebelum implementasi | |
| R02 | Encode JPEG membebani CPU (kamera non-MJPG) | 2 | 3 | 6 | passthrough bila bisa; encoder lazy; fps rendah | |
| R03 | Kebocoran memori pada stream klien lambat | 2 | 3 | 6 | P2-08, uji ketahanan | |
| R04 | Monitor menurunkan FPS AI | 2 | 3 | 6 | prioritas rendah, shed load, uji regresi | |
| R05 | Kebocoran data pribadi lewat LAN | 2 | 3 | 6 | token, CIDR, masking, keputusan privasi | |
| R06 | Safari iOS berperilaku berbeda (pause/MJPEG) | 3 | 2 | 6 | fallback snapshot, uji perangkat nyata | |
| R07 | Wi-Fi lemah membuat video tersendat | 3 | 1 | 3 | parameter fps/w; kabel | |
| R08 | Perubahan matcher (top-2) mengubah keputusan | 1 | 3 | 3 | uji regresi P12-08 | |
| R09 | Selisih sinkron overlay mengganggu kalibrasi | 2 | 2 | 4 | TTL, bekukan saat pause, evaluasi S2 | |
| R10 | Ukuran HTML membengkak | 2 | 1 | 2 | P5-05 batas ukuran | |
| R11 | Guard klien bocor sehingga pengguna terkunci | 2 | 2 | 4 | uji drop path, timeout | |
| R12 | Throttling termal | 2 | 2 | 4 | pendingin, uji 1 jam | |
| R13 | Dependensi baru menambah waktu build/ukuran | 2 | 1 | 2 | P0-07 | |
| R14 | Token statis bocor | 2 | 2 | 4 | rotasi, cookie HttpOnly, tidak di URL | |
| R15 | Jam perangkat salah mengganggu timestamp/sinkron | 2 | 2 | 4 | NTP | |
| R16 | Estimasi terlalu optimis | 3 | 1 | 3 | buffer 20–30% | |
| R17 | Fitur dipakai sebagai kanal produksi tanpa pengamanan | 2 | 3 | 6 | dokumentasikan sebagai alat debug; token wajib di produksi | |
| R18 | Perbedaan versi axum membuat sketsa kode tidak cocok | 3 | 1 | 3 | P0-02 | |
| R19 | Banyak tab terbuka menghabiskan batas klien | 3 | 1 | 3 | TTL klien, pesan jelas | |
| R20 | Log berisi data pribadi | 2 | 2 | 4 | audit log; tanpa nama di log monitor | |

### 15.2 Template Log Bug

| ID | Tanggal | Lingkungan | Langkah reproduksi | Harapan | Aktual | Tingkat | Status | Tautan |
| -- | ------- | ---------- | ------------------ | ------- | ------ | ------- | ------ | ------ |
| B001 | | | | | | | | |
| B002 | | | | | | | | |
| B003 | | | | | | | | |
| B004 | | | | | | | | |
| B005 | | | | | | | | |

Tingkat: **Blocker** (inti terganggu/data bocor), **Mayor** (fitur utama rusak), **Minor** (kosmetik/alternatif ada).

---

## 16. Ide Lanjutan (Di Luar Scope Awal)

Diprioritaskan setelah rilis pertama dan retrospektif (P14-06).

| ID | Ide | Manfaat | Biaya | Catatan |
| -- | --- | ------- | ----- | ------- |
| I01 | Sinkron per frame (S2) | kotak tepat di frame | sedang | lihat P7-12 |
| I02 | Mengubah ambang pencocokan dari UI | kalibrasi cepat | sedang | butuh autentikasi kuat dan audit |
| I03 | Ekspor CSV skor genuine/impostor | analisis akurasi offline | rendah | tanpa nama atau dengan masking |
| I04 | Histogram skor langsung | visual kalibrasi | rendah | canvas 2D ringan |
| I05 | Riwayat metrik 24 jam di perangkat | tren | sedang | ring buffer di RAM, kecil |
| I06 | Endpoint Prometheus `/metrics` | integrasi pemantauan | rendah | teks sederhana |
| I07 | Notifikasi outbox menumpuk | operasi | rendah | webhook |
| I08 | Mode hemat (tanpa video, status saja) | sangat ringan | rendah | parameter `?video=0` |
| I09 | Tampilan multi-kamera | skala | tinggi | desain ulang FrameHub |
| I10 | Pendaftaran wajah lewat UI | kemudahan | tinggi | masalah privasi dan keamanan besar |
| I11 | HTTPS dengan sertifikat lokal | enkripsi LAN | sedang | manajemen sertifikat |
| I12 | Perekaman klip pendek saat kejadian | bukti | sedang | penyimpanan SD terbatas |

---

## 17. Definisi Selesai dan Daftar Periksa Rilis

### 17.1 Definition of Done (per tugas)

Sebuah tugas dianggap selesai bila semua poin berikut benar:

- [ ] Semua AC pada tugas tercentang.
- [ ] Kode lolos `cargo fmt --check` dan `cargo clippy` tanpa peringatan baru.
- [ ] Uji unit/integrasi terkait ditambahkan dan lulus.
- [ ] Tidak ada alokasi tambahan di jalur panas tanpa pembenaran.
- [ ] Dokumentasi yang terkait diperbarui.
- [ ] Peninjauan kode oleh minimal satu orang lain (atau tinjauan mandiri tertulis bila sendirian).
- [ ] Log pengerjaan diisi.

### 17.2 Definition of Done (per fase)

- [ ] Semua tugas fase selesai atau dibatalkan dengan alasan tertulis.
- [ ] Kriteria keluar fase terpenuhi.
- [ ] Pengukuran sumber daya relevan dijalankan dan dicatat.
- [ ] Tidak ada bug Blocker terbuka.

### 17.3 Daftar Periksa Rilis

**Fungsi**

- [ ] T01–T30 lulus di semua lingkungan E1–E6 (atau pengecualian tertulis).
- [ ] Status kamera, AI, template, outbox akurat dibanding sumber.
- [ ] Pop-up top-2 sesuai log matcher.
- [ ] Pause, resume, snapshot bekerja.

**Performa**

- [ ] Skenario M0–M7 dijalankan; hasil terhadap ambang 12.5 terdokumentasi.
- [ ] Uji ketahanan 24 jam lulus.
- [ ] Uji termal 1 jam lulus.
- [ ] Ukuran HTML ≤ 40 KB mentah, ≤ 12 KB gzip.

**Keamanan dan privasi**

- [ ] Token dan CIDR diuji.
- [ ] Masking nama diuji (T29).
- [ ] Tidak ada rahasia di repo.
- [ ] Model ancaman ditinjau.

**Operasional**

- [ ] Unit systemd terpasang dan hidup setelah reboot.
- [ ] Rollback dilatih.
- [ ] README dan panduan pemecahan masalah selesai.
- [ ] Operator menyelesaikan uji penerimaan.

**Penutup**

- [ ] Tag versi dibuat.
- [ ] Catatan rilis ditulis.
- [ ] Retrospektif dijadwalkan.

---

## Lampiran A. Glosarium

| Istilah | Arti |
| ------- | ---- |
| Edge | perangkat di lokasi (Orange Pi) tempat inferensi dijalankan |
| MJPEG | rangkaian gambar JPEG dikirim berurutan lewat HTTP |
| SSE | Server-Sent Events; aliran teks satu arah server ke browser |
| FrameHub | wadah frame terbaru yang dibagi ke banyak konsumen |
| DetectionBus | saluran siaran hasil deteksi per frame |
| Passthrough | mengirim byte JPEG dari kamera tanpa decode/encode ulang |
| Lazy encode | encode hanya bila ada klien yang membutuhkan |
| Latest-wins | konsumen lambat melewati data lama dan mengambil yang terbaru |
| Shed load | menurunkan beban opsional saat sistem kewalahan |
| Top-2 | dua kandidat teratas hasil pencocokan wajah |
| Margin | selisih skor kandidat pertama dan kedua |
| Threshold | ambang skor minimum untuk dianggap cocok |
| Outbox | antrean lokal presensi yang menunggu dikirim ke server |
| Template | representasi numerik wajah terdaftar untuk pencocokan |
| RSS | resident set size; memori fisik yang dipakai proses |
| TTL | masa berlaku data sebelum dianggap kedaluwarsa |
| AC | acceptance criteria |
| CIDR | notasi rentang alamat IP |
| ADR | architecture decision record |

## Lampiran B. Referensi Konfigurasi Lengkap

| Kunci | Tipe | Default | Deskripsi |
| ----- | ---- | ------- | --------- |
| `monitor.enabled` | bool | `true` | aktif/nonaktif seluruh monitor |
| `monitor.token` | string | `""` | token akses; kosong = tanpa token |
| `monitor.show_names` | bool | `true` | tampilkan nama di UI dan jaringan |
| `monitor.show_second_candidate` | bool | `true` | sertakan kandidat kedua |
| `monitor.max_stream_clients` | int | `2` | batas klien MJPEG |
| `monitor.max_sse_clients` | int | `4` | batas klien SSE |
| `monitor.stream_fps_default` | int | `8` | fps default stream |
| `monitor.stream_fps_max` | int | `15` | fps maksimum yang boleh diminta |
| `monitor.jpeg_quality` | int | `60` | kualitas bila perlu encode |
| `monitor.snapshot_rate_per_s` | int | `2` | batas snapshot per detik per IP |
| `monitor.login_rate_per_min` | int | `5` | batas login per menit per IP |
| `monitor.allow_cidrs` | array | LAN privat | rentang IP yang diizinkan |
| `monitor.ambiguous_margin` | float | `0.05` | margin minimum agar 'cocok' |
| `monitor.shed_ai_fps_threshold` | float | `5.0` | ambang FPS AI untuk shed load |
| `monitor.shed_window_s` | int | `10` | durasi di bawah ambang sebelum shed |
| `monitor.det_max_per_s` | int | `10` | batas event detections per klien |
| `monitor.status_interval_ms` | int | `1000` | interval event status |
| `monitor.detection_ttl_ms` | int | `300` | TTL kotak di UI |

Variabel lingkungan: `MONITOR_<KUNCI_HURUF_BESAR>` (titik diganti garis bawah).

## Lampiran C. Perintah Berguna

```bash
# Format kamera
v4l2-ctl --list-devices
v4l2-ctl -d /dev/video0 --list-formats-ext

# Cek status via curl
curl -s http://<ip>:5000/api/status | jq .
curl -N http://<ip>:5000/events
curl -o snap.jpg http://<ip>:5000/api/snapshot.jpg && file snap.jpg
curl -I http://<ip>:5000/

# Hitung part per detik pada stream
timeout 10 curl -s http://<ip>:5000/stream.mjpg | grep -a -c -- '--frame'

# Pantau proses
top -H -p $(pidof engine-server)
grep -E 'VmRSS|VmHWM' /proc/$(pidof engine-server)/status
cat /sys/class/thermal/thermal_zone0/temp

# Koneksi aktif
ss -tn state established '( sport = :5000 )'

# Layanan
systemctl status engine-server
journalctl -u engine-server -f
journalctl --disk-usage

# Uji klien lambat
curl --limit-rate 10k -s http://<ip>:5000/stream.mjpg > /dev/null
```

## Lampiran D. Skema Pemantauan Singkat untuk Operator

| Indikator | Normal | Perhatian | Tindakan |
| --------- | ------ | --------- | -------- |
| FPS kamera | ≥ 10 | 5–10 | cek kamera/kabel |
| FPS AI | ≥ 5 | 2–5 | cek beban/suhu |
| Pending outbox | 0–10 | 10–100 | cek jaringan ke server |
| Gagal outbox | 0 | > 0 | lihat `last_error` |
| Suhu | < 65 °C | 65–75 °C | pendingin/kurangi beban |
| Klien monitor | 0–2 | 3+ ditolak | tutup tab lama |
| RSS | stabil | naik terus | laporkan; restart terjadwal sementara |

Ambang di atas adalah titik awal. Sesuaikan setelah baseline P0-05 diketahui.

## Lampiran E. Catatan Perubahan Dokumen

| Versi | Tanggal | Perubahan |
| ----- | ------- | --------- |
| 1.0 | 2026-10-19 | Versi awal berdasarkan deskripsi tujuan; repositori belum berisi kode untuk diverifikasi |

---

_Akhir dokumen._