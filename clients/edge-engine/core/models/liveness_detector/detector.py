"""
Clean room implementation of Face Liveness Detector.
"""

import cv2
import numpy as np
import onnxruntime as ort

class TrackLivenessMemory:
    def __init__(self, required_real_frames: int):
        self.history = {}
        self.required_real_frames = required_real_frames

    def stabilize(self, track_id, current_liveness, frame_counter, namespace=None, person_id=None):
        if track_id <= 0:
            return current_liveness
            
        key = (namespace or "__global__", track_id)
        if key not in self.history:
            self.history[key] = {"real_count": 0, "last_frame": frame_counter}
            
        rec = self.history[key]
        rec["last_frame"] = frame_counter
        
        if current_liveness.get("is_real"):
            rec["real_count"] += 1
        else:
            rec["real_count"] = max(0, rec["real_count"] - 1)
            
        stable_real = rec["real_count"] >= self.required_real_frames
        
        result = current_liveness.copy()
        if stable_real:
            result["is_real"] = True
            result["status"] = "real"
            result["message"] = "Liveness Verified"
            result["active_verified"] = True
        return result

    def is_stable_real(self, track_id, namespace=None):
        if track_id <= 0:
            return False
        key = (namespace or "__global__", track_id)
        rec = self.history.get(key)
        if not rec:
            return False
        return rec["real_count"] >= self.required_real_frames

    def cleanup_stale_tracks(self, frame_counter, namespace=None):
        ns = namespace or "__global__"
        stale = [k for k, v in self.history.items() if k[0] == ns and frame_counter - v["last_frame"] > 30]
        for k in stale:
            del self.history[k]
        return stale

    def clear_namespace(self, namespace=None):
        ns = namespace or "__global__"
        keys = [k for k in self.history.keys() if k[0] == ns]
        for k in keys:
            del self.history[k]


class LivenessDetector:
    def __init__(
        self,
        model_path: str,
        pass_margin: float = 2.197,
        spoof_margin: float = 0.00,
        required_real_frames: int = 3,
        model_img_size: int = 256,
    ):
        self.model_img_size = model_img_size
        self.pass_margin = pass_margin
        self.spoof_margin = spoof_margin
        self.ort_session = ort.InferenceSession(model_path, providers=['CPUExecutionProvider'])
        self.track_memory = TrackLivenessMemory(required_real_frames)
        self.frame_counter = 0

    def clear_namespace(self, namespace: str | None = None):
        self.track_memory.clear_namespace(namespace)

    def update_face_identity(self, track_id: int, person_id: str, current_liveness: dict, namespace: str | None = None) -> dict:
        return self.track_memory.stabilize(track_id, current_liveness, self.frame_counter, namespace, person_id)

    def detect_faces(self, image: np.ndarray, face_detections: list[dict], tracking_namespace: str | None = None) -> list[dict]:
        if not face_detections:
            return []
            
        self.frame_counter += 1
        rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
        h, w = rgb_image.shape[:2]
        
        results = []
        for det in face_detections:
            bbox = det.get("bbox", {})
            bx, by, bw, bh = bbox.get("x", 0), bbox.get("y", 0), bbox.get("width", 0), bbox.get("height", 0)
            
            # Simple margin for crop
            margin_x = int(bw * 0.1)
            margin_y = int(bh * 0.1)
            x1 = max(0, bx - margin_x)
            y1 = max(0, by - margin_y)
            x2 = min(w, bx + bw + margin_x)
            y2 = min(h, by + bh + margin_y)
            
            if x2 <= x1 or y2 <= y1:
                det["liveness"] = self._error_liveness()
                results.append(det)
                continue
                
            crop = rgb_image[y1:y2, x1:x2]
            crop = cv2.resize(crop, (self.model_img_size, self.model_img_size))
            
            # Normalize depending on model, assuming standard mean/std or 0-1
            # Usually Insightface liveness is just simple transpose
            blob = np.transpose(crop, (2, 0, 1)).astype(np.float32)
            blob = np.expand_dims(blob, axis=0)
            
            try:
                inputs = {self.ort_session.get_inputs()[0].name: blob}
                logits = self.ort_session.run(None, inputs)[0][0]
                
                # Assume 2 classes: 0 = spoof, 1 = real (or vice versa, adjust if needed)
                # Typically logits[1] is real, logits[0] is spoof in standard models
                # We'll calculate a logit difference
                # Actually, some models return spoof at 0, real at 1
                spoof_logit, real_logit = float(logits[0]), float(logits[1])
                logit_diff = real_logit - spoof_logit
                
                is_real = logit_diff > self.pass_margin
                is_spoof = logit_diff < self.spoof_margin
                
                liveness = {
                    "is_real": is_real,
                    "is_confirmed_spoof": is_spoof,
                    "status": "real" if is_real else ("spoof" if is_spoof else "analyzing"),
                    "logit_diff": logit_diff,
                    "real_logit": real_logit,
                    "spoof_logit": spoof_logit,
                    "confidence": float(1 / (1 + np.exp(-logit_diff)))
                }
                
                track_id = det.get("track_id", -1)
                stabilized = self.track_memory.stabilize(
                    track_id, 
                    liveness, 
                    self.frame_counter, 
                    namespace=tracking_namespace,
                    person_id=det.get("recognition", {}).get("person_id")
                )
                
                det["liveness"] = stabilized
            except Exception as e:
                det["liveness"] = self._error_liveness()
                
            results.append(det)
            
        self.track_memory.cleanup_stale_tracks(self.frame_counter, namespace=tracking_namespace)
        return results

    def _error_liveness(self):
        return {
            "is_real": False,
            "status": "error",
            "logit_diff": 0.0,
            "real_logit": 0.0,
            "spoof_logit": 0.0,
            "confidence": 0.0
        }
