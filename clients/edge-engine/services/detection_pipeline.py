"""
services/detection_pipeline.py — Pipeline inferensi utama.

Mengorkestrasi:
  1. FaceDetector (YuNet)      → temukan kotak wajah
  2. LivenessDetector          → tolak foto/layar
  3. FaceRecognizer (SFace)    → cocokkan ke template lokal
  4. Cooldown tracker          → cegah absen dobel dalam <N detik

Return: list of RecognitionResult per frame.
"""

import time
import logging
import numpy as np
import cv2
from dataclasses import dataclass, field
from typing import Optional

from config.settings import (
    FACE_CONF_THRESHOLD,
    LIVENESS_THRESHOLD,
    RECOGNITION_THRESHOLD,
    RECOGNITION_COOLDOWN_SEC,
)


@dataclass
class RecognitionResult:
    student_id: Optional[str]
    name: str
    confidence: float
    liveness_score: float
    bbox: tuple          # (x, y, w, h)
    recognized: bool


class DetectionPipeline:
    def __init__(self, detector, recognizer=None, liveness=None):
        self.detector   = detector
        self.recognizer = recognizer    # None = stubbed
        self.liveness   = liveness      # None = stubbed (pass-through)
        self._cooldowns: dict[str, float] = {}   # student_id → last_seen timestamp

    def process(self, frame: np.ndarray, templates: list[dict]) -> list[RecognitionResult]:
        """
        Proses satu frame. Kembalikan daftar RecognitionResult.
        templates: [{"student_id": ..., "embedding": bytes, "name": ...}]
        """
        results: list[RecognitionResult] = []

        raw_faces = self.detector.detect(frame)
        if raw_faces is None or len(raw_faces) == 0:
            return results

        for face in raw_faces:
            # Confidence filter
            conf = float(face[-1]) if len(face) > 4 else 1.0
            if conf < FACE_CONF_THRESHOLD:
                continue

            bbox = tuple(face[:4].astype(int))

            # ── Liveness (stubbed) ────────────────────────────────────────
            liveness_score = 1.0
            if self.liveness:
                liveness_score = self.liveness.predict(frame, bbox)
            if liveness_score < LIVENESS_THRESHOLD:
                logging.debug("Liveness gagal — kemungkinan foto/layar.")
                continue

            # ── Recognition (stubbed) ────────────────────────────────────
            student_id = None
            name       = "Unknown"
            similarity = 0.0

            if self.recognizer and templates:
                embedding = self.recognizer.embed(frame, bbox)
                best_dist = float("inf")
                for t in templates:
                    stored = np.frombuffer(t["embedding"], dtype=np.float32)
                    dist   = float(np.linalg.norm(embedding - stored))
                    if dist < best_dist:
                        best_dist  = dist
                        student_id = t["student_id"]
                        name       = t.get("name", "Anggota")

                similarity = max(0.0, 1.0 - best_dist)

            recognized = student_id is not None and similarity >= RECOGNITION_THRESHOLD

            # ── Cooldown ────────────────────────────────────────────────
            if recognized:
                last = self._cooldowns.get(student_id, 0)
                if time.time() - last < RECOGNITION_COOLDOWN_SEC:
                    recognized = False   # Masih dalam cooldown, skip
                else:
                    self._cooldowns[student_id] = time.time()

            results.append(RecognitionResult(
                student_id   = student_id if recognized else None,
                name         = name if recognized else "Unknown",
                confidence   = similarity,
                liveness_score = liveness_score,
                bbox         = bbox,
                recognized   = recognized,
            ))

        return results
