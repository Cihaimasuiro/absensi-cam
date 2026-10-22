<div align="center">

# Absensi-Cam

### Edge-first, real-time face recognition attendance for constrained hardware.

**Runs inference on an Orange Pi. Syncs to Laravel when the network allows.**

[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Rust](https://img.shields.io/badge/Rust-1.91-000000?style=flat-square&logo=rust&logoColor=white)](https://www.rust-lang.org)
[![ONNX Runtime](https://img.shields.io/badge/ONNX_Runtime-edge_inference-005CED?style=flat-square&logo=onnx&logoColor=white)](https://onnxruntime.ai)
[![License](https://img.shields.io/badge/license-internal-f4c430?style=flat-square)](LICENSE)

[Features](#core-features) · [Architecture](#architecture) · [Hardware](#hardware-requirements) · [Quickstart](#quickstart) · [Guidelines](#development-guidelines) · [Documentation](#documentation)

<code>composer install && php artisan migrate --seed</code>

</div>

> [!IMPORTANT]
> Face embeddings are biometric data. They are encrypted at rest with AES-256-GCM and are
> never transmitted to the edge in plaintext. Consent withdrawal soft-deletes the template,
> which propagates to every paired device as an `op=delete` tombstone on the next delta sync
> (UU PDP No. 27/2022, GDPR Art. 17).

---

Most face recognition attendance systems rely on cloud inference. That demands constant
high-bandwidth internet and adds latency to every scan. Absensi-Cam runs the entire
detection, liveness, and recognition pipeline on an affordable Single Board Computer, and
treats the network as an optional convenience rather than a requirement.

## Why Absensi-Cam?

| Feature                 | Absensi-Cam (Edge-First) | Cloud-Based Systems |
| :---------------------- | :----------------------: | :-----------------: |
| **Inference Location**  | Local Edge Device (ONNX) | Remote Cloud        |
| **Internet Dependency** | Optional (Sync only)     | Mandatory           |
| **Latency**             | Real-time (< 50ms)       | Network Dependent   |
| **Hardware Cost**       | Ultra-Low (Orange Pi)    | High (Servers/GPUs) |

> [!NOTE]
> **Offline-first by design.** Detection, tracking, and template matching all happen on the
> edge device. Attendance is written to a local SQLite outbox and synced to Laravel when
> connectivity allows. If the internet drops, attendance keeps being recorded and drains
> automatically once the link returns.

## Core Features

- **On-device AI inference** — Real-time face detection (YuNet) and recognition
  (ArcFace/SFace) running entirely on local ARM hardware via `onnxruntime`, driven by native
  Rust (`clients/edge-engine-rs`).
- **Biometric security** — Face embeddings are encrypted at rest with AES-256-GCM. Keys are
  rotated and distributed to paired edge devices through a dedicated artisan command.
- **Offline-first syncing** — A SQLite-backed outbox daemon (`smart-absensi-sync`) syncs
  attendance records carrying UUID v4 identifiers and UTC ISO-8601 timestamps, so records
  survive network outages without loss or duplication.
- **Over-the-air updates** — Deploy new `.onnx` models and engine binaries to a fleet of edge
  devices from the Laravel dashboard. A manifest on the central server means one published
  file updates the whole fleet.
- **Latent space versioning** — Strict `MODEL_VERSION` tagging prevents biometric vectors from
  different model architectures from ever being compared.
- **Dynamic device pairing** — Devices pair to the backend with a short code and provision
  their `.env` automatically, with no manual credential handling.
- **Automated rollback** — The edge engine watches for `systemctl` crash loops and rolls back
  a bad OTA update on its own.
- **Liveness and anti-spoofing** — Mini-FASNet passive anti-spoofing with FFT frequency
  analysis, three-frame temporal confirmation, spatial spoof veto, and real-time margin
  logging. Defeats photo and smartphone-screen replay attacks without extra IR sensors.
- **Attendance cooldown** — A configurable cooldown (default five minutes) is restored from
  SQLite on engine restart, so a reboot never produces duplicate scans.

## Architecture

The project is a modular monolith plus a native edge runtime.

### 1. Backend — Laravel

A modular monolith under `app/Domain/{Attendance,Device,Enrollment,Report,School,Student,User}`,
responsible for:

- Managing students, classrooms, buildings, and devices.
- Generating face embeddings through a queued worker during enrollment.
- Serving the REST API that edge devices use to sync attendance batches idempotently
  (`/api/v1/attendance/batch`).
- Broadcasting OTA triggers and configuration deltas.

### 2. Frontend — Blade, Tailwind, Alpine.js

An administration dashboard built on Tailwind CSS v4 with an Alpine.js CSP build for
interactivity, and Lucide icons for consistent iconography.

### 3. Edge Engine — native Rust (`clients/edge-engine-rs`)

A single-binary runtime compiled for ARM Linux, targeting the Orange Pi Lite 2:

- **Capture** — Pure libc V4L2 zero-copy YUYV capture at roughly 30 FPS.
- **Decoupled AI pipeline** — YuNet detection, MiniFASNet liveness, and ArcFace/SFace
  extraction run in a dedicated thread with adaptive thermal pacing (300 ms idle, 50 ms
  active).
- **Streaming** — An `axum`-based MJPEG stream plus SSE vector overlay for the live canvas.
- **Outbox daemon** — An independent systemd unit (`smart-absensi-sync.service`) that
  continuously drains local SQLite attendance to the backend.
- **Footprint** — 10–25% CPU at standby, ~50% while a web view is open, with temperatures
  stable around 68 °C after thermal pacing replaced the previous 80.2 °C throttling.

### 4. Legacy reference engine — Python (`archive/edge-engine-py`)

Retained for laboratory prototyping and offline benchmarking. It is not part of the
production path.

## Hardware Requirements

The edge engine is tuned for resource-constrained Single Board Computers.

- **Target device** — Orange Pi Lite 2 (ARM Cortex-A53).
- **RAM** — 1 GB; the engine is built to stay under 150 MB RSS.
- **Camera** — A standard USB UVC webcam. MJPEG is preferred for lower CPU overhead.
- **Cooling** — A passive heatsink is recommended. The engine monitors thermal zones and
  reports degradation to the backend.

## Quickstart

### 1. Backend

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

**Production queue worker (Supervisor).** Create
`/etc/supervisor/conf.d/absensi-cam-worker.conf`:

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

**Production scheduler.** Add to the server crontab (`crontab -e`):

```bash
* * * * * cd /path/to/absensi-cam && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Pair the edge device

> [!IMPORTANT]
> Without a device token the edge cannot sync to Laravel.

1. Open the Laravel admin, go to **Devices**, and create a new **pairing code**.
2. Copy the code that appears (for example `AB12CD`).
3. Run the pairing flow on the edge device and enter the backend URL and pairing code.
4. The device's `.env` is populated automatically with `SMART_ABSENSI_TOKEN` and
   `SMART_ABSENSI_DEVICE_ID`.

### 3. Run the development stack

```bash
# Everything at once (Laravel + Vite + edge engine)
npm run dev

# Web only (Laravel + Vite), no edge
npm run dev:web

# Edge engine only
npm run dev:edge
```

> [!NOTE]
> `npm run dev` does not pass `--kill-others`. The edge engine keeps running even if Laravel
> stops, which is the offline-first behaviour the system is built around.

### 4. Deploy the Rust edge engine

On the Orange Pi, install the standalone binary:

```bash
# 1. Cross-compile on the host for the aarch64 target
cargo zigbuild --target aarch64-unknown-linux-gnu.2.28 --release -p engine-cli

# 2. Copy the binary to the device, or fetch it from a local deployment server
curl -fsSL http://<HOST_IP>:8888/engine-cli -o /tmp/smart-absensi-sync
sudo install -m 755 /tmp/smart-absensi-sync /usr/local/bin/smart-absensi-sync

# 3. Manage the systemd units
sudo systemctl restart smart-absensi.service smart-absensi-sync.service
sudo systemctl status smart-absensi.service smart-absensi-sync.service
```

## Database Requirements and Audit Logging

The `activity_log` table is protected by **append-only SQL triggers**
(`prevent_activity_log_update` and `prevent_activity_log_delete`) to guarantee tamper-proof
audit trails for administrative and attendance actions.

When deploying or migrating:

- **Binary logging** — If MySQL binary logging is enabled, creating these triggers requires
  the `SUPER` privilege, or you must set `log_bin_trust_function_creators = 1`.
- **Log cleanup** — Because deletion is blocked at the database level, commands such as
  `php artisan activitylog:clean` will fail with a `PDOException`. Archiving or truncating
  the table requires dropping the triggers first, performing the cleanup as a database
  administrator, then recreating them.
- **Application grants** — For true immutability, revoke `UPDATE` and `DELETE` on
  `activity_log` from the MySQL user the application connects as, complementing the trigger
  protection.

## Classroom Timings and Tolerance

In version 2.3 and later, the `late_threshold_enabled` boolean was replaced by a single
`late_tolerance_minutes` integer. Any classroom previously configured with late tracking off
(`late_threshold_enabled = false`) was migrated to the maximum tolerance of `120` minutes.
As a result, DTR now flags arrivals later than two hours as "Late" where they were
previously never flagged. Edge devices receive timings per group under a `groups` JSON
array on the `/api/v1/config` endpoint rather than as flat values.

## Development Guidelines

This repository enforces strict engineering standards for both AI coding agents and human
developers. Before contributing, read:

1. **[AGENTS.md](AGENTS.md)** — Laravel enterprise architecture guidelines: modular
   monolith, thin controllers, query builders, DTOs, batch sync contracts.
2. **[EDGE-GUIDELINES.md](docs/EDGE-GUIDELINES.md)** — Edge engine standards: native Rust
   conventions, memory safety, decoupled AI pacing.
3. **[PRD.md](docs/PRD.md)** — Product requirements (monorepo spec v2.5.0).
4. **[DESIGN.md](docs/DESIGN.md)** — Design language and token specifications.

> [!WARNING]
> We practice **zero over-engineering**. Do not introduce speculative abstractions,
> unnecessary wrapper classes, microservices, or unneeded dependencies unless they are
> explicitly required and measured.

> [!NOTE]
> All code comments are written in Indonesian. All Markdown documentation is written in
> English. See [AGENTS.md §100](AGENTS.md) for the full rule.

## Documentation

| Document | Contents |
| :--- | :--- |
| [AGENTS.md](AGENTS.md) | Engineering rules, architecture constraints, comment policy |
| [docs/PRD.md](docs/PRD.md) | Product requirements and acceptance criteria |
| [docs/DESIGN.md](docs/DESIGN.md) | Design tokens and UI language |
| [docs/EDGE-GUIDELINES.md](docs/EDGE-GUIDELINES.md) | Edge engine engineering standards |
| [docs/TFT_STREAM.md](docs/TFT_STREAM.md) | TFT display streaming protocol |
| [docs/LITERATURE.md](docs/LITERATURE.md) | Model selection and research notes |
| [SECURITY.md](SECURITY.md) | Vulnerability reporting and security posture |
| [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) | Dependency licensing |

## License

This project is licensed for internal use. See the [LICENSE](LICENSE) file for details.

<div align="center">

**[Back to top](#absensi-cam)**

</div>
