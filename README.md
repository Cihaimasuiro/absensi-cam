<a id="readme-top"></a>

<h1 align="center">
  Smart Absensi — Sistem Absensi Face Recognition
</h1>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-000000?style=flat&logo=git&logoColor=f2f5f7&labelColor=0d1117" alt="License"></a>
</p>

<p align="center">
  <strong>Smart Absensi adalah monorepo sistem absensi face recognition berbasis Orange Pi Lite 2.</strong><br>
  Edge node (C++ engine) -> REST API / JSON -> Server Pusat / Admin PC (Laravel 13).
</p>

<p align="center">
  <img src="docs/assets/screenshots/02-face-recognition-live.png" alt="Smart Absensi Desktop UI - Active Scan" width="100%">
</p>

## Screenshots

<details>
<summary><strong>UI Gallery</strong></summary>

### Main Interface

![Camera Preview](docs/assets/screenshots/01-camera-preview.png)
<br/>
![Face Recognition](docs/assets/screenshots/02-face-recognition-live.png)
_Primary control interface for video hardware setup and active biometric scanning, featuring real-time face detection and multi-subject tracking._

### Overview

![Attendance Dashboard](docs/assets/screenshots/03-attendance-dashboard.png)
_Overview dashboard displaying real-time metrics for 'Present Today' and 'Late Arrivals' alongside a live Activity Log._

### Reports

![Reports](docs/assets/screenshots/04-reports-management.png)
_Reports interface detailing attendance records, featuring built-in tools for manual session editing and CSV data export._

### Members

![Members](docs/assets/screenshots/05-member-enrollment.png)
_Member management interface for profile administration, role assignment, and biometric enrollment._

### General

![General Settings](docs/assets/screenshots/06-settings-general.png)
_Configuration controls for core attendance mechanics, including Entry/Exit modes, Late Tracking thresholds, Duplicate Prevention cooldowns, and Recognition Limits._

### Security & Compliance

![Security Settings](docs/assets/screenshots/07-settings-security.png)
_Configuration panel for Anti-spoofing (Liveness Verification) toggles, Global Group Consent enforcement, and Data Retention policies._

### Database

![Database Settings](docs/assets/screenshots/08-settings-database-sync.png)
_Database settings panel detailing system clock accuracy validation and enrollment metrics across groups._

### Backup & Export

![Backup Restore](docs/assets/screenshots/09-database-backup-restore.png)
_Backup utilities for exporting and restoring the offline SQLite database and biometric profiles via encrypted archives._

</details>

---

> [!CAUTION]  
> This is the official open source repository for Smart Absensi. Treat other repositories, installers, and downloads as unverified unless they come from official sources.

> [!NOTE]  
> **Privacy First:** Smart Absensi processes face detection, tracking, and template matching locally. Encrypted face templates can optionally sync between your devices, with decryption keys stored on your paired hardware and encrypted in the cloud database.

## Arsitektur Monorepo

```
absensi-cam/ (pnpm workspaces)
├── app-smart-absensi/       # Aplikasi Utama & Web Portal (Laravel 13 PHP)
│   ├── app/
│   │   ├── Domain/          # Arsitektur berbasis domain (Attendance, Member, User)
│   │   └── Http/            # Controllers API V1, Validasi Request, & API Resources
│   ├── database/            # Migrasi basis data, seeders, & factories
│   ├── resources/           # Antarmuka UI (Blade / Inertia React), CSS, & JS
│   └── routes/              # Definisi rute Web & API
├── server/                  # Mikroservis AI / Pengenalan Wajah (Python FastAPI + C++)
│   ├── api/                 # Endpoint & rute API pemrosesan inferensi AI
│   ├── core/models/         # Engine Pembelajaran Mesin (ML Models)
│   │   ├── face_detector/   # Algoritma Pendeteksi Wajah
│   │   ├── face_recognizer/ # Algoritma Pengenal Wajah
│   │   ├── liveness_detector/# Pendeteksi Keaslian Wajah (Anti-spoofing)
│   │   └── tracker/         # Pelacak Wajah (Face Tracking)
│   └── migrations/          # Migrasi basis data layanan AI
├── docs/                    # Dokumentasi proyek & tangkapan layar
└── templates/               # Templat struktur rujukan
```

**Alur Data:** Orange Pi Lite 2 -> REST API / JSON -> Laravel 13 Server Pusat

## Why Smart Absensi

| Feature | Smart Absensi | Cloud-Based Systems |
| :---------------------- | :--------: | :-----------------: |
| **Data Residency** | Server sendiri (Admin PC) | Remote Cloud |
| **Internet Dependency** | LAN/WiFi only | Mandatory |
| **Latency** | Real-time (< 30ms/frame) | Network Dependent |
| **Face Capacity** | 2000+ per device | Varied |
| **Server Pusat** | Laravel 13 (self-hosted) | Third-party cloud |

## Fitur

