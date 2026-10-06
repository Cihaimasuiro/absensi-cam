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

- **On-Device AI Inference:** Real-time face detection (e.g., YuNet) and recognition (e.g., MobileFaceNet/SFace) running entirely on local ARM hardware using `onnxruntime` via the cross-platform `face_core` module.
- **Biometric Security:** Face embeddings are strictly encrypted at rest in the database using AES-256-GCM. Keys are securely rotated and distributed to paired edge devices.
- **Offline-First Syncing:** SQLite-backed outbox mechanism ensures no attendance records are lost during network outages.
- **Over-The-Air (OTA) Updates:** Deploy new face recognition `.onnx` models and engine binary updates directly from the Laravel dashboard to fleets of edge devices.
- **Latent Space Versioning:** Strict `MODEL_VERSION` tagging ensures biometric vectors from different model architectures are never incorrectly compared.
- **Dynamic Device Pairing:** Securely pair edge devices to the backend using pairing codes and encrypted `.env` provisioning.
- **Automated Rollbacks:** Edge engine utilizes `systemctl` crash-loop detection to automatically rollback bad OTA updates.

## Architecture

The project consists of two primary components:

### 1. The Backend (Laravel)
A Modular Monolith built with Laravel, responsible for:
- Managing Students, Employees, and Devices.
- Generating Face Embeddings securely via a Python `face_core` integration running in background Queues during enrollment.
- Providing the REST API for Edge Devices to sync attendance.
- Broadcasting OTA update triggers.

### 2. The Edge Engine (Python)
A lightweight, high-performance Python application running on the edge:
- **Runtime:** Python 3.11+
- **Inference:** ONNX Runtime (CPU Execution Provider) via `face_core`.
- **Web API:** Flask for local network setup and status.
- **Packaging:** Compiled into a single binary using PyInstaller.

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

### 5. Production Deployment (Edge Engine)
Di Orange Pi (production), instal menggunakan skrip otomatis yang akan membuat *virtual environment* Python dan layanan systemd:

```bash
cd clients/edge-engine
sudo ./install.sh
# Skrip akan menginstal dependencies ke /opt/smart-absensi/edge-engine dan mengaktifkan smart-absensi.service
```

## Database Requirements & Audit Logging

The `activity_log` table is protected by **append-only SQL triggers** (`prevent_activity_log_update` and `prevent_activity_log_delete`) to guarantee tamper-proof audit trails for administrative and attendance actions. 

When deploying or migrating:
- **Binary Logging:** If MySQL binary logging is enabled on your production server, creating these triggers requires the `SUPER` privilege OR you must set `log_bin_trust_function_creators = 1` in your MySQL configuration.
- **Log Cleanup:** Because deletions are blocked at the database level, standard Laravel commands like `php artisan activitylog:clean` will **fail** with a PDOException. Archiving or truncating the log table requires dropping the triggers first, performing the cleanup as a database administrator, and recreating them.
- **Application User Grants:** For true immutability, ensure the MySQL user configured in your `.env` (used by the Laravel application) is **revoked** of `UPDATE` and `DELETE` privileges on the `activity_log` table, complementing the trigger protections.

## Development Guidelines

This repository enforces strict, enterprise-grade engineering standards for both AI coding agents and human developers. 

Before contributing, you **must** read:
1. **[AGENTS.md](AGENTS.md):** Laravel Enterprise Architecture Guidelines (Modular Monolith, Thin Controllers, Query Builders, DTOs).
2. **[AGENTS2.md](AGENTS2.md):** Python Edge Engine Guidelines (PEP 8, Type Hints, Thread Safety, ONNX optimization, PyInstaller).

> [!WARNING]
> We practice **Zero Over-Engineering**. Do not introduce abstract base classes, microservices, or external dependencies unless explicitly required and measured.

## License

This project is licensed for internal use. See the `LICENSE` file for details.
