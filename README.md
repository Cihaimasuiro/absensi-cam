<a id="readme-top"></a>

<h1 align="center">
  Absensi-Cam
</h1>

<p align="center">
  <strong>An Edge-First, Real-Time Face Recognition Attendance System</strong><br>
  Built for constrained hardware (Orange Pi) with a powerful Laravel centralized backend.
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#architecture">Architecture</a> •
  <a href="#hardware-requirements">Hardware</a> •
  <a href="#quickstart">Quickstart</a> •
  <a href="#development-guidelines">Guidelines</a>
</p>

---

> [!NOTE]  
> **Offline-First by Design:** Absensi-Cam processes face detection, tracking, and template matching locally on the edge device. Attendance records are synced securely to the Laravel backend. If the internet drops, attendance continues to be recorded locally and will automatically sync when connectivity is restored.

## Why Absensi-Cam?

Most face recognition attendance systems rely on cloud inference, which requires constant high-bandwidth internet and introduces latency. Absensi-Cam runs inference directly on affordable Single Board Computers (SBCs).

| Feature                 | Absensi-Cam (Edge-First) | Cloud-Based Systems |
| :---------------------- | :----------------------: | :-----------------: |
| **Inference Location**  | Local Edge Device (ONNX) | Remote Cloud        |
| **Internet Dependency** | Optional (Sync only)     | Mandatory           |
| **Latency**             | Real-time (< 50ms)       | Network Dependent   |
| **Hardware Cost**       | Ultra-Low (Orange Pi)    | High (Servers/GPUs) |

## Core Features

- **On-Device AI Inference:** Real-time face detection (YuNet) and recognition (ArcFace/SFace) running entirely on local ARM hardware using `onnxruntime` via native Rust (`clients/edge-engine-rs`) or Python (`face_core`).
- **Biometric Security:** Face embeddings are strictly encrypted at rest in the database using AES-256-GCM. Keys are securely rotated and distributed to paired edge devices.
- **Offline-First Syncing:** SQLite-backed outbox daemon (`smart-absensi-sync`) ensures attendance records with UUID v4 and UTC ISO-8601 timestamps are synced reliably and never lost during network outages.
- **Over-The-Air (OTA) Updates:** Deploy new face recognition `.onnx` models and engine binary updates directly from the Laravel dashboard to fleets of edge devices.
- **Latent Space Versioning:** Strict `MODEL_VERSION` tagging ensures biometric vectors from different model architectures are never incorrectly compared.
- **Dynamic Device Pairing:** Securely pair edge devices to the backend using pairing codes and encrypted `.env` provisioning.
- **Automated Rollbacks:** Edge engine utilizes `systemctl` crash-loop detection to automatically rollback bad OTA updates.
- **Liveness & Anti-Spoofing:** Mini-FASNet passive anti-spoofing with FFT frequency analysis, 3-frame temporal confirmation, spatial spoof veto, and real-time margin logging. Effectively defeats photo and smartphone screen replay attacks without extra IR sensors.
- **Attendance Cooldown & Deduplication:** Configurable cooldown (default 5 minutes) with persistent restoration from SQLite upon engine restart to prevent duplicate scans.

## Architecture

The project consists of three primary components:

### 1. The Backend (Laravel 13)
A Modular Monolith built with Laravel, responsible for:
- Managing Students, Employees, Classrooms, and Devices.
- Generating Face Embeddings securely via a background Python worker during enrollment.
- Providing the REST API for Edge Devices to sync attendance batches idempotently (`/api/v1/attendance/batch`).
- Broadcasting OTA update triggers and config synchronizations.

### 2. The Frontend (Blade + Tailwind + Alpine.js)
A modern, fast, and responsive administration dashboard:
- **UI Framework:** Tailwind CSS for utility-first styling.
- **Interactivity:** Alpine.js for lightweight, JS-driven state management (e.g., zero-FOUC collapsible mini-sidebar).
- **Design System:** Built upon modern Admin Dashboard aesthetics, seamlessly integrated directly into Laravel Blade.
- **Icons:** Lucide Icons for clean and consistent iconography.

### 3. The Production Edge Engine (Native Rust — `clients/edge-engine-rs`)
A high-performance, single-binary application compiled for ARM Linux (Orange Pi Lite 2):
- **Runtime:** Native Rust (compiled via `cargo zigbuild` for `aarch64-unknown-linux-gnu.2.28`).
- **Video Capture:** Pure libc V4L2 zero-copy YUYV camera capture at ~30 FPS.
- **Decoupled AI Pipeline:** YuNet detection, MiniFASNet liveness, and ArcFace/SFace feature extraction running in a dedicated thread with adaptive thermal pacing (300ms idle sleep, 50ms active sleep).
- **Streaming & Telemetry:** `axum`-based MJPEG stream and SSE (Server-Sent Events) live vector canvas overlay.
- **Outbox Sync Daemon:** Independent systemd daemon (`smart-absensi-sync.service`) continuously draining local SQLite attendance records to the Laravel backend.
- **Hardware Footprint:** **10–25% CPU standby** (~50% during web view), CPU temperatures stable at **~68°C** (down from 80.2°C thermal throttling).

### 4. The Reference Edge Engine (Python — `clients/edge-engine`)
Maintained for laboratory prototyping, offline benchmarking, and tooling.

## Hardware Requirements

The Edge Engine is heavily optimized for resource-constrained Single Board Computers (SBCs).