- **Custom 2000+ Face Capacity**: SQLite C++ engine, kapasitas >= 2000 wajah per SBC.
- **Connected Devices**: Monitoring multi-terminal Orange Pi Lite 2 terpusat.
- **Custom Branches / Locations**: Pengelompokan cabang, gedung, pintu masuk.
- **Custom Organizations**: Hierarki organisasi, divisi, kelas siswa.
- **Remote Face Template Sync**: Sync template wajah dari Server Pusat Laravel 13 ke semua SBC edge via REST API.
- **Lifetime Cloud Attendance History**: Sync riwayat absensi ke Laravel 13 Server Pusat — data tersimpan selamanya.
- **Audit Logs**: Jejak audit aktivitas sistem lengkap.
- **Excel & PDF DTR Reports**: Laporan kehadiran Daily Time Record otomatis.
- **Raw CSV Data Export**: Ekspor data mentah CSV untuk HRMS / Payroll.

## Performance

### Hardware Compatibility

- **No GPU Required:** Real-time matching on standard CPUs.
- **Environment:** Optimized for controlled lighting and consistent setups.
- **Hardware:** Verified on hardware as old as 2nd-gen Intel i7 (2011), 4th-gen i3 (2015), and 8th-gen i5 (2018) laptops.

### Performance Benchmark

Evaluated on the standard **LFW (Labeled Faces in the Wild)** dataset with **500 registered identities** on an Intel Core i5-8350U CPU without a dedicated GPU:

![LFW Benchmark Face Grid](docs/assets/screenshots/lfw_benchmark_grid.png)

| Metric | Result | Target | Status |
| :--- | :---: | :---: | :---: |
| **Average search latency** | `14.56 ms` | < 50 ms | Passed |
| **Max search latency** | `52.34 ms` | < 150 ms | Passed |
| **Security (False match rate)** | `0.0%` | < 0.1% | Passed |
| **Rejection accuracy (TNR)** | `100.0%` | > 99.0% | Passed |
| **Recognition accuracy (TPR)** | `94.0%` | > 90.0% | Passed |

- **Security:** The system did not commit a single false match. An unregistered stranger will never be mistaken for a registered member.
- **Speed:** It takes less than 0.02 seconds to search and match a face against all 500 profiles in the database.
- **Accuracy:** It successfully recognizes registered members on the first try, even with changes in pose, expression, or lighting conditions.

To run the exact same test locally:
```bash
cd server
python tests/stress_test_recognition.py
```
*(The script will automatically download the test faces, run the simulation, and clean up afterwards.)*

> [!NOTE]
> **Scaling:** The benchmark above represents a worst-case scenario, searching all 500 profiles with no filtering. In actual use, Smart Absensi limits face searches to the active group's members only, reducing both search time and false match risk in practice.

## Offline-First Behavior

Smart Absensi Desktop continues to work locally when internet access is unavailable:

- Recognition and liveness verification remain functional.
- Attendance is recorded and stored permanently in a local SQLite database.
- Settings, backups, and member management remain accessible.
- Smart Absensi Cloud sync resumes automatically when connectivity returns.
  (Completely optional, as cloud sync is disabled by default and only active when your device is paired.)

> [!NOTE]  
> **Privacy Assurance:** Smart Absensi does not store or upload raw face photos. Raw camera frames are processed locally and are never persisted to disk. Biometric embeddings are encrypted with AES-256-GCM before optional cloud synchronization to enable multi-device sync and automatic backup. Matching always stays on your local hardware.

## How it works

### Where is the data stored?

Everything is stored in a local SQLite database on your machine. Biometric templates are encrypted at rest.

### Does it need the internet?

No. The local FastAPI server processes all camera frames on your machine. Internet connectivity is only required to upload attendance logs or sync encrypted templates to the web dashboard.

### What hardware do I need?

It's designed for standard CPUs and has been tested on hardware as old as 2nd-gen Intel i7 (2011), 4th-gen i3 (2015), and 8th-gen i5 (2018) laptops. No dedicated GPU or CUDA setup is required.

### How do I protect my data?

Because Smart Absensi operates locally, no external servers have access to your database. Built-in backup utilities allow exporting encrypted `.Smart Absensi` archives for secure local or remote storage.

## Roadmap

- [x] Support for cross-platform native installers (Windows, macOS, Linux).
- [x] Attendance trends and site-level reporting in Smart Absensi Dashboard.
- [ ] Software Signing.
- [ ] Mobile companion application for remote monitoring.

---

> [!IMPORTANT]
> **Security & Trust**
>
> - **Automated Audits:** GitHub CodeQL scans commits for security vulnerabilities on every push.
> - **Build Transparency:** GitHub Actions compiles all release binaries from public source code.
> - **Privacy First:** Devices encrypt all outbound templates with AES-256-GCM. The database only stores templates in encrypted form.

