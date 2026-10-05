"""
services/stream_service.py — Server MJPEG stream via Flask.

Menggantikan web_stream.py dan web_ui_optional.py (digabung jadi satu).
Berjalan di thread daemon terpisah; route /video_feed konsumsi oleh
Dashboard Laravel (img src) maupun browser lokal via HDMI.
"""

import logging

logger = logging.getLogger(__name__)
import threading
from collections import deque

import cv2
from flask import Flask, Response, jsonify, render_template_string

from config.settings import STREAM_PORT

app = Flask(__name__)

_lock = threading.Lock()
_latest_frame = None
_latest_status = ""
_history: deque = deque(maxlen=10)  # log 10 absensi terakhir


# ─── Public API (dipanggil dari detection pipeline) ─────────────────────────


def push_frame(frame, status: str = "") -> None:
    """Update frame + status teks yang akan di-stream."""
    global _latest_frame, _latest_status
    with _lock:
        _latest_frame = frame.copy() if frame is not None else None
        _latest_status = status


def push_attendance(record: dict) -> None:
    """Tambahkan catatan absensi ke history ring-buffer."""
    with _lock:
        _history.appendleft(record)


# ─── Flask Routes ────────────────────────────────────────────────────────────


def _generate_mjpeg():
    while True:
        with _lock:
            if _latest_frame is None:
                continue
            frame = _latest_frame.copy()
            status = _latest_status

        if status:
            # Blue text (255, 0, 0), font scale 0.5, thickness 1
            cv2.putText(
                frame, status, (10, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 0, 0), 1
            )

        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 70])
        if not ok:
            continue

        yield (
            b"--frame\r\n" b"Content-Type: image/jpeg\r\n\r\n" + buf.tobytes() + b"\r\n"
        )


@app.route("/video_feed")
def video_feed():
    return Response(
        _generate_mjpeg(), mimetype="multipart/x-mixed-replace; boundary=frame"
    )


@app.route("/status")
def status():
    with _lock:
        return jsonify({"status": "ok", "recognition": _latest_status})


@app.route("/history")
def history():
    with _lock:
        return jsonify(list(_history))


@app.route("/auto-pair", methods=["POST"])
def auto_pair():
    """
    Dipanggil dari Laravel Dashboard.
    Terima pairing_code + backend_url, lalu langsung exchange ke Laravel /api/v1/devices/pair
    sehingga perangkat terdaftar penuh tanpa perlu input terminal sama sekali.
    """
    import os
    import platform
    import requests as _requests

    from flask import request
    from config.settings import ROOT_DIR

    data = request.json
    if not data or not data.get("backend_url") or not data.get("pairing_code"):
        return jsonify({"success": False, "error": "Invalid payload: butuh backend_url dan pairing_code"}), 400

    backend_url  = data["backend_url"].rstrip("/")
    pairing_code = data["pairing_code"].strip().upper()

    # --- Tentukan nama perangkat secara otomatis ---
    hostname = platform.node()
    system   = platform.system()
    try:
        with open("/sys/firmware/devicetree/base/model", "r") as f:
            model       = f.read().replace("\x00", "").strip().replace(" ", "")
            device_name = f"{model}-{hostname}"
    except Exception:
        device_name = f"{system}-{hostname}"

    # --- Panggil balik Laravel untuk exchange code → token ---
    try:
        resp = _requests.post(
            f"{backend_url}/api/v1/devices/pair",
            json={
                "code":          pairing_code,
                "device_name":   device_name,
                "fw_version":    "1.0.0",
                "model_version": "yunet-2303",
            },
            timeout=10,
        )
        resp.raise_for_status()
        result = resp.json()
    except Exception as e:
        logger.error(f"[Auto-Pair] Gagal exchange pairing code: {e}")
        return jsonify({"success": False, "error": "Gagal exchange pairing code: Internal error"}), 500

    token     = result.get("token")
    device_id = result.get("device_id")

    if not token or not device_id:
        return jsonify({"success": False, "error": "Respons pairing tidak valid dari server"}), 500

    # --- Simpan ke .env ---
    env_path = os.path.join(ROOT_DIR, ".env")
    content  = (
        f"SMART_ABSENSI_URL={backend_url}\n"
        f"SMART_ABSENSI_TOKEN={token}\n"
        f"SMART_ABSENSI_DEVICE_ID={device_id}\n"
    )
    try:
        with open(env_path, "w") as f:
            f.write(content)
        os.chmod(env_path, 0o600)
    except Exception as e:
        logger.error(f"[Auto-Pair] Gagal simpan .env: {e}")
        return jsonify({"success": False, "error": "Gagal menyimpan konfigurasi: Internal error"}), 500

    logger.info(f"[Auto-Pair] Berhasil! Device ID={device_id} Server={backend_url}")

    return jsonify({
        "success":   True,
        "device_id": device_id,
        "message":   f"Perangkat {device_name} berhasil dipairing sebagai {device_id}.",
    })


