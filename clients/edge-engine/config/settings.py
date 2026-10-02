"""
config/settings.py — Semua konfigurasi dan path konstanta untuk Edge Engine.
Dibaca dari .env yang dibuat oleh pairing.py.
"""

import os
import sys
import logging

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Paths
ENV_PATH      = os.path.join(BASE_DIR, ".env")
DB_PATH       = os.path.join(BASE_DIR, "local_edge.db")
MODELS_DIR    = os.path.join(BASE_DIR, "assets", "models")

# Camera
CAMERA_INDEX  = 0
FRAME_WIDTH   = 320
FRAME_HEIGHT  = 240

# Detection
FACE_CONF_THRESHOLD   = 0.6
LIVENESS_THRESHOLD    = 0.5
RECOGNITION_THRESHOLD = 0.4  # cosine distance

# Stream server
STREAM_PORT   = 5000

# Hardware
ARDUINO_PORT  = "/dev/ttyACM0"
ARDUINO_BAUD  = 115200

# Cooldown setelah pengenalan berhasil (detik) — cegah bombardir absen
RECOGNITION_COOLDOWN_SEC = 2


def load_env() -> dict:
    """
    Baca konfigurasi dari .env yang dibuat pairing.py.
    Keluar dengan pesan jelas jika belum di-pairing.
    """
    if not os.path.exists(ENV_PATH):
        logging.critical("[!] Perangkat belum di-pairing. Jalankan: python pairing.py")
        sys.exit(1)

    config: dict = {}
    with open(ENV_PATH) as f:
        for line in f:
            line = line.strip()
            if "=" in line and not line.startswith("#"):
                key, _, val = line.partition("=")
                config[key.strip()] = val.strip()

    required = ["SMART_ABSENSI_URL", "SMART_ABSENSI_TOKEN", "SMART_ABSENSI_DEVICE_ID"]
    missing = [k for k in required if not config.get(k)]
    if missing:
        logging.critical(f"[!] .env tidak lengkap: {missing}. Jalankan: python pairing.py")
        sys.exit(1)

    return config
