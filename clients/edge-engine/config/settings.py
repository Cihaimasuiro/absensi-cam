"""
config/settings.py — Semua konfigurasi dan path konstanta untuk Edge Engine.
Dibaca dari .env yang dibuat oleh pairing.py.
"""

import os
import sys
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Paths
ENV_PATH = os.path.join(BASE_DIR, ".env")
DB_PATH = os.path.join(BASE_DIR, "local_edge.db")

_default_models_dir = os.path.join(BASE_DIR, "models")
_monorepo_models_dir = os.path.join(os.path.dirname(os.path.dirname(BASE_DIR)), "packages", "models")
if not os.path.exists(_default_models_dir) and os.path.exists(_monorepo_models_dir):
    _default_models_dir = _monorepo_models_dir

MODELS_DIR = os.environ.get("MODELS_DIR", _default_models_dir)
DEFAULT_MODEL_VERSION = "3d9f1f77896fb3d1"

# Camera
CAMERA_INDEX = 0
FRAME_WIDTH = 320
FRAME_HEIGHT = 240

# Detection
FACE_CONF_THRESHOLD = 0.6
LIVENESS_THRESHOLD = 0.5
RECOGNITION_THRESHOLD = 0.4  # cosine distance

# Stream server
STREAM_PORT = 5000

# Hardware
ARDUINO_PORT = "/dev/ttyACM0"
ARDUINO_BAUD = 115200

# Cooldown setelah pengenalan berhasil (detik) — cegah bombardir absen
RECOGNITION_COOLDOWN_SEC = 2


def load_env() -> dict:
    """
    Baca konfigurasi dari .env yang dibuat pairing.py.
    Keluar dengan pesan jelas jika belum di-pairing.
    """
    if not os.path.exists(ENV_PATH):
        logger.critical("[!] Perangkat belum di-pairing. Jalankan: python pairing.py")
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
        logger.critical(
            f"[!] .env tidak lengkap: {missing}. Jalankan: python pairing.py"
        )
        sys.exit(1)

    return config
