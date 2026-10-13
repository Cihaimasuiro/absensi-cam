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
| **Estetika Dark Mode** | Palet zinc/dark-surface (`#121318` & `#1a1c24`) | Cukup bersih dan ringkas. |
| **Kerapatan (Density)** | Padding 12px, font 11–14px, filter bar | Mendekati gaya dashboard teknis ringkas (ArtCraft-like). |
| **Tipografi** | Font bawaan egui | Sedikit kaku/game-like jika belum menggunakan custom font TTF (misal: Inter). |
| **Footprint & Build** | Kompilasi instan (< 1s), binary mandiri | Zero webview dependency, resource memori < 35MB RSS. |

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
