import os
import sys

_EDGE_ENGINE_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
_PKG_DEV = os.path.join(os.path.dirname(_EDGE_ENGINE_DIR), "packages")
_PKG_PROD = os.path.join(_EDGE_ENGINE_DIR, "packages")

for p in [_PKG_DEV, _PKG_PROD]:
    if os.path.isdir(p) and p not in sys.path:
        sys.path.insert(0, p)
from face_core.face_detector.detector import FaceDetector
from .liveness_detector.detector import LivenessDetector
from .tracker.tracker import FaceTracker

__all__ = ["FaceDetector", "LivenessDetector", "FaceTracker"]
