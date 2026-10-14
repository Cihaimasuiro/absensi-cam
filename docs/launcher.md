# Edge Launcher Spike Decision & Architecture

## 1. Context & Purpose
Tujuan spike ini adalah menguji estetika dan ergonomi antarmuka grafis native Rust untuk mengelola armada perangkat Orange Pi secara lokal, membandingkannya dengan pendekatan alternatif (web dashboard Blade atau Tauri/wry).

- **Branch Eksperimen:** `feat/egui-launcher`
- **Kode Prototipe:** [`clients/launcher/src/main.rs`](../clients/launcher/src/main.rs)
- **Status Saat Ini:** **Fase 0 (Pemisahan Core & Pembersihan Slop UI)**

---

## 2. Review Kritis & Masalah UI Spike (egui)

### Bug Visual
1. **Glyph Kotak (□):** Tombol `⋯` dan emoji `📋✏🗑` tidak ada di glyph embedded font, tampil sebagai kotak kosong. Solusi: Gunakan teks ASCII standar (`Opsi` / `...` dan teks menu tanpa emoji).
2. **Tabrakan Teks Progress Bar:** Teks `108/254 IP` menimpa progress bar dan terpotong.
3. **Inkonsistensi Footer:** Status `Server: Terputus` dan notifikasi hijau muncul bahkan di state Normal.
4. **Distingsi Kosong:** Filter `Offline` yang tidak menemukan hasil menampilkan pesan "Tidak ada perangkat terdeteksi di LAN", padahal armada ada. Pesan "armada kosong" dan "filter tidak cocok" harus dibedakan.
5. **Perataan Teks:** Panduan lapangan rata kiri sementara judul di tengah.

### AI Slop & Kompleksitas Berlebih
- Toolbar `Simulasi Keadaan` bercampur dengan antarmuka produk (harus dibatasi di balik feature flag `dev-states` atau dihapus).
- Terlalu banyak dekorasi tanpa fungsi (`// PELUNCUR ARMADA`, `[ ∅ ]`, label kapital mono berlebihan).
- 5 state kegagalan dibuat sebelum ada satu pun fungsi nyata.

### Catatan Arsitektur (Device-Initiated Pairing)
Dengan pairing yang dimulai dari perangkat (Orange Pi menghubungi Laravel sendiri saat boot), perangkat langsung muncul di tabel "Menunggu Persetujuan" pada web admin Laravel. Kebutuhan scan LAN di aplikasi desktop berkurang drastis. Sebuah halaman Blade di Laravel mungkin sudah cukup. Namun jika aplikasi desktop tetap dibutuhkan, ikuti roadmap bertahap di bawah ini.

---

## 3. Rencana Bertahap: UI Web (HTML/CSS) di Launcher Desktop

Prinsip: Logika bisnis dan klien API **tidak boleh bergantung** pada pilihan shell UI.

### FASE 0: Pisahkan Logika dari UI (Selesai & Disempurnakan)
- Buat crate `clients/launcher-core` (tanpa dependensi UI atau grafis):
  - Tipe data: `EdgeDevice`, `DeviceStatus`, `FilterTab`
  - Trait `ApiClient` + implementasi `MockApiClient`
  - Pemformat waktu (`format_relative_time_id`)
  - 6 unit test otomatis (100% lulus via `cargo test`)
- `clients/launcher` (egui) murni memanggil `launcher-core`.
- Pembersihan & Koreksi UX:
  1. URL stream diubah ke HTTP MJPEG port 5000: `http://{ip}:5000/stream` (bukan RTSP).
  2. Notifikasi footer hilang otomatis dalam ~4 detik via timer `Instant::now()` dan repaint reaktif.
  3. Status pemindaian konsisten: tombol "Memindai..." dan notifikasi tidak kontradiktif, jumlah perangkat di footer sinkron dengan daftar aktif.
  4. Penulisan waktu diseragamkan: `"Dipindai baru saja"`.
  5. Tombol "Opsi" diselaraskan menjadi outline dengan margin pembatas dan menu pop-up terisolasi.
  6. **TODO (Pengaturan URL Server):** Tambahkan kembali modal/dialog pengaturan URL target server Laravel saat mode dinamis diintegrasikan pada Fase 4.
- **Kriteria Lolos:** `cargo test` di `launcher-core` 100% pass, `cargo build --release` di `launcher` 0 warning.

### FASE 1: Spike "wry di dalam eframe" (Selesai & Terukur)
- Crate terisolasi: [`clients/launcher-webview-spike`](../clients/launcher-webview-spike)
- Arsitektur: Jendela `eframe` (backend `glow`) memuat child window `wry::WebView` yang mengeksekusi HTML/CSS/JS lokal (`include_str!`).
- IPC 2-arah: `window.ipc.postMessage` (JSON tervalidasi via serde di Rust) dan `webview.evaluate_script` untuk pembaruan reaktif.

#### Tabel Hasil Pengujian Empiris (Butir a sampai i)

