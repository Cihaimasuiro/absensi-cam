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

- **On-Device AI Inference:** Real-time face detection (e.g., YuNet) and recognition (e.g., MobileFaceNet/SFace) running entirely on local ARM hardware using `onnxruntime`.
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
- Generating Face Embeddings (via background Queues) during enrollment.
- Providing the REST API for Edge Devices to sync attendance.
- Broadcasting OTA update triggers.

### 2. The Edge Engine (Python)
A lightweight, high-performance Python application running on the edge:
- **Runtime:** Python 3.11+
- **Inference:** ONNX Runtime (CPU Execution Provider)
- **Web API:** Flask for local network setup and status.
- **Packaging:** Compiled into a single binary using PyInstaller.

## Hardware Requirements

The Edge Engine is heavily optimized for resource-constrained Single Board Computers (SBCs).

- **Target Device:** Orange Pi Lite 2 (ARM Cortex-A53)
- **RAM:** 1 GB (Engine optimized to run in < 150MB RSS)
- **Camera:** Standard USB UVC Webcams (MJPEG format preferred for lower CPU overhead).
- **Cooling:** Passive heatsink recommended; engine monitors thermal zones and reports degradation to the backend.

## Quickstart

### Setting up the Backend (Laravel)
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed

# Run the local server and background worker
php artisan serve
php artisan horizon
```

### Setting up the Edge Engine (Development)
```bash
cd clients/edge-engine
python -m venv venv
source venv/bin/activate  # Windows: .\venv\Scripts\activate
pip install -r requirements.txt

# Run the engine
python engine.py
```

### Building for Production (Edge)
To build the single-file binary for OTA deployment on the Orange Pi:
```bash
cd clients/edge-engine
python build.py
# This generates `dist/absensi-engine` and prints its SHA-256 checksum.
```

## Development Guidelines

This repository enforces strict, enterprise-grade engineering standards for both AI coding agents and human developers. 

Before contributing, you **must** read:
1. **[AGENTS.md](AGENTS.md):** Laravel Enterprise Architecture Guidelines (Modular Monolith, Thin Controllers, Query Builders, DTOs).
2. **[AGENTS2.md](AGENTS2.md):** Python Edge Engine Guidelines (PEP 8, Type Hints, Thread Safety, ONNX optimization, PyInstaller).

> [!WARNING]
> We practice **Zero Over-Engineering**. Do not introduce abstract base classes, microservices, or external dependencies unless explicitly required and measured.

## License

This project is licensed for internal use. See the `LICENSE` file for details.
