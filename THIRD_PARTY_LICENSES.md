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
| `detector.onnx` | Face Detection | InsightFace (SCRFD) | **Non-Commercial** (CC BY-NC-SA 4.0) | ❌ No |
| `recognizer.onnx` | Face Recognition (512d) | InsightFace (ArcFace) | **Non-Commercial** (CC BY-NC-SA 4.0) | ❌ No |
| `liveness.onnx` | Liveness / Anti-Spoofing | Silent-Face-Anti-Spoofing | MIT / Apache 2.0 (Check source) | ⚠️ Unknown (Needs verification) |

## Facenox Derived Code Status

*(To be updated after rewrite)*

Currently, several components (Cipher, Tracker, Liveness wrappers) were originally derived from Facenox. A rewrite is planned to implement these strictly from behavior specifications using permissive libraries to ensure no GPL/AGPL contamination.
