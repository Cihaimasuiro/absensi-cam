# Model Provenance & Commercial Compliance Plan

The Edge Engine relies on ONNX models for detection, recognition, and liveness. Many popular open-source models, such as InsightFace, are licensed under **CC BY-NC-SA 4.0** (Non-Commercial) or depend on code licensed under **AGPL-3.0**. 

To deploy Smart Absensi commercially, we must replace these models with permissively licensed alternatives (Apache 2.0, MIT, or BSD).

## 1. Face Detection
- **Current Issue**: Often uses RetinaFace or SCRFD (InsightFace, CC BY-NC-SA).
- **Replacement Options**:
  - **YuNet**: A highly efficient face detector developed by Shiqi Yu. It is included in OpenCV's DNN module. License: **MIT/Apache-2.0**.
  - **MediaPipe Face Detection**: Developed by Google, extremely fast on edge devices. License: **Apache-2.0**.
  - **Ultra-Light-Fast-Generic-Face-Detector-1MB**: Fast edge model. License: **MIT**.

## 2. Face Recognition
- **Current Issue**: InsightFace ArcFace models are restricted to non-commercial use.
- **Replacement Options**:
  - **OpenFace**: Based on FaceNet, permissive license (**Apache-2.0**). Note: accuracy may be slightly lower than state-of-the-art ArcFace.
  - **Train a Custom ArcFace/AdaFace Model**: The ArcFace *algorithm* is public. You can train your own ResNet model on a permissively licensed dataset (e.g., CASIA-WebFace or VGGFace2, though VGGFace2 has its own NC restrictions). A custom training run on a clean dataset avoids InsightFace's license.
  - **GhostFaceNets**: Fast and lightweight face recognition models. License: **MIT**.

## 3. Liveness Detection
- **Current Issue**: The provenance of the current `liveness.onnx` is undocumented. If derived from MiniFASNet or Silent-Face-Anti-Spoofing, it might be MIT, but we must verify.
- **Replacement Options**:
  - **Silent-Face-Anti-Spoofing**: The original implementation is licensed under **MIT**. If we compile the ONNX directly from their repository, we are cleared for commercial use.
  - **MobileNetV3 Custom Model**: Train a lightweight binary classifier for liveness using the CelebA-Spoof dataset (verify dataset commercial terms).

## Next Steps
1. Remove all current `.onnx` files from the repository's tracked history or `packages/models` if their provenance is unknown.
2. Integrate **YuNet** (MIT) for detection and **GhostFaceNets** (MIT) for recognition.
3. Re-export the **Silent-Face-Anti-Spoofing** (MIT) model to ONNX for liveness detection.
4. Update `THIRD_PARTY_LICENSES.md` to reflect the new permissive models.
