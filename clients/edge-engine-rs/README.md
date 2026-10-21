# Smart Absensi Edge Engine (Native Rust)

Production-grade, native Rust runtime for the Smart Absensi edge gateway terminal. Designed for embedded Linux SBCs (Orange Pi / Raspberry Pi) and industrial gate controllers.

---

## Architecture Overview

The codebase is organized into focused, decoupled modules adhering to the Senior Architecture guidelines:

```
clients/edge-engine-rs/
├── src/
│   ├── core/           # SQLite database, attendance outbox, data models, crypto
│   ├── vision/         # V4L2 camera capture, face detection, ArcFace/SFace, MiniFASNet
│   ├── hardware/       # RFID serial readers, Arduino bridge, GPIO, framebuffer display
│   ├── server/         # Axum HTTP MJPEG streamer, SSE vector events, and Control Panel
│   │   ├── auth.rs     # Session tokens, argon2 password hashing, rate limiting
│   │   ├── control/    # Decomposed web control panel submodules
│   │   │   ├── mod.rs      # Route registration & integration unit tests
│   │   │   ├── models.rs   # Settings structs, validation & atomic file persistence
│   │   │   ├── system.rs   # Linux /proc, sysfs, statvfs, network telemetry
│   │   │   └── handlers.rs # REST endpoints, HTML UI, settings, backup, update
│   │   └── mod.rs      # FrameHub distribution, MJPEG streaming & SSE hubs
│   ├── sync/           # Outbox synchronization worker & REST API client
│   ├── engine/         # High-level pipeline execution & CLI runner
│   │   ├── args.rs         # Command-line arguments & subcommands (clap)
│   │   ├── stream.rs       # Vision capture loop, inference, and hardware dispatch
│   │   ├── sync_runner.rs  # Outbox synchronization daemon runner
│   │   ├── tracker.rs      # Consecutive face tracking & spatial anti-spoofing
│   │   └── utils.rs        # Model path resolution & graceful shutdown handler
│   ├── lib.rs          # Library root exposing core modules
│   ├── main.rs         # Thin binary entrypoint for `engine-cli`
│   └── bin/
│       ├── smart_absensi_sync.rs # Thin binary entrypoint for `smart-absensi-sync`
│       └── serial_bench.rs       # Hardware serial throughput benchmark
├── scripts/
│   ├── package-onnxruntime.sh   # Linux packager & installer for libonnxruntime.so
│   ├── package-onnxruntime.ps1  # Windows packager for libonnxruntime.so
│   ├── build-aarch64.sh         # aarch64 cross-compilation (glibc 2.28)
│   └── build-aarch64.bat        # Windows cross-compilation driver
└── rust-toolchain.toml # Pinned Rust toolchain (1.91.0)
```

---

## ONNX Runtime Dynamic Linking (`ort load-dynamic`)

The engine uses `ort` with `load-dynamic`, avoiding static compilation bloat and allowing the same binary to load hardware-optimized ONNX Runtime shared libraries at runtime.

### Required Shared Library
- **Target Version:** `1.18.1` (`api-24`)
- **Library Name:** `libonnxruntime.so` (or `libonnxruntime.so.1.18.1`)

### Search Paths
The runtime searches for `libonnxruntime.so` in the following order:
1. Environment variable: `ORT_DYLIB_PATH`
2. CLI argument: `--ort-path <PATH>`
3. System library paths:
   - `/usr/local/lib/libonnxruntime.so`
   - `/usr/lib/libonnxruntime.so`
   - `/usr/lib/aarch64-linux-gnu/libonnxruntime.so`
   - `/opt/smart-absensi/edge-engine/models/libonnxruntime.so`
   - `./libonnxruntime.so`
4. Embedded application virtual environment paths

---

## Packaging ONNX Runtime Libraries

To download, verify, and package `libonnxruntime.so` for target architectures:

### Linux / macOS / WSL
```bash
# Package for all architectures (x86_64 and aarch64)
./scripts/package-onnxruntime.sh --arch all

# Package only for ARM64 (Orange Pi / Raspberry Pi)
./scripts/package-onnxruntime.sh --arch aarch64

# Directly install to /usr/local/lib on host target
sudo ./scripts/package-onnxruntime.sh --install
```

### Windows (PowerShell)
```powershell
.\scripts\package-onnxruntime.ps1 -Arch all
```

Output assets are placed into `dist/onnxruntime/<arch>/`:
- `libonnxruntime.so.1.18.1`
- `libonnxruntime.so` (symlink / copy)
- `SHA256SUMS`

---

## Building and Cross-Compiling

### Native Host Build
```bash
cargo build --release --bin engine-cli
```

### Cross-Compiling for aarch64 (Debian Buster / glibc 2.28)
```bash
# Linux / macOS host:
./scripts/build-aarch64.sh

# Windows host:
.\scripts\build-aarch64.bat
```

---

## Deploying to Orange Pi / Embedded Linux

1. **Copy binary and ONNX Runtime library:**
   ```bash
   scp target/aarch64-unknown-linux-gnu/release/engine-cli edge@${BOARD_IP}:/tmp/smart-absensi-sync
   scp dist/onnxruntime/aarch64/libonnxruntime.so.1.18.1 edge@${BOARD_IP}:/tmp/
   ```

2. **Install on target:**
   ```bash
   ssh edge@${BOARD_IP}
   sudo install -m 755 /tmp/smart-absensi-sync /usr/local/bin/smart-absensi-sync
   sudo install -m 755 /tmp/libonnxruntime.so.1.18.1 /usr/local/lib/libonnxruntime.so.1.18.1
   sudo ln -sf /usr/local/lib/libonnxruntime.so.1.18.1 /usr/local/lib/libonnxruntime.so
   sudo ldconfig
   ```

3. **Install and start systemd services:**
   ```bash
   sudo cp smart-absensi.service /etc/systemd/system/
   sudo cp smart-absensi-sync.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now smart-absensi-sync
   sudo systemctl enable --now smart-absensi
   ```
