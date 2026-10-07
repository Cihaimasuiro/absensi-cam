"""
services/detection_pipeline.py — Pipeline inferensi utama.

Mengorkestrasi:
  1. FaceDetector (YuNet)      → temukan kotak wajah
  2. LivenessDetector          → tolak foto/layar
  3. FaceRecognizer (SFace)    → cocokkan ke template lokal
  4. Cooldown tracker          → cegah absen dobel dalam <N detik

Return: list of RecognitionResult per frame.
"""

import logging

logger = logging.getLogger(__name__)
import time
from dataclasses import dataclass

import numpy as np

from config.settings import (
    FACE_CONF_THRESHOLD,
    LIVENESS_THRESHOLD,
    RECOGNITION_COOLDOWN_SEC,
    RECOGNITION_THRESimport logging

logger = logging.getLogger(__name__)
import time
from dataclasses import dataclass

import numpy as np

from config.settings import (
    FACE_CONF_THRESHOLD,
    LIVENESS_THRESHOLD,
    RECOGNITION_COOLDOWN_SEC,
    RECOGNITION_THRESHOLD,
)


@dataclass
class RecognitionResult:
    student_id: str | None
    name: str
    confidence: float
    liveness_score: float
    bbox: tuple  # (x, y, w, h)
    recognized: bool


class DetectionPipeline:
    def __init__(self, detector, recognizer=None, liveness=None):
        self.detector = detector
        self.recognizer = recognizer  # None = stubbed
        self.liveness = liveness  # None = stubbed (pass-through)
        self._cooldowns: dict[str, float] = {}  # student_id -> last_seen timestamp
        self._track_info: dict[int, dict] = {}  # track_id -> cached ai results
        
        try:
            from core.models.tracker.tracker import FaceTracker
            self.tracker = FaceTracker(track_thresh=0.5, match_thresh=0.8, track_buffer=30)
        except Exception as e:
            logger.error(f"Tracker failed to load: {e}")
            self.tracker = None

    def process(
        self, frame: np.ndarray, templates: list[dict]
    ) -> list[RecognitionResult]:
        results: list[RecognitionResult] = []

        raw_faces = self.detector.detect_faces(frame)
        if raw_faces is None or len(raw_faces) == 0:
            if self.tracker:
                self.tracker.update([])
            return results
