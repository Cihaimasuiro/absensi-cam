# Platform Support Matrix — Smart Absensi Edge Engine

Panduan resmi cakupan platform, kompatibilitas arsitektur perangkat keras (SBC & PC), sistem operasi, dan kapabilitas inferensi edge engine.

---

## 1. Tabel Kompatibilitas Platform (Three-Tier Strategy)

| Platform / Arsitektur | Kamera & Capture | AI Inference (ONNX) | GPIO & Relay | Tingkat Dukungan |
|---|---|---|---|---|
| **Linux aarch64** (Raspberry Pi 4/5, Orange Pi 3/5/Zero 3) | V4L2 Native (0% CPU) | ✅ ONNX Native | `/sys/class/gpio` | **Tier 1 — Full Production** |
| **Linux x86_64** (Mini PC, NUC, Industrial IPC) | V4L2 Native (0% CPU) | ✅ ONNX Native | `/sys/class/gpio` | **Tier 1 — Full Production** |
| **Windows x86_64** (PC Developer / Laptop) | **Native nokhwa (MF)** (Default) / Python fallback | ✅ ONNX Native | — | **Tier 1 — Full Production & UAT** |
| **macOS arm64 & x86_64** (M1-M4 Apple Silicon / Intel) | **Native nokhwa (AVF)** (Default) / Python fallback | ✅ ONNX (`.dylib`) | — | **Tier 1 — CI-Verified** |
| **Linux armv7 / armhf** (Orange Pi Lite 2, Pi 2, Pi Zero 2 32-bit OS) | V4L2 Native (68-byte struct) | ❌ Upstream Absent | `/sys/class/gpio` | **Tier 2 — Capture & RFID Only (`--no-inference`)** |
| **Windows ARM64** (Snapdragon X Elite / SQ1) | — | ❌ Upstream Absent | — | **Tier 3 — Explicitly Unsupported** |
| **Linux RISC-V 64** | — | ❌ Upstream Absent | — | **Tier 3 — Explicitly Unsupported** |

---

## 2. Rasional Arsitektur & Kebijakan Platform

### Mengapa Linux armv7 Berstatus *Capture-Only*?
Microsoft secara resmi **tidak menerbitkan** biner `libonnxruntime.so` untuk arsitektur 32-bit ARM Linux (`linux-arm32` / `armhf`). Ekosistem ML modern (PyTorch, TensorFlow, ONNX) telah beralih sepenuhnya ke arsitektur 64-bit.
- **Kondisi Perangkat Keras**: Banyak board populer seperti **Raspberry Pi Zero 2 W** dan **Orange Pi Lite 2** sesungguhnya memiliki prosesor 64-bit ARMv8 (Cortex-A53), tetapi sering dipasangi citra OS 32-bit (`armv7l`). **Menginstal OS 64-bit (Raspberry Pi OS 64-bit / Armbian aarch64) langsung meningkatkan status board ke Tier 1 dengan inferensi lokal penuh tanpa biaya tambahan.**
- **SBC Murni 32-bit (Pi 2, Cortex-A7/A8)**: Tetap didukung penuh pada level kernel untuk menangani perangkat keras: **V4L2 32-bit (struct 68 byte), capture MJPEG, pembaca kartu RFID, relay GPIO, dan streaming HTTP**. Jalankan dengan flag `--no-inference` agar bertindak sebagai terminal penangkap gambar & RFID yang meneruskan verifikasi ke server Laravel.

### Driver Kamera Desktop: Pure Rust Native (`nokhwa`) vs Python Fallback
- **Produksi Linux**: Menggunakan syscall libc V4L2 murni (`ioctl VIDIOC_DQBUF`) tanpa dependensi eksternal (0% subprocess overhead).
- **Windows & macOS (Default Pure Rust)**: Menggunakan driver native `nokhwa` (Media Foundation pada Windows, AVFoundation pada macOS). Raw RGB diteruskan langsung ke pipeline AI YuNet tanpa overhead decode JPEG berulang dan tanpa memerlukan instalasi Python/OpenCV eksternal.
- **Legacy Fallback**: Jika diperlukan, kompilasi tanpa default features (`cargo build --no-default-features`) tetap menyediakan fallback shim Python 3.8+ OpenCV (`cv2`). Interpreter dikelola via `uv`:
```bash
uv python install 3.11 && uv pip install --python "$(uv python find <=3.13)" opencv-python
```

---

## 3. Verifikasi Performa & Tolok Ukur Empiris

Edge Engine dilengkapi modul metrik internal. Untuk menguji latensi dan efisiensi penghematan inferensi di hardware produksi, jalankan:

```bash
EDGE_VISION_METRICS=1 ./engine-cli stream --device /dev/video0 --fps-cap 15
```

Setiap 60 detik, mesin akan mengeluarkan rollup metrik:
```text
[VISION_METRICS_1M] window=60s frames=900 faces=12 | Luma avg=112.4 min=34.0 max=180.0 | Inference[run=145 skipped=755 (83.9% saved) avg_ms=28.40]
```

- **`run` vs `skipped`**: Mengindikasikan seberapa banyak komputasi inferensi YuNet dihemat oleh *motion gate*.
- **`avg_ms`**: Mengukur durasi aktual inferensi neural network pada inti CPU perangkat keras Anda.
