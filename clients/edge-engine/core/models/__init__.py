import os
import sys

_ROOT_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))))
_PACKAGES_DIR = os.path.join(_ROOT_DIR, "packages")
if _PACKAGES_DIR not in sys.path:
    sys.path.insert(0, _PACKAGES_DIR)

from face_core.face_detector.detector import FaceDetector
from .liveness_detector.detector import LivenessDetector
from .tracker.tracker import FaceTracker

__all__ = ["FaceDetector", "LivenessDetector", "FaceTracker"]
