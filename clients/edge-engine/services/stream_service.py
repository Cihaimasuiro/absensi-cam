"""
services/stream_service.py — Server MJPEG stream via Flask.

Menggantikan web_stream.py dan web_ui_optional.py (digabung jadi satu).
Berjalan di thread daemon terpisah; route /video_feed konsumsi oleh
Dashboard Laravel (img src) maupun browser lokal via HDMI.
"""

import threading
import logging
import cv2
from collections import deque
from flask import Flask, Response, jsonify, render_template_string

from config.settings import STREAM_PORT

app = Flask(__name__)

_lock          = threading.Lock()
_latest_frame  = None
_latest_status = ""
_history: deque = deque(maxlen=10)   # log 10 absensi terakhir


# ─── Public API (dipanggil dari detection pipeline) ─────────────────────────

def push_frame(frame, status: str = "") -> None:
    """Update frame + status teks yang akan di-stream."""
    global _latest_frame, _latest_status
    with _lock:
        _latest_frame  = frame.copy() if frame is not None else None
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
            cv2.putText(frame, status, (10, 30),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)

        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 70])
        if not ok:
            continue

        yield (b"--frame\r\n"
               b"Content-Type: image/jpeg\r\n\r\n" + buf.tobytes() + b"\r\n")


@app.route("/video_feed")
def video_feed():
    return Response(_generate_mjpeg(),
                    mimetype="multipart/x-mixed-replace; boundary=frame")


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
    """Endpoint untuk menerima konfigurasi pairing dari Laravel backend."""
    from flask import request
    import os
    from config.settings import ROOT_DIR
    
    data = request.json
    if not data or not data.get("backend_url") or not data.get("token"):
        return jsonify({"success": False, "error": "Invalid payload"}), 400
        
    env_path = os.path.join(ROOT_DIR, ".env")
    
    try:
        # Baca existing env dan override baris yang sesuai
        lines = []
        if os.path.exists(env_path):
            with open(env_path, "r") as f:
                lines = f.readlines()
                
        new_lines = []
        url_updated = False
        token_updated = False
        
        for line in lines:
            if line.startswith("SMART_ABSENSI_URL="):
                new_lines.append(f"SMART_ABSENSI_URL={data['backend_url']}\n")
                url_updated = True
            elif line.startswith("SMART_ABSENSI_TOKEN="):
                new_lines.append(f"SMART_ABSENSI_TOKEN={data['token']}\n")
                token_updated = True
            else:
                new_lines.append(line)
                
        if not url_updated:
            new_lines.append(f"SMART_ABSENSI_URL={data['backend_url']}\n")
        if not token_updated:
            new_lines.append(f"SMART_ABSENSI_TOKEN={data['token']}\n")
            
        with open(env_path, "w") as f:
            f.writelines(new_lines)
            
        logging.info(f"[Auto-Pair] Konfigurasi berhasil disimpan. Backend: {data['backend_url']}")
        
        # Opsi: Secara ideal kita perlu merestart service systemd agar env termuat ulang.
        # Untuk kepraktisan, kita bisa merestart worker atau menginstruksikan systemctl
        import subprocess
        threading.Timer(1.0, lambda: subprocess.Popen(["sudo", "systemctl", "restart", "absensi-edge"])).start()
        
        return jsonify({"success": True, "message": "Paired successfully. Rebooting engine..."})
        
    except Exception as e:
        logging.error(f"[Auto-Pair] Error: {e}")
        return jsonify({"success": False, "error": str(e)}), 500


@app.route("/auto-update", methods=["POST"])
def auto_update():
    """Endpoint untuk trigger OTA update dari Laravel."""
    from flask import request
    import subprocess
    import os
    from config.settings import ROOT_DIR
    
    data = request.json
    if not data or not data.get("download_url"):
        return jsonify({"success": False, "error": "Invalid payload"}), 400
        
    url = data["download_url"]
    checksum = data.get("checksum", "")
    updater_script = os.path.join(ROOT_DIR, "updater.sh")
    
    if not os.path.exists(updater_script):
        return jsonify({"success": False, "error": "updater.sh tidak ditemukan di perangkat"}), 404
        
    try:
        # Jalankan updater di background agar response API tidak nge-hang saat service direstart
        subprocess.Popen(["sudo", "bash", updater_script, url, checksum], start_new_session=True)
        return jsonify({"success": True, "message": "OTA Update dimulai. Perangkat akan merestart otomatis."})
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


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
    logging.info(f"[Stream] Memulai server MJPEG di port {port}")
    app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False, threaded=True)
