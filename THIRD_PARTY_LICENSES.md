# Third-Party Licenses

This document lists the third-party dependencies and AI models used in this project, along with their licenses.

## Software Dependencies

### 1. Edge Engine (Rust - Production: `clients/edge-engine-rs`)

The production edge engine is written in native Rust and compiles to a single standalone binary without Python or AGPL code contamination.

| Component / Crate | Purpose | License | Source |
| :--- | :--- | :--- | :--- |
| `ort` (v2.0.0-rc.9) | ONNX Runtime Rust bindings for AI inference | MIT / Apache 2.0 | https://github.com/pykeio/ort |
| `tokio` (v1.x) | Asynchronous runtime | MIT | https://tokio.rs |
| `axum` (v0.8.x) | High-performance HTTP server for MJPEG stream & SSE overlays | MIT | https://github.com/tokio-rs/axum |
| `libsql` / `rusqlite` | SQLite WAL database engine for local outbox & template cache | MIT | https://github.com/tursodatabase/libsql |
| `rustfft` | High-performance FFT library for MiniFASNet frequency domain | MIT / Apache 2.0 | https://github.com/ejmahler/RustFFT |
| `libc` | Direct Linux V4L2 ioctl kernel bindings for webcam capture | MIT / Apache 2.0 | https://github.com/rust-lang/libc |
| `reqwest` | Asynchronous HTTP client for Laravel REST API sync | MIT / Apache 2.0 | https://github.com/seanmonstar/reqwest |
| `uuid` | RFC 4122 compliant UUID v4 generator for attendance records | MIT / Apache 2.0 | https://github.com/uuid-rs/uuid |
| `chrono` | Date and time handling (UTC ISO-8601 formatting) | MIT / Apache 2.0 | https://github.com/chronotope/chrono |
| `aes-gcm` | AES-256-GCM authenticated decryption for face templates | MIT / Apache 2.0 | https://github.com/RustCrypto/AEADs |

### 2. Edge Engine (Python - Reference / Legacy: `clients/edge-engine`)

| Component | Description | License | Source |
| :--- | :--- | :--- | :--- |
| `cryptography` | Cryptographic recipes and primitives for Python | Dual: Apache 2.0 or BSD | https://cryptography.io/ |
| `ByteTrack` | Multi-Object Tracking algorithm (adapted logic) | MIT License | https://github.com/ifzhang/ByteTrack |
| `OpenCV` | Computer Vision Library (`opencv-python-headless`) | Apache 2.0 | https://opencv.org/ |
| `NumPy` | Scientific Computing Library | BSD | https://numpy.org/ |

## AI Models

> [!WARNING]
> **Important Note on InsightFace Models:** The InsightFace published weights are strictly for **non-commercial research purposes only**. If this system is deployed commercially, you must train your own models, acquire a commercial license from InsightFace, or replace the models with permissively licensed alternatives (e.g., from MobileFaceNet community forks with Apache/MIT licenses).

| Model File | Purpose | Source / Architecture | License | Commercial Use? |
| :--- | :--- | :--- | :--- | :--- |
| `detector.onnx` | Face Detection | YuNet / SCRFD | MIT / Apache 2.0 (OpenCV Zoo) | Permissive / Check weights |
| `recognizer.onnx` | Face Recognition (512d) | ArcFace / SFace | Non-Commercial (InsightFace) / Apache 2.0 (SFace) | ⚠️ Check architecture |
| `liveness.onnx` | Liveness / Anti-Spoofing | Silent-Face-Anti-Spoofing (MiniFASNet) | Apache 2.0 / MIT | Permissive |

## Facenox & Clean-Room Architecture Status

The initial Python prototype was influenced by Facenox (AGPL-3.0). To resolve licensing risks:

1. **Production Rust Engine (`clients/edge-engine-rs`)**:
   - Developed as a **100% clean-room native Rust implementation**.
   - Directly interfaces with Linux kernel V4L2 ioctls, ONNX Runtime C API, and native SQLite.
   - Contains **zero lines of code, algorithms, or bindings from Facenox**, completely insulating production edge deployments from AGPL-3.0 copyleft obligations.
2. **Python Prototype (`clients/edge-engine`)**:
   - Maintained strictly as an offline reference and laboratory testbed.
   - `core/cipher.py` has been completely deleted.
   - Any production device rollout exclusively uses the compiled Rust binary (`smart-absensi-sync`).