> **Management Dashboard:** The **official Smart Absensi Dashboard** is an optional service for centralized reporting. This repository contains the source for the **desktop client** only.

## Download

Latest prebuilt binaries are available on the [GitHub Releases](https://github.com/Smart Absensi/Smart Absensi/releases/latest) page.

If you want to build from source, please follow the [Installation Guide](docs/INSTALLATION.md).

## Installation Notes

Smart Absensi is in active development. Until code-signing is finalized in a future release, you may encounter OS security prompts.

> [!WARNING]  
> **OS Security Prompts & Installation Warnings:**
>
> **Windows (SmartScreen):** If blocked, click **More info** then **Run anyway**.
>
> <img src="docs/assets/smartscreen_warning.png" alt="Windows SmartScreen warning" width="350">
>
> **macOS (Gatekeeper):** If blocked, **Right-click** the app, select **Open**, then confirm the prompt.
>
> <img src="docs/assets/macos_gatekeeper_warning.png" alt="macOS Gatekeeper warning" width="350">
>
> **Linux (AppImage / Debian Package):**
>
> *Using the AppImage:*
> 1. Make the downloaded `.AppImage` file executable by right-clicking it -> **Properties** -> **Permissions** -> checking **Is executable** (or by running `chmod +x Smart Absensi-Linux-*.AppImage` in the terminal).
> 2. Double-click the file to execute it. If prompted with a security warning (e.g., in KDE Dolphin), click **Continue**.
>
> <img src="docs/assets/linux_warning.png" alt="Linux AppImage warning" width="500">
>
> *Compatibility Note:* Modern Linux distributions (e.g., Ubuntu 22.04+) require `libfuse2` to mount and run AppImages. If the app fails to start, install it by running:
> ```bash
> sudo apt update && sudo apt install libfuse2
> ```
> *(Warning: Do not install the package named `fuse` as it can conflict with your desktop environment.)*
>
> *Using the Debian Package (.deb):*
> 1. Install by running the following commands in the terminal (recommended to automatically resolve dependencies):
>    ```bash
>    sudo apt update
>    sudo apt install ./Smart Absensi-Linux-*.deb
>    ```
>    *(Note: The `./` prefix is required for `apt` to identify the file as a local package.)*
> 2. Alternatively, double-click the `.deb` file to open it in your system's Software Center and click **Install**.

## Documentation

- [FEATURES.md](docs/FEATURES.md): Capabilities and out-of-scope items.
- [ARCHITECTURE.md](docs/ARCHITECTURE.md): System design and sync boundaries.
- [INSTALLATION.md](docs/INSTALLATION.md): Local development setup.
- [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md): Common setup and runtime issues.
- [CODE_SIGNING_POLICY.md](docs/CODE_SIGNING_POLICY.md): Release identity and trust rules.
- [PRIVACY.md](docs/PRIVACY.md): Data handling and consent policy.
- [SECURITY.md](SECURITY.md): Vulnerability reporting policy.

## Tech Stack

### Edge Node Backend (`server/` — Orange Pi Lite 2)

- **Runtime:** Python 3.10+ (FastAPI)
- **Inference:** ONNX Runtime, OpenCV (YuNet + SFace + Mini-FASNet)
- **Tracking:** ByteTrack (High-performance MOT)
- **Anti-Spoofing:** face-antispoof-onnx (600KB ONNX model)
- **Storage:** SQLite, SQLAlchemy, Alembic
- **Sync:** REST API client -> Laravel 13 Server Pusat

### Server Pusat / Admin PC (`app-smart-absensi/` — Laravel 13)

- **Runtime:** PHP 8.3 + Laravel 13
- **Auth:** Laravel Sanctum
- **Permissions:** spatie/laravel-permission
- **REST API:** menerima sync dari semua edge node Orange Pi Lite 2
- **Laporan:** Excel, PDF (DTR), CSV export

## Development Quickstart

```bash
# 1. Clone the repo
git clone https://github.com/smart-absensi/smart-absensi.git
cd smart-absensi

# 2. Setup Edge Node Backend (atau sidecar desktop)
python -m venv venv
source venv/bin/activate  # atau venv\Scripts\activate
pip install -r server/requirements.txt

# 3. Setup Desktop App
pnpm install

# 4. Setup Server Pusat (Laravel 13)
cd app-smart-absensi
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
cd ..

# 5. Run Development Workspace
# Desktop + Edge sidecar:
./dev-start.sh
# Server Pusat:
./dev-start.bat
```

## Contributing

Pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a PR, especially for changes affecting privacy or biometric data.

## Acknowledgments

- [FastAPI](https://fastapi.tiangolo.com/)
- [ONNX Runtime](https://onnxruntime.ai/)
- [OpenCV](https://opencv.org/)
- [Electron](https://www.electronjs.org/)
- [React](https://react.dev/)
- [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md)

## License

Smart Absensi is licensed under the **GNU AGPL v3**. See [LICENSE](LICENSE) for details.

