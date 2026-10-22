# Server Face Extraction Deployment Guide

This document describes how to deploy and configure the native Rust face extractor (`face-extract`) on the Laravel application server for face enrollment, bulk photo enrollment, and biometric re-enrollment.

---

## 1. Overview

Face extraction runs **exclusively on the Laravel server** (never on edge devices) whenever an administrator registers a member with a photo or runs `app:re-enroll-from-photos`.

- **Binary**: Standalone Rust executable `face-extract` (built from `clients/edge-engine-rs/src/bin/face_extract.rs`).
- **Models**: ONNX models in root `models/` directory:
  - `detector.onnx` (YuNet face detector)
  - `recognizer.onnx` (MobileFaceNet / SFace recognizer, 512-d normalized float32 vector)
- **Runtime**: ONNX Runtime C API library (`libonnxruntime.so` on Linux / `onnxruntime.dll` on Windows).
- **Communication Contract**: Standard subprocess execution via Laravel `Process`:
  - Input: `--image <path> --models-dir <path> [--ort-path <path>]`
  - Output stdout: `{"success":true,"embedding_b64":"..."}` (2048 little-endian bytes = 512 × f32)
  - Output stderr: `{"error":"<message>"}`

---

## 2. Compilation

Compile the standalone server binary using Cargo:

```bash
cd clients/edge-engine-rs
cargo build --release --bin face-extract
```

The resulting binary will be located at:
- Linux: `clients/edge-engine-rs/target/release/face-extract`
- Windows: `clients/edge-engine-rs/target/release/face-extract.exe`

*(Note: The edge device sync binary `smart-absensi-sync` is built separately and remains completely independent).*

---

## 3. ONNX Runtime Installation

The `face-extract` binary dynamically links to the ONNX Runtime library.

### Linux (Server)

1. Download the official ONNX Runtime release (v1.17.0+ recommended):
   ```bash
   curl -L -O https://github.com/microsoft/onnxruntime/releases/download/v1.17.3/onnxruntime-linux-x64-1.17.3.tgz
   tar -xzf onnxruntime-linux-x64-1.17.3.tgz
   sudo cp onnxruntime-linux-x64-1.17.3/lib/libonnxruntime.so* /usr/local/lib/
   sudo ldconfig
   ```

2. Alternatively, specify the path in `.env`:
   ```dotenv
   ORT_DYLIB_PATH=/path/to/libonnxruntime.so
   ```

### Windows (Local Development)

Download `onnxruntime-win-x64-*.zip` and place `onnxruntime.dll` in your system `PATH` or specify `ORT_DYLIB_PATH` in `.env`.

---

## 4. Environment Configuration (`.env`)

Add the following variables to your `.env` file on the Laravel server:

```dotenv
# Path to the compiled face-extract executable (optional, defaults to target/release/face-extract)
FACE_EXTRACT_BIN=/var/www/absensi-cam/clients/edge-engine-rs/target/release/face-extract

# Path to directory containing detector.onnx and recognizer.onnx (optional, defaults to root models/)
FACE_MODELS_DIR=/var/www/absensi-cam/models

# Optional explicit path to libonnxruntime.so (omit if in standard system library path)
ORT_DYLIB_PATH=/usr/local/lib/libonnxruntime.so

# 32-byte AES-256-GCM key used to encrypt face embeddings at rest
ENROLLMENT_EMBED_KEY=base64:your_32_byte_key_here
```

---

## 5. Verification & Testing

Verify that extraction operates correctly:

```bash
# 1. Direct CLI verification
./clients/edge-engine-rs/target/release/face-extract \
  --image /path/to/sample_face.jpg \
  --models-dir models/

# Expected stdout:
# {"success":true,"embedding_b64":"..."}

# 2. PHP Unit test verification
php artisan test tests/Unit/FaceExtractorTest.php
```
