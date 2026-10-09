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

    def process(self, frame: np.ndarray, templates: list[dict], use_detector: bool = True, liveness_enabled: bool = True) -> list[RecognitionResult]:
        results: list[RecognitionResult] = []

        if use_detector:
            raw_faces = self.detector.detect_faces(frame)
            if raw_faces is None or len(raw_faces) == 0:
                if self.tracker:
                    self.tracker.update([])
                return results
            
            tracked_faces = raw_faces
            if self.tracker:
                tracked_faces = self.tracker.update(raw_faces)
        else:
            if self.tracker:
                tracked_faces = self.tracker.predict_only()
                if not tracked_faces:
                    return results
            else:
                return results
            
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
            liveness_score = 1.0
            is_liveness_real = True
            now = time.time()
            if liveness_enabled and self.liveness is not None:
                if info.get("liveness_verified", False) and now - info.get("liveness_time", 0) < 3.0:
                    is_liveness_real = True
                    liveness_score = info.get("liveness_score", 1.0)
                elif use_detector:
                    det = {"bbox": {"x": bbox[0], "y": bbox[1], "width": bbox[2], "height": bbox[3]}, "track_id": track_id}
                    res = self.liveness.detect_faces(frame, [det])
                    if res and "liveness" in res[0]:
                        is_real_now = res[0]["liveness"].get("is_real", False)
                        logit_diff = res[0]["liveness"].get("logit_diff", 0.0)
                        raw_conf = res[0]["liveness"].get("prob_real", res[0]["liveness"].get("confidence", 1.0))
                        liveness_score = float(np.clip(raw_conf, 0.0, 1.0))
                        
                        # Face Size Limit Check (Max 40% of frame)
                        fh, fw = frame.shape[:2]
                        face_area = bbox[2] * bbox[3]
                        frame_area = fh * fw
                        size_ratio = face_area / frame_area
                        
                        if size_ratio > 0.40:
                            is_liveness_real = False
                            info["liveness_count"] = 0
                            info["liveness_rejected_reason"] = "Face too close/large"
                        else:
                            if liveness_score >= LIVENESS_THRESHOLD:
                                info["liveness_count"] = info.get("liveness_count", 0) + 1
                            else:
                                info["liveness_count"] = 0
                                
                            # Require at least 2 consecutive passing frames to avoid 1-frame spoof spikes
                            # (Since FPS is ~0.6 to 1.5, 2 frames is 1-3 seconds, maintaining walk-in feel)
                            if info.get("liveness_count", 0) >= 2:
                                is_liveness_real = True
                                if track_id > 0:
                                    info["liveness_verified"] = True
                                    info["liveness_score"] = liveness_score
                                    info["liveness_time"] = now
                            else:
                                is_liveness_real = False

            # ── Recognition ────────────────────────────────────────
            student_id = None
            name = "Unknown"
            similarity = 0.0

            if self.recognizer and templates:
                if info.get("student_id") is not None and now - info.get("recognition_time", 0) < 2.0:
                    # Cached recognition!
                    student_id = info["student_id"]
                    name = info["name"]
                    similarity = info["similarity"]
                elif use_detector:
                    embedding = self.recognizer.embed(frame, face)
                    if embedding is not None:
                        best_dist = float("inf")
                        best_t = None
                        for t in templates:
                            stored = np.frombuffer(t["embedding"], dtype=np.float32)
                            dist = float(np.linalg.norm(embedding - stored))
                            if dist < best_dist:
                                best_dist = dist
                                best_t = t

                        if best_t is not None:
                            # Cosine similarity for unit vectors: 1 - d^2 / 2
                            similarity = float(np.clip(1.0 - (best_dist ** 2) / 2.0, 0.0, 1.0))
                            if similarity >= RECOGNITION_THRESHOLD:
                                student_id = best_t["student_id"]
                                name = best_t.get("name", "Anggota")
                                if track_id > 0:
                                    info["student_id"] = student_id
                                    info["name"] = name
                                    info["similarity"] = similarity
                                    info["recognition_time"] = now

            is_valid_match = (student_id is not None and similarity >= RECOGNITION_THRESHOLD)
            
            # Attendance dicatat jika wajah cocok (valid match)
            recognized = is_valid_match and (not liveness_enabled or is_liveness_real)

            # ── Cooldown ────────────────────────────────────────
            if recognized:
                last = self._cooldowns.get(student_id, 0)
                if time.time() - last < RECOGNITION_COOLDOWN_SEC:
                    recognized = False
                else:
                    self._cooldowns[student_id] = time.time()

            display_name = name if is_valid_match else "Unknown"

            results.append(
                RecognitionResult(
                    student_id=student_id if is_valid_match else None,
                    name=display_name,
                    confidence=similarity,
                    liveness_score=liveness_score,
                    bbox=bbox,
                    recognized=recognized,
                )
            )

        return results