@app.route("/auto-update", methods=["POST"])
def auto_update():
    """Endpoint untuk trigger OTA update dari Laravel."""
    import os
    import subprocess
    import hashlib

    from flask import request

    from config.settings import ROOT_DIR, load_env

    try:
        cfg = load_env()
    except Exception as e:
        return jsonify({"success": False, "error": "Unpaired device"}), 403

    # 1. Authorization Check
    auth_header = request.headers.get("Authorization")
    expected_token = f"Bearer {cfg.get('SMART_ABSENSI_TOKEN')}"
    if auth_header != expected_token:
        return jsonify({"success": False, "error": "Unauthorized"}), 401

    data = request.json
    if not data or not data.get("download_url"):
        return jsonify({"success": False, "error": "Invalid payload"}), 400

    url = data["download_url"]
    
    # 2. Strict HTTPS Enforcement
    if not url.startswith("https://"):
        return jsonify({"success": False, "error": "Insecure download URL. HTTPS required."}), 400

    # 3. Checksum Requirements
    checksum = data.get("checksum", "")
    if not checksum or len(checksum) != 64:
        return jsonify({"success": False, "error": "Valid SHA-256 checksum is required."}), 400

    update_type = data.get("update_type", "binary")  # "binary" or "model"
    updater_script = os.path.join(ROOT_DIR, "updater.sh")

    if not os.path.exists(updater_script):
        return (
            jsonify(
                {"success": False, "error": "updater.sh tidak ditemukan di perangkat"}
            ),
            404,
        )

    try:
        # Jalankan updater di background agar response API tidak nge-hang saat service direstart
        subprocess.Popen(
            ["sudo", "bash", updater_script, url, checksum, update_type],
            start_new_session=True,
        )
        return jsonify(
            {
                "success": True,
                "message": f"OTA Update ({update_type}) dimulai. Perangkat akan merestart otomatis.",
            }
        )
    except Exception as e:  # noqa: BLE001
        logger.error(f"[OTA] Gagal menjalankan updater: {e}")
        return jsonify({"success": False, "error": "Internal error saat menjalankan updater"}), 500

@app.route("/")
def kiosk():
    """Halaman HTML sederhana untuk layar HDMI langsung di Orange Pi."""
    html = """
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Smart Absensi — Live</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { background: #f8fafc; font-family: Inter, sans-serif; display: flex; height: 100vh; }
            .cam  { flex: 2; background: #000; display: flex; align-items: center; justify-content: center; }
            .cam img { max-width: 100%; max-height: 100%; object-fit: contain; }
            .log  { flex: 1; padding: 1rem; overflow-y: auto; border-left: 1px solid #e2e8f0; background: #fff; }
            .log h2 { font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.75rem; }
            .item { font-size: 0.8125rem; padding: 0.5rem; border-bottom: 1px solid #f1f5f9; }
            .item .name { font-weight: 600; color: #1e293b; }
            .item .time { color: #94a3b8; font-size: 0.75rem; }
        </style>
    </head>
    <body>
        <div class="cam"><img src="/video_feed" alt="Live"></div>
        <div class="log">
            <h2>Log Terbaru</h2>
            <div id="items"></div>
        </div>
        <script>
            setInterval(() => {
                fetch('/history').then(r => r.json()).then(data => {
                    document.getElementById('items').innerHTML = data.map(d =>
                        `<div class="item"><div class="name">${d.name || '-'}</div><div class="time">${d.captured_at || ''} · ${d.direction || ''}</div></div>`
                    ).join('');
                });
            }, 2000);
        </script>
    </body>
    </html>
    """
    return render_template_string(html)


# ─── Start ───────────────────────────────────────────────────────────────────


def start(port: int = STREAM_PORT) -> None:
    logger.info(f"[Stream] Memulai server MJPEG di port {port}")
    app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False, threaded=True)
