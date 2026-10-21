# Third-Party Licenses

This document lists the third-party dependencies and AI models used in this project, along with their licenses.

## Software Dependencies

### 1. Edge Engine (Rust - Production: `clients/edge-engine-rs`)

The production edge engine is written in native Rust. It compiles to an efficient native binary with dynamic ONNX Runtime bindings (`load-dynamic`), eliminating Python runtime dependencies while maintaining full compatibility with embedded ARM64/Linux SBCs.

| Component / Crate | Purpose | License | Source |
| :--- | :--- | :--- | :--- |
| `ort` (v2.0.0-rc.13) | ONNX Runtime Rust bindings for AI inference (`load-dynamic`, loads `libonnxruntime.so` / `onnxruntime.dll`) | MIT / Apache 2.0 | https://github.com/pykeio/ort |
| `tokio` (v1.x) | Asynchronous runtime | MIT | https://tokio.rs |
| `axum` (v0.7.x) | High-performance HTTP server for MJPEG stream & SSE overlays | MIT | https://github.com/tokio-rs/axum |
| `libsql` / `rusqlite` | SQLite WAL database engine for local outbox & template cache | MIT | https://github.com/tursodatabase/libsql |
| `rustfft` | High-performance FFT library for MiniFASNet frequency domain | MIT / Apache 2.0 | https://github.com/ejmahler/RustFFT |
| `libc` | Direct Linux V4L2 ioctl kernel bindings for webcam capture | MIT / Apache 2.0 | https://github.com/rust-lang/libc |
| `reqwest` | Asynchronous HTTP client for Laravel REST API sync | MIT / Apache 2.0 | https://github.com/seanmonstar/reqwest |
| `uuid` | RFC 4122 compliant UUID v4 generator for attendance records | MIT / Apache 2.0 | https://github.com/uuid-rs/uuid |
| `chrono` | Date and time handling (UTC ISO-8601 formatting) | MIT / Apache 2.0 | https://github.com/chronotope/chrono |
| `aes-gcm` | AES-256-GCM authenticated decryption for face templates | MIT / Apache 2.0 | https://github.com/RustCrypto/AEADs |

### 2. Edge Engine (Python - Reference / Legacy: `archive/edge-engine-py`)

| Component | Description | License | Source |
| :--- | :--- | :--- | :--- |
| `cryptography` | Cryptographic recipes and primitives for Python | Dual: Apache 2.0 or BSD | https://cryptography.io/ |
| `ByteTrack` | Multi-Object Tracking algorithm (adapted logic) | MIT License | https://github.com/ifzhang/ByteTrack |
| `OpenCV` | Computer Vision Library (`opencv-python-headless`) | Apache 2.0 | https://opencv.org/ |
| `NumPy` | Scientific Computing Library | BSD | https://numpy.org/ |

## AI Models

> [!WARNING]
> **Important Note on Face Recognition Model Licenses:**
> - **InsightFace (ArcFace / Glint360k / Buffalo):** Published model weights from InsightFace are strictly **non-commercial research only**.
> - **OpenCV SFace / MobileFaceNet:** Models from the official [OpenCV Zoo (SFace)](https://github.com/opencv/opencv_zoo/tree/master/models/face_recognition_sface) are licensed under **Apache 2.0** and are permissible for commercial use.
> - **Deployment Guidance:** For enterprise or commercial deployments, ensure `recognizer.onnx` points to an Apache 2.0 SFace/MobileFaceNet model or proprietary fine-tuned weights, rather than research-only InsightFace weights.

| Model File | Purpose | Source / Architecture | License | Commercial Use? |
| :--- | :--- | :--- | :--- | :--- |
| `detector.onnx` | Face Detection | YuNet (OpenCV Zoo) | Apache 2.0 | Permissive (Commercial OK) |
| `recognizer.onnx` | Face Recognition | SFace (OpenCV Zoo) or ArcFace | Apache 2.0 (SFace) / Non-Commercial (InsightFace) | Check weights in use |
| `liveness.onnx` | Liveness / Anti-Spoofing | Silent-Face-Anti-Spoofing (MiniFASNet) | Apache 2.0 / MIT | Permissive (Commercial OK) |

## Clean-Room Architecture Status

1. **Production Rust Engine (`clients/edge-engine-rs`)**:
   - Developed as a **100% clean-room native Rust implementation**.
   - Directly interfaces with Linux kernel V4L2 ioctls, ONNX Runtime C API, and native SQLite.
   - Contains **zero lines of code, algorithms, or bindings from Facenox**, completely insulating production edge deployments from AGPL-3.0 copyleft obligations.
2. **Python Prototype (`archive/edge-engine-py`)**:
   - Archived strictly as an offline reference and testbed.
   - Any production device rollout exclusively uses the compiled Rust binary (`smart-absensi-sync` / `engine-cli`).
