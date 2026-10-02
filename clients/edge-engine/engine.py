"""
engine.py — Entry point Edge Engine Smart Absensi.

Orkestrasi:
  - Config (.env) → pairing.py
  - Camera loop → services/detection_pipeline.py
  - Arduino output → hardware/arduino.py
  - Web stream → services/stream_service.py
  - DB + Sync → database.py + sync_worker.py
"""

import cv2
import time
import uuid
import logging
import threading

from config.settings import (
    CAMERA_INDEX, FRAME_WIDTH, FRAME_HEIGHT,
    MODELS_DIR, STREAM_PORT, load_env,
)
from database import DatabaseManager
from sync_worker import SyncWorker
from hardware.arduino import ArduinoBridge
from services.detection_pipeline import DetectionPipeline
import services.stream_service as stream

from core.models.face_detector.detector import FaceDetector
# Uncomment when models are ready:
# from core.models.liveness_detector.detector import LivenessDetector
# from core.models.face_recognizer.recognizer import FaceRecognizer

import os


class EdgeEngine:
    def __init__(self, config: dict):
        self.config = config

        # ── Hardware ───────────────────────────────────────────────────
        self.arduino = ArduinoBridge()

        # ── Persistence & Sync ────────────────────────────────────────
        self.db = DatabaseManager()
        self.sync_worker = SyncWorker(
            self.db,
            config["SMART_ABSENSI_URL"],
            config["SMART_ABSENSI_TOKEN"],
        )

        # ── AI Models ─────────────────────────────────────────────────
        detector = FaceDetector(
            model_path=os.path.join(MODELS_DIR, "detector.onnx")
        )
        # liveness   = LivenessDetector(os.path.join(MODELS_DIR, "liveness.onnx"))
        # recognizer = FaceRecognizer(os.path.join(MODELS_DIR, "recognizer.onnx"))
        self.pipeline = DetectionPipeline(
            detector   = detector,
            recognizer = None,   # stub — ganti dengan FaceRecognizer()
            liveness   = None,   # stub — ganti dengan LivenessDetector()
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
        cap.set(cv2.CAP_PROP_FRAME_WIDTH,  FRAME_WIDTH)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, FRAME_HEIGHT)

        if not cap.isOpened():
            logging.error(f"Gagal membuka kamera (index {CAMERA_INDEX})")
            return

        logging.info("Kamera aktif. Mulai memindai wajah…")

        while True:
            ret, frame = cap.read()
            if not ret:
                logging.warning("Frame tidak terbaca — kamera mungkin dicabut.")
                break

            # Ambil template lokal terbaru (cached setiap loop)
            templates = self.db.get_all_templates()

            t0      = time.time()
            results = self.pipeline.process(frame, templates)
            ms      = (time.time() - t0) * 1000

            for r in results:
                # Gambar bounding box
                x, y, w, h = r.bbox
                color = (0, 255, 0) if r.recognized else (0, 0, 255)
                cv2.rectangle(frame, (x, y), (x + w, y + h), color, 2)

                if r.recognized:
                    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

                    # Simpan ke outbox lokal
                    self.db.insert_attendance(
                        id            = str(uuid.uuid4()),
                        student_id    = r.student_id,
                        captured_at   = now_str,
                        direction     = "in",
                        score         = r.confidence,
                        liveness_score= r.liveness_score,
                        time_source   = "rtc",
                    )

                    # Push ke stream history
                    stream.push_attendance({
                        "student_id": r.student_id,
                        "name":       r.name,
                        "captured_at": now_str,
                        "direction":  "in",
                    })

                    # Respons fisik Arduino
                    self.arduino.recognized(r.name, "", now_str)

                    logging.info(
                        f"[✓] {r.name} dikenali  conf={r.confidence:.3f}  [{ms:.0f}ms]"
                    )

            status = f"{len(results)} wajah | {ms:.0f}ms" if results else "Scanning…"
            stream.push_frame(frame, status)

            time.sleep(0.01)   # Hindari CPU lock 100% di H6

        cap.release()


if __name__ == "__main__":
    logging.basicConfig(
        level  = logging.INFO,
        format = "%(asctime)s  %(levelname)-7s  %(message)s",
    )

    cfg = load_env()
    logging.info(
        f"[✓] Perangkat {cfg['SMART_ABSENSI_DEVICE_ID']} → {cfg['SMART_ABSENSI_URL']}"
    )

    EdgeEngine(cfg).run()