- **Target Device:** Orange Pi Lite 2 (ARM Cortex-A53)
- **RAM:** 1 GB (Engine optimized to run in < 150MB RSS)
- **Camera:** Standard USB UVC Webcams (MJPEG format preferred for lower CPU overhead).
- **Cooling:** Passive heatsink recommended; engine monitors thermal zones and reports degradation to the backend.

## Quickstart

### 1. Backend (Laravel)
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

**Production Queue Worker (Supervisor):**
Create `/etc/supervisor/conf.d/absensi-cam-worker.conf`:
```ini
[program:absensi-cam-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/absensi-cam/artisan queue:work --queue=enrollments,default --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/absensi-cam/storage/logs/worker.log
```

**Production Cron (Scheduler):**
Add to your server's crontab (`crontab -e`):
```bash
* * * * * cd /path/to/absensi-cam && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Edge Engine — setup venv (sekali saja)
```bash
cd clients/edge-engine
python -m venv venv

# Windows
venv\Scripts\pip install -r requirements.txt

# Linux / macOS
venv/bin/pip install -r requirements.txt
```

### 3. Pairing Edge ke Backend (sekali saja, sebelum `dev:edge`)

> [!IMPORTANT]
> Tanpa token perangkat, edge tidak bisa sync ke Laravel.

1. Buka admin Laravel → **Devices** → buat **Pairing Code** baru.
2. Salin kode yang muncul (contoh: `AB12CD`).
3. Jalankan `python pairing.py` di terminal edge → masukkan URL backend dan kode pairing sesuai instruksi.
4. File `.env` di dalam `clients/edge-engine/` akan terisi otomatis (`SMART_ABSENSI_TOKEN`, `SMART_ABSENSI_DEVICE_ID`).

### 4. Menjalankan Development

```bash
# Semua sekaligus (Laravel + Vite + Edge Engine)
npm run dev

# Hanya web (Laravel + Vite) — tanpa edge
npm run dev:web

# Hanya Edge Engine — tanpa web
npm run dev:edge
```

> [!NOTE]
> `npm run dev` tidak menggunakan `--kill-others`. Edge Engine tetap berjalan walau Laravel mati, sesuai prinsip offline-first.

### 5. Production Deployment (Rust Edge Engine)
Di Orange Pi (production), compile dan pasang binary Rust mandiri:

```bash
# 1. Cross-compile di host (Windows/Linux) ke target aarch64
cargo zigbuild --target aarch64-unknown-linux-gnu.2.28 --release -p engine-cli

# 2. Salin binary ke Orange Pi (/usr/local/bin/smart-absensi-sync)
# Atau unduh dari local deployment server:
curl -fsSL http://<HOST_IP>:8888/engine-cli -o /tmp/smart-absensi-sync
sudo install -m 755 /tmp/smart-absensi-sync /usr/local/bin/smart-absensi-sync

# 3. Kelola service systemd
sudo systemctl restart smart-absensi.service smart-absensi-sync.service
sudo systemctl status smart-absensi.service smart-absensi-sync.service
```

Untuk implementasi Python legacy/reference:
```bash
cd clients/edge-engine
sudo ./install.sh
```

## Database Requirements & Audit Logging

The `activity_log` table is protected by **append-only SQL triggers** (`prevent_activity_log_update` and `prevent_activity_log_delete`) to guarantee tamper-proof audit trails for administrative and attendance actions. 

When deploying or migrating:
- **Binary Logging:** If MySQL binary logging is enabled on your production server, creating these triggers requires the `SUPER` privilege OR you must set `log_bin_trust_function_creators = 1` in your MySQL configuration.
- **Log Cleanup:** Because deletions are blocked at the database level, standard Laravel commands like `php artisan activitylog:clean` will **fail** with a PDOException. Archiving or truncating the log table requires dropping the triggers first, performing the cleanup as a database administrator, and recreating them.
- **Application User Grants:** For true immutability, ensure the MySQL user configured in your `.env` (used by the Laravel application) is **revoked** of `UPDATE` and `DELETE` privileges on the `activity_log` table, complementing the trigger protections.

## Classroom Timings & Tolerance
In version 2.3+, the old `late_threshold_enabled` boolean has been replaced by a single `late_tolerance_minutes` integer. Any classroom previously configured with "late tracking off" (`late_threshold_enabled = false`) has been migrated to the maximum tolerance of `120` minutes. Consequently, DTR will now flag arrivals later than 2 hours as "Late" where previously they were never flagged. Additionally, Edge devices receive timings per group under a `groups` JSON array on the `/api/v1/config` endpoint instead of flat values.

## Development Guidelines

This repository enforces strict, enterprise-grade engineering standards for both AI coding agents and human developers. 

Before contributing, you **must** read:
1. **[AGENTS.md](AGENTS.md):** Laravel Enterprise Architecture Guidelines (Modular Monolith, Thin Controllers, Query Builders, DTOs, Batch Sync Contracts).
2. **[AGENTS2.md](docs/AGENTS2.md):** Edge Engine Engineering Guidelines (Native Rust `clients/edge-engine-rs` standards, Memory Safety, Decoupled AI Pacing, Python Reference PEP 8).
3. **[PRD.md](docs/PRD.md):** Product Requirement Document (Monorepo Specs v2.5.0).
4. **[DESIGN.md](docs/DESIGN.md):** Design Language & Token Specifications.

> [!WARNING]
> We practice **Zero Over-Engineering**. Do not introduce speculative abstractions, unnecessary wrapper classes, microservices, or unneeded dependencies unless explicitly required and measured.

## License

This project is licensed for internal use. See the `LICENSE` file for details.
