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

## Architecture & Threat Scope

This project operates on a dual-architecture model:
1. **The Backend (Laravel 13):** Handles relational database storage, background queues for enrollment embedding extraction, role-based access control, audit logs, and REST API endpoints. Standard web application security models apply here.
2. **The Production Edge Engine (Native Rust — `clients/edge-engine-rs`):** Runs on distributed IoT devices (Orange Pi Lite 2) on local school networks. Written in native Rust for memory safety (no buffer overflows, no use-after-free, no garbage collection stalls).
   - **Video Ingestion:** Direct Linux V4L2 ioctl bindings without shell escapes.
   - **Local Web Server:** Built on `axum`/`tokio` serving MJPEG streams, snapshots, and SSE vector overlay events on port 5000.
   - **Offline Outbox Daemon:** Standalone `smart-absensi-sync` daemon communicating with Laravel REST API over HTTPS/HTTP with token authentication.

## Biometric Security & Anti-Spoofing Model

- **Liveness & Anti-Spoofing:** MiniFASNet model running dual-crop (tight + context) with 16x16 FFT frequency spectrum analysis.
- **Temporal Confirmation with Spoof Veto:** Anti-spoofing requires 3 consecutive frames of confirmed liveness. Any spoof detection in the spatial vicinity immediately vetoes and resets the accumulator to 0, thwarting photo or video playback attacks.
- **Biometric Encryption:** Face embeddings (512-dimensional floating vectors) are encrypted at rest using AES-256-GCM (`ENROLLMENT_EMBED_KEY`). Plaintext biometric vectors are never written to disk.
- **Attendance Idempotency:** Each edge capture generates an RFC 4122 compliant UUID v4 and UTC ISO-8601 timestamp. The backend enforces unique primary keys via `insertOrIgnore()`, preventing replay and network duplicate attacks.

## What Counts as High Severity

Examples of high-severity issues in Absensi-Cam include:
- Extracting raw face templates or bypassing biometric validation.
- Bypassing the Device Pairing mechanism or forging valid device API tokens.
- Bypassing the 3-frame liveness temporal confirmation and spoof veto.
- Over-The-Air (OTA) update vulnerabilities (e.g., bypassing SHA-256 checksum verification).
- Memory safety exploits or remote code execution against the edge axum web server or sync daemon.
- Extracting the `SMART_ABSENSI_TOKEN` or `ENROLLMENT_EMBED_KEY` from the edge device remotely.
- Cracking the AES-256-GCM encryption used for biometric template storage.
