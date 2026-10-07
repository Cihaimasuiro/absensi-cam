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

The original Facenox codebase (AGPL-3.0) heavily influenced this system. Currently:

* **Removed/Replaced**: `clients/edge-engine/core/cipher.py` has been completely deleted.
* **Retained Structure/Strings**: The general architecture and strings in `hooks/face_processing.py`, `detection_pipeline.py`, `lifespan.py`, and `packages/face_core` still carry Facenox-derived structures.
* **Modified**: `detector.py` and `tracker.py` have been modified to remove Facenox dependencies and rely on standard `numpy`/`lap` logic, but they still originated from the same baseline.

A complete architectural rewrite to remove all structural similarities is still under evaluation.
