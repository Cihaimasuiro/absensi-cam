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

### FASE 0: Pisahkan Logika dari UI (Sedang Berjalan)
- Buat crate `clients/launcher-core` (tanpa dependensi UI atau grafis):
  - Tipe data: `EdgeDevice`, `DeviceStatus`, `FilterTab`
  - Trait `ApiClient` + implementasi `MockApiClient`
  - Pemformat waktu (`format_relative_time_id`)
  - Unit test
- `clients/launcher` (egui) hanya memanggil `launcher-core`.
- Bersihkan semua AI slop dan perbaiki bug visual.
- **Kriteria Lolos:** `cargo test` di `launcher-core` 100% pass, `clients/launcher` berjalan bersih.

### FASE 1: Spike "wry di dalam eframe" (Maks. 1 hari)
- Crate terisolasi `clients/launcher-webview-spike` (jangan sentuh `clients/launcher`).
- Jendela eframe + wry child webview yang memuat HTML lokal (`include_str!`).
- Pengujian & pencatatan:
  1. Resize, DPI scaling, minimize/maximize, fokus keyboard.
  2. Apakah dialog/modal egui tertutup webview.
  3. IPC 2-arah: JS memanggil Rust (`ipc_handler`), Rust memanggil JS (`evaluate_script`).
  4. Pengujian di Windows 10/11.
  5. Ukuran binary & memori release.
- **Kriteria Lolos:** Poin 1, 3, dan 4 berjalan di Windows tanpa flicker, dan dialog dibuat di HTML.
- **Keputusan:** Jika seluruh jendela adalah webview dan egui tidak memberi nilai tambah, lanjut ke Fase 2 tanpa egui.

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
