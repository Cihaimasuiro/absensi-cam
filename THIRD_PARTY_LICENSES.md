# Third-Party Licenses

This document lists the third-party dependencies and AI models used in this project, along with their licenses.

## Software Dependencies (Edge Engine)

| Component | Description | License | Source |
| :--- | :--- | :--- | :--- |
| `cryptography` | Cryptographic recipes and primitives for Python | Dual: Apache 2.0 or BSD | https://cryptography.io/ |
| `ByteTrack` | Multi-Object Tracking algorithm (adapted logic) | MIT License | https://github.com/ifzhang/ByteTrack |
| `OpenCV` | Computer Vision Library (`opencv-python-headless`) | Apache 2.0 | https://opencv.org/ |
| `NumPy` | Scientific Computing Library | BSD | https://numpy.org/ |

## AI Models

> [!WARNING]
> **Important Note on InsightFace Models:** The InsightFace published weights are strictly for **non-commercial research purposes only**. If this system is deployed commercially, you must train your own models, acquire a commercial license from InsightFace, or replace the models with permissively licensed alternatives (e.g., from MobileFaceNet community forks with Apache/MIT licenses).

| Model File | Purpose | Source / Architecture | License | Commercial Use? |
| :--- | :--- | :--- | :--- | :--- |
| `detector.onnx` | Face Detection | InsightFace (SCRFD) | Unknown | ⚠️ Unknown |
| `recognizer.onnx` | Face Recognition (512d) | InsightFace (ArcFace) | Unknown | ⚠️ Unknown |
| `liveness.onnx` | Liveness / Anti-Spoofing | Silent-Face-Anti-Spoofing | MIT / Apache 2.0 (Check source) | ⚠️ Unknown |

## Facenox Derived Code Status

All Facenox-derived code (Cipher, Tracker, Liveness wrappers) has been completely removed and rewritten from scratch using standard permissive libraries (`lap`, `numpy`, `cryptography`). No AGPL-3.0 code remains in the repository.
