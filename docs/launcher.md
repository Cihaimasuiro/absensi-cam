# Edge Launcher Spike Decision & Architecture

## 1. Context & Purpose
Tujuan spike ini adalah menguji estetika dan ergonomi antarmuka grafis native Rust (**egui** / **eframe**) untuk mengelola armada perangkat Orange Pi secara lokal, membandingkannya dengan pendekatan alternatif (web dashboard Blade atau Tauri + React).

- **Branch Eksperimen:** `feat/egui-launcher`
- **Kode Prototipe:** [`clients/launcher/src/main.rs`](../clients/launcher/src/main.rs)
- **Status Saat Ini:** **UI Mock Spike (Fase 1)** — data perangkat dan telemetri masih hardcoded untuk evaluasi visual.

---

## 2. Hasil Evaluasi Spike UI (egui)

| Parameter Evaluasi | Observasi Spike | Catatan Teknis |
| :--- | :--- | :--- |
| **Estetika Dark Mode** | Palet zinc/dark-surface (`#0F1014` & `#191B23`) | Kontras kartu ditingkatkan, padding tepi jendela 20px, aksen amber untuk Pending. |
| **Kerapatan (Density)** | Padding konsisten 20px/14px, tinggi kontrol 30px | Hirarki jelas: Pending di atas, Offline diredupkan, tombol Buka Stream (primer) vs Inspeksi (sekunder). |
| **Tipografi & Bahasa** | Font embedded (Inter, Geist Mono, Archivo) | Bahasa Indonesia konsisten, label `CPU: xx°C` dengan ambang warna, badge `USANG` untuk versi lama. |
| **Footprint & Build** | Binary mandiri, zero webview | Terukur via `Get-Process WorkingSet`: **53.49 MB** pada optimized release build menggunakan backend `glow` (turun dari 351 MB saat pakai wgpu/DX12). |

---

## 3. Catatan Keputusan (Decision Record)

Pilih salah satu status di bawah ini setelah pengujian langsung (`cargo run`):

- [ ] **Lanjut egui:** Estetika disetujui. Lanjutkan ke modularisasi `launcher-core` (klien API Laravel + discovery) dan `launcher-app` (UI).
- [ ] **Gugurkan / Kembali ke Web Dashboard:** Cukup gunakan dashboard Blade di browser.
- [ ] **Beralih ke Tauri + React:** Jika ingin fleksibilitas styling CSS/Tailwind murni.

---

## 4. Rencana Kerja Jika Lanjut (Fase 2)
1. **Refaktor Crate:** Pecah menjadi `clients/launcher/crates/launcher-core` (tanpa GUI) dan `launcher-app`.
2. **Dependensi Backend:** Menunggu implementasi M4 (Device-initiated pairing API di Laravel).
3. **Telemetri Nyata:** Integrasi pembacaan suhu CPU dari daemon Orange Pi (`/sys/class/thermal/thermal_zone0/temp`).
