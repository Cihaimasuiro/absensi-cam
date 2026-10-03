# Security Policy

## Supported Versions

Absensi-Cam manages sensitive biometric and attendance data across both a centralized backend and distributed edge devices. Only the latest released version is considered supported for security fixes.

| Version | Supported |
| --- | --- |
| latest release | yes |
| older releases | no |

## Reporting a Vulnerability

Do not open a public GitHub issue for a security vulnerability.

**Preferred path:**
1. Open a GitHub Draft Security Advisory for this repository.

**Fallback path:**
1. Contact the core maintainers privately.

Please include:
- The affected version, commit, or branch.
- A clear description of the issue.
- Reproduction steps or a proof of concept.
- The impact (especially if biometric embeddings, attendance data, or device pairing mechanisms are compromised).

## Response Target

Reports are normally acknowledged within 48 to 72 hours. The exact fix timeline depends on severity and reproducibility.

## What Counts as High Severity

Examples of high-severity issues in Absensi-Cam include:
- Extracting raw face templates or bypassing biometric validation.
- Bypassing the Device Pairing mechanism to spoof attendance records.
- Over-The-Air (OTA) update vulnerabilities (e.g., bypassing SHA-256 checksum verification).
- Exploiting the SQLite outbox or Flask API on the edge device to execute arbitrary code.
- Extracting the `SMART_ABSENSI_TOKEN` from the edge device's `.env` remotely.

## Architecture Scope

This project operates on a dual-architecture model:
1. **The Backend (Laravel):** Handles the main database, queues, and API endpoints. Standard web application security models apply here.
2. **The Edge Engine (Python):** Runs on distributed IoT devices (Orange Pi) on local networks. Vulnerabilities requiring physical access to the SD card of the edge device are generally considered outside our software threat model, but network-based attacks against the local Flask server are strictly within scope.
