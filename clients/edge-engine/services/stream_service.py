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
