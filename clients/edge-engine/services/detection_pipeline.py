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
        self._cooldowns: dict[str, float] = {}  # student_id → last_seen timestamp

    def process(
        self, frame: np.ndarray, templates: list[dict]
    ) -> list[RecognitionResult]:
        """
        Proses satu frame. Kembalikan daftar RecognitionResult.
        templates: [{"student_id": ..., "embedding": bytes, "name": ...}]
        """
        results: list[RecognitionResult] = []

        raw_faces = self.detector.detect_faces(frame)
        if raw_faces is None or len(raw_faces) == 0:
            return results

        for face in raw_faces:
            # Confidence filter
            conf = face.get("confidence", 1.0)
            if conf < FACE_CONF_THRESHOLD:
                continue

            bbox_dict = face.get("bbox", {})
            bbox = (
                int(bbox_dict.get("x", 0)),
                int(bbox_dict.get("y", 0)),
                int(bbox_dict.get("width", 0)),
                int(bbox_dict.get("height", 0)),
            )

            # ── Liveness (stubbed) ────────────────────────────────────────
            liveness_score = 1.0
            if self.liveness:
                liveness_score = self.liveness.predict(frame, bbox)
            if liveness_score < LIVENESS_THRESHOLD:
                logger.debug("Liveness gagal — kemungkinan foto/layar.")
                continue

            # ── Recognition (stubbed) ────────────────────────────────────
            student_id = None
            name = "Unknown"
            similarity = 0.0

            if self.recognizer and templates:
                embedding = self.recognizer.embed(frame, face)
                if embedding is not None:
                    best_dist = float("inf")
                    for t in templates:
                        stored = np.frombuffer(t["embedding"], dtype=np.float32)
                        # L2 distance
                        dist = float(np.linalg.norm(embedding - stored))
                        if dist < best_dist:
                            best_dist = dist
                            student_id = t["student_id"]
                            name = t.get("name", "Anggota")

                    # Convert Euclidean distance to Cosine Similarity
                    similarity = 1.0 - (best_dist ** 2) / 2.0

            recognized = student_id is not None and similarity >= RECOGNITION_THRESHOLD

            # ── Cooldown ────────────────────────────────────────────────
            # We want to keep student_id and name for display purposes
            # even if we are in cooldown (recognized = False for attendance)
            is_valid_match = student_id is not None and similarity >= RECOGNITION_THRESHOLD
            
            if recognized:
                last = self._cooldowns.get(student_id, 0)
                if time.time() - last < RECOGNITION_COOLDOWN_SEC:
                    recognized = False  # Masih dalam cooldown, skip logging
                else:
                    self._cooldowns[student_id] = time.time()

            results.append(
                RecognitionResult(
                    student_id=student_id if is_valid_match else None,
                    name=name if is_valid_match else "Unknown",
                    confidence=similarity,
                    liveness_score=liveness_score,
                    bbox=bbox,
                    recognized=recognized,
                )
            )

        return results
