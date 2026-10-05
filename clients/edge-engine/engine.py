"""
engine.py — Entry point Edge Engine Smart Absensi.

Orkestrasi:
  - Config (.env) → pairing.py
  - Camera loop → services/detection_pipeline.py
  - Arduino output → hardware/arduino.py
  - Web stream → services/stream_service.py
  - DB + Sync → database.py + sync_worker.py
"""

import logging

logger = logging.getLogger(__name__)

# Uncomment when models are ready:
# from core.models.liveness_detector.detector import LivenessDetector
# from core.models.face_recognizer.recognizer import FaceRecognizer
import os
import sys

# Tambahkan path 'packages' agar face_core bisa di-import
_ROOT_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
_PACKAGES_DIR = os.path.join(_ROOT_DIR, "packages")
if _PACKAGES_DIR not in sys.path:
    sys.path.insert(0, _PACKAGES_DIR)

import threading
import time
import uuid

import cv2

import services.stream_service as stream
from config.settings import (
    CAMERA_INDEX,
    FRAME_HEIGHT,
    FRAME_WIDTH,
    MODELS_DIR,
    STREAM_PORT,
    load_env,
)
from core.models import FaceDetector
from database import DatabaseManager
from hardware.arduino import ArduinoBridge
from services.detection_pipeline import DetectionPipeline
from sync_worker import SyncWorker


class EdgeEngine:
    def __init__(self, config: dict):
        self.config = config

        # ── Hardware ───────────────────────────────────────────────────
        self.arduino = ArduinoBridge()

        # ── Persistence & Sync ────────────────────────────────────────
        key_b64 = config.get("ENROLLMENT_EMBED_KEY")
        self.db = DatabaseManager(key_b64=key_b64)
        self.sync_worker = SyncWorker(
            self.db,
            config["SMART_ABSENSI_URL"],
            config["SMART_ABSENSI_TOKEN"],
        )

        # ── AI Models ─────────────────────────────────────────────────
        detector = FaceDetector(
            model_path=os.path.join(MODELS_DIR, "detector.onnx"),
            input_size=(FRAME_WIDTH, FRAME_HEIGHT),
            conf_threshold=0.6,
            nms_threshold=0.3,
            top_k=5000,
            min_face_size=20,
        )

        class SimpleRecognizer:
            def __init__(self, path):
                from face_core.session_utils import init_face_recognizer_session
                self.session, self.input_name = init_face_recognizer_session(path)
            
            def embed(self, frame, face_dict):
                from face_core.preprocess import align_faces_batch, preprocess_batch
                import numpy as np
                aligned = align_faces_batch(frame, [face_dict], (112, 112))
                if not aligned:
                    return None
                tensor = preprocess_batch(aligned)
                out = self.session.run(None, {self.input_name: tensor})[0][0]
                norm = np.linalg.norm(out)
                return out / norm if norm > 0 else out

        try:
            recognizer = SimpleRecognizer(os.path.join(MODELS_DIR, "recognizer.onnx"))
        except Exception as e:
            logger.error(f"Failed to load recognizer: {e}")
            recognizer = None

        self.pipeline = DetectionPipeline(
            detector=detector,
            recognizer=recognizer,
            liveness=None,  # stub — ganti dengan LivenessDetector()
        )

        # ── UDP Auto-Discovery ────────────────────────────────────────
        import services.discovery_service as discovery

        discovery.start()

        # ── Web Stream (daemon thread) ────────────────────────────────
        threading.Thread(
            target=stream.start, kwargs={"port": STREAM_PORT}, daemon=True
        ).start()

    def run(self) -> None:
        # ── Arduino Handshake ────────────────────────────────────────
        self.arduino.connect()
        self.arduino.standby()

        # ── Background Sync ──────────────────────────────────────────
        self.sync_worker.start()

        # ── Camera Loop ──────────────────────────────────────────────
        cap = cv2.VideoCapture(CAMERA_INDEX)
        cap.set(cv2.CAP_PROP_FRAME_WIDTH, FRAME_WIDTH)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, FRAME_HEIGHT)

        if not cap.isOpened():
            logger.error(f"Gagal membuka kamera (index {CAMERA_INDEX})")
            return

        logger.info("Kamera aktif. Mulai memindai wajah…")

        frame_id = 0
        while True:
            frame_id += 1
            ret, frame = cap.read()
            if not ret:
                logger.warning("Frame tidak terbaca — kamera mungkin dicabut.")
                break

            # Ambil template lokal terbaru (cached setiap loop)
            templates = self.db.get_all_templates()

            t0 = time.time()
            results = self.pipeline.process(frame, templates)
            ms = (time.time() - t0) * 1000

            for r in results:
                # Gambar bounding box tipis warna merah (0, 0, 255)
                x, y, w, h = r.bbox
                cv2.rectangle(frame, (x, y), (x + w, y + h), (0, 0, 255), 1)
                
                # Teks score warna kuning (0, 255, 255)
                cv2.putText(frame, f"score:{r.confidence:.2f}", (x, y + h - 15), cv2.FONT_HERSHEY_SIMPLEX, 0.4, (0, 255, 255), 1)
                
                # Teks id warna merah (0, 0, 255)
                display_name = r.name if r.name else "Unknown"
                cv2.putText(frame, f"id:{display_name}", (x, y + h - 2), cv2.FONT_HERSHEY_SIMPLEX, 0.4, (0, 0, 255), 1)

                if r.recognized:
                    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

                    # Simpan ke outbox lokal
                    self.db.insert_attendance(
                        id=str(uuid.uuid4()),
                        student_id=r.student_id,
                        captured_at=now_str,
                        direction="in",
                        score=r.confidence,
                        liveness_score=r.liveness_score,
                        time_source="rtc",
                    )

                    # Push ke stream history
                    stream.push_attendance(
                        {
                            "student_id": r.student_id,
                            "name": r.name,
                            "captured_at": now_str,
                            "direction": "in",
                        }
                    )

                    # Respons fisik Arduino
                    self.arduino.recognized(r.name, "", now_str)

                    logger.info(
                        f"[✓] {r.name} dikenali  conf={r.confidence:.3f}  [{ms:.0f}ms]"
                    )

            fps = 1000 / ms if ms > 0 else 0
            status = f"FRAMEID= {frame_id} FPS: {fps:.2f}"
            stream.push_frame(frame, status)

            time.sleep(0.01)  # Hindari CPU lock 100% di H6

        cap.release()


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s  %(levelname)-7s  %(message)s",
    )

    cfg = load_env()
    logger.info(
        f"[✓] Perangkat {cfg['SMART_ABSENSI_DEVICE_ID']} → {cfg['SMART_ABSENSI_URL']}"
    )

    try:
        EdgeEngine(cfg).run()
    except KeyboardInterrupt:
        logger.info("Menerima sinyal berhenti (Ctrl+C). Keluar dengan aman...")
        sys.exit(0)
