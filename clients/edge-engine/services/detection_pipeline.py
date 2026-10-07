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
import time
from dataclasses import dataclass
import numpy as np

from config.settings import (
    FACE_CONF_THRESHOLD,
    LIVENESS_THRESHOLD,
    RECOGNITION_COOLDOWN_SEC,
    RECOGNITION_THRESHOLD,
)

logger = logging.getLogger(__name__)

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

    def process(self, frame: np.ndarray, templates: list[dict]) -> list[RecognitionResult]:
        results: list[RecognitionResult] = []

        raw_faces = self.detector.detect_faces(frame)
        if raw_faces is None or len(raw_faces) == 0:
            if self.tracker:
                self.tracker.update([])
            return results
            
        tracked_faces = raw_faces
        if self.tracker:
            tracked_faces = self.tracker.update(raw_faces)
            
            # Cleanup stale tracks from our AI cache
            active_track_ids = {f.get("track_id", -1) for f in tracked_faces}
            stale_tracks = [tid for tid in self._track_info.keys() if tid not in active_track_ids]
            for tid in stale_tracks:
                del self._track_info[tid]

        for face in tracked_faces:
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

            track_id = face.get("track_id", -1)
            info = self._track_info.setdefault(track_id, {}) if track_id > 0 else {}

            # ── Liveness ────────────────────────────────────────
            is_liveness_real = True
            liveness_score = 1.0
            
            if self.liveness:
                if info.get("is_liveness_real") is True:
                    # Cached real from previous frames!
                    is_liveness_real = True
                    liveness_score = info.get("liveness_score", 1.0)
                else:
                    det = {"bbox": {"x": bbox[0], "y": bbox[1], "width": bbox[2], "height": bbox[3]}, "track_id": track_id}
                    res = self.liveness.detect_faces(frame, [det])
                    if res and "liveness" in res[0]:
                        is_liveness_real = res[0]["liveness"].get("is_real", False)
                        liveness_score = res[0]["liveness"].get("confidence", 0.0)
                        
                        if is_liveness_real and track_id > 0:
                            info["is_liveness_real"] = True
                            info["liveness_score"] = liveness_score
                            
            if not is_liveness_real:
                continue

            # ── Recognition ────────────────────────────────────────
            student_id = None
            name = "Unknown"
            similarity = 0.0

            if self.recognizer and templates:
                if "student_id" in info:
                    # Cached recognition!
                    student_id = info["student_id"]
                    name = info["name"]
                    similarity = info["similarity"]
                else:
                    embedding = self.recognizer.embed(frame, face)
                    if embedding is not None:
                        best_dist = float("inf")
                        for t in templates:
                            stored = np.frombuffer(t["embedding"], dtype=np.float32)
                            dist = float(np.linalg.norm(embedding - stored))
                            if dist < best_dist:
                                best_dist = dist
                                student_id = t["student_id"]
                                name = t.get("name", "Anggota")

                        similarity = 1.0 - (best_dist ** 2) / 2.0
                        
                        if student_id is not None and similarity >= RECOGNITION_THRESHOLD and track_id > 0:
                            info["student_id"] = student_id
                            info["name"] = name
                            info["similarity"] = similarity

            recognized = student_id is not None and similarity >= RECOGNITION_THRESHOLD
            is_valid_match = recognized

            # ── Cooldown ────────────────────────────────────────
            if recognized:
                last = self._cooldowns.get(student_id, 0)
                if time.time() - last < RECOGNITION_COOLDOWN_SEC:
                    recognized = False
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