| Butir Pengujian | Status | Hasil Pengukuran / Catatan Teknis |
| :--- | :---: | :--- |
| **a. Resize, Minimize, Maximize** | **Catatan** | Webview mengikuti perubahan ukuran jendela via `set_bounds`. Namun karena WebView2 adalah child HWND terpisah, terjadi *asynchronous lag* (flicker hitam sesaat pada batas tepi) saat jendela ditarik cepat secara manual di Windows. |
| **b. DPI Scaling (100% vs 150%)** | **Lulus** | Posisi dan dimensi webview dikonversi ke `LogicalPosition` dan `LogicalSize`. WebView2 menangani rendering resolusi tinggi secara internal. |
| **c. Fokus Keyboard** | **Catatan** | Mengetik di dalam kolom input HTML dan Tab antar-elemen HTML berjalan normal. Namun, fokus terjebak di child HWND: tombol Tab tidak bisa berpindah keluar dari webview kembali ke elemen egui tanpa klik mouse manual. |
| **d. Tumpang Tindih (Airspace / Overlap)** | **GAGAL** | **Popup / dropdown menu egui tertutup oleh webview.** Child window Win32 (`HWND`) WebView2 berada di atas lapisan gambar OpenGL egui. Elemen egui di atas area webview tidak terlihat (masalah klasik *Airspace* Win32). |
| **e. IPC Dua Arah** | **Lulus** | Perintah `list_devices`, `approve`, dan `reject` berhasil memicu mutasi di `MockApiClient` dan memperbarui tampilan HTML tanpa reload. Perintah di luar whitelist JSON ditolak dan dicatat di stderr. |
| **f. Memori (Working Set)** | **Catatan** | • Host process saja: **105.51 MB**<br>• Total (Host + 6 proses anak `msedgewebview2.exe`): **~385 MB**.<br>*(Sebagai perbandingan, `launcher-egui` pure Rust hanya memakan **~96 MB** dalam 1 proses tunggal tanpa proses anak).* |
| **g. Ukuran Binary Release** | **Lulus** | **20,444,828 bytes** (**~19.50 MB**). Selisih hanya +63 KB dibandingkan launcher egui (**19.44 MB**). |
| **h. Prasyarat Runtime** | **Wajib** | Membutuhkan **Microsoft Edge WebView2 Runtime**. Sudah terpasang bawaan di Windows 11 & Windows 10 modern. Pada Windows 10 LTSC / sistem offline tanpa WebView2, inisialisasi akan gagal (error panic pada creation). |
| **i. Lingkungan Linux** | **Tidak Diuji** | Lingkungan pengujian saat ini adalah Windows. Secara arsitektur, `build_as_child` wry di Linux bergantung pada X11 plug/socket dan tidak mendukung Wayland native. |

#### Evaluasi Arsitektur: Apakah egui Masih Berguna Jika Seluruh Isi Adalah HTML?
**Jawaban Jujur:** **TIDAK.**
Jika seluruh antarmuka aplikasi dibangun dengan HTML/CSS, keberadaan `egui` justru menjadi beban ganda:
1. **Masalah Airspace:** Menu, dialog modal, dan tooltip egui tidak bisa digambar di atas webview (tertutup oleh HWND WebView2). Akibatnya, semua dialog dan menu tetap harus ditulis di HTML.
2. **Duplikasi Overhead:** Aplikasi harus menjalankan runtime OpenGL/egui sekaligus runtime Chromium WebView2, menghabiskan ~385 MB RAM.
3. **Pilihan Lebih Masuk Akal:**
   - **Jika Ingin Web UI Desktop:** Gunakan **`Tauri`** atau **`tao` + `wry` langsung** (tanpa egui). Menghilangkan lapisan OpenGL yang mubazir, perataan jendela native rapi tanpa lag resize, dan footprint lebih bersih.
   - **Jika Ingin Efisiensi Memori & Single Binary:** Tetap pada **`egui` murni** (Fase 0) yang hanya memakan ~53–96 MB RAM, tanpa ketergantungan runtime browser eksternal.
   - **Jika Ingin Nol Distribusi Desktop:** Gunakan **Halaman Admin Blade Laravel** (terhubung langsung ke database tanpa aplikasi klien desktop sama sekali).

### FASE 2: Pilih Shell Final
- **Opsi A:** `tao` + `wry` langsung (paling ringan, kontrol native penuh).
- **Opsi B:** `Tauri` (ekosistem matang, installer, bundler siap pakai).
- **Opsi C:** Web Dashboard Blade di Laravel (paling sedikit kode, zero distribusi desktop).

### FASE 3: UI Implementation
- Frontend bersih di `clients/launcher/ui/` (HTML/CSS/vanilla JS).
- Tanpa dekorasi berlebihan, high-density, ramah teknisi.

### FASE 4: Data Nyata & Integrasi
- Hubungkan `launcher-core` ke endpoint pairing Laravel (M4).
- Token teknisi disimpan di OS Keyring, bukan plaintext.

---

## 4. Aturan Disiplin AI Agent
1. **Zero Unmeasured Claims:** Dilarang mengklaim metrik performa tanpa angka hasil pengukuran nyata.
2. **Disiplin Fase:** Dilarang memulai fase berikutnya sebelum kriteria fase aktif terpenuhi.
3. **YAGNI:** Dilarang menambah state, fitur spekulatif, atau dekorasi di luar tugas yang sedang diminta.
