import cv2
import time
import json
import logging
import serial
import os
import sys
import uuid
import numpy as np

from database import DatabaseManager
from sync_worker import SyncWorker

# Add current directory to path
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from core.models.face_detector.yunet import FaceDetector
# The other models (Liveness, Recognizer, Tracker) would go here
# from core.models.liveness_detector.minifasnet import LivenessDetector
# from core.models.face_recognizer.sface import FaceRecognizer

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

class ArduinoBridge:
    def __init__(self, port='/dev/ttyACM0', baudrate=115200):
        self.port = port
        self.baudrate = baudrate
        self.serial = None
        
    def connect(self):
        try:
            self.serial = serial.Serial(self.port, self.baudrate, timeout=1)
            time.sleep(2) # Wait for auto-reset
            logging.info(f"Connected to Arduino on {self.port}")
            return True
        except Exception as e:
            logging.warning(f"Failed to connect to Arduino: {e}")
            return False
            
    def send_command(self, payload: dict):
        if self.serial and self.serial.is_open:
            try:
                msg = json.dumps(payload) + '\n'
                self.serial.write(msg.encode('utf-8'))
            except Exception as e:
                logging.error(f"Failed to send to Arduino: {e}")

class EdgeEngine:
    def __init__(self):
        self.detector = None
        self.arduino = ArduinoBridge()
        self.db = DatabaseManager()
        # Nanti token dan URL ini dibaca dari .env atau config.json
        self.sync_worker = SyncWorker(self.db, "http://127.0.0.1:8081", "api-token-rahasia")
        self.load_models()
        
    def load_models(self):
        model_dir = os.path.join(os.path.dirname(__file__), "models")
        logging.info("Loading AI Models...")
        self.detector = FaceDetector(model_path=os.path.join(model_dir, "detector.onnx"))
        # Load liveness and recognizer here...
        
    def run(self):
        self.arduino.connect()
        self.arduino.send_command({"cmd": "standby", "msg": "Sistem Siap!"})
        
        self.sync_worker.start()
        
        cap = cv2.VideoCapture(0)
        # Force 320x240 for performance on H6
        cap.set(cv2.CAP_PROP_FRAME_WIDTH, 320)
        cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 240)
        
        if not cap.isOpened():
            logging.error("Failed to open /dev/video0")
            return
            
        logging.info("Starting Camera Stream...")
        while True:
            ret, frame = cap.read()
            if not ret:
                break
                
            start_t = time.time()
            
            # 1. Detection
            faces = self.detector.detect_faces(frame)
            
            # 2. Tracking & Liveness (Stubbed for now)
            # 3. Recognition (Stubbed for now)
            
            process_t = (time.time() - start_t) * 1000
            
            if faces and len(faces) > 0:
                # Mock finding a face
                logging.info(f"Detected {len(faces)} faces. [Time: {process_t:.1f}ms]")
                # We would normally match the embedding here
                
                # Simulasi member_id yang dikenali
                dummy_member_id = "123e4567-e89b-12d3-a456-426614174000"
                # AttendanceBatchRequest expects: 'date_format:Y-m-d\TH:i:s\Z'
                now_str = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
                
                # Masukkan ke Outbox lokal (akan disync ke Laravel di background)
                self.db.insert_attendance(
                    id=str(uuid.uuid4()),
                    member_id=dummy_member_id,
                    captured_at=now_str,
                    direction="in",  # Sementara hardcoded, bisa diganti sesuai konfigurasi mode absen
                    score=0.92,      # (match_score)
                    liveness_score=0.98,
                    time_source="rtc"
                )
                
                # Beritahu Arduino untuk membunyikan buzzer & layarnya hijau
                self.arduino.send_command({
                    "cmd": "display", "status": "RECOGNIZED", 
                    "name": "Member", "dept": "Staff", "time": now_str
                })
                self.arduino.send_command({"cmd": "io", "led": "green", "buzzer": "2short"})
                
                # Cooldown agar tidak bombardir absen berkali-kali untuk orang yang sama
                time.sleep(2)
                
            # Sleep slightly to prevent 100% CPU lock on H6
            time.sleep(0.01)

if __name__ == '__main__':
    engine = EdgeEngine()
    engine.run()
