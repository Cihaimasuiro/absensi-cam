import cv2
import threading
from flask import Flask, Response, render_template_string, jsonify
import json
from collections import deque

app = Flask(__name__)

# Global state for web UI
current_frame = None
# Circular buffer for last 5 attendance logs
attendance_history = deque(maxlen=5)

# Tampilan HTML Sederhana untuk Layar Kiosk HDMI
html_template = """
<!DOCTYPE html>
<html>
<head>
    <title>Smart Absensi - Live Scan</title>
    <style>
        body { background: #0d1117; color: white; font-family: Arial, sans-serif; margin: 0; padding: 20px; display: flex; gap: 20px; }
        .video-container { flex: 2; text-align: center; }
        .video-container img { width: 100%; max-width: 640px; border-radius: 8px; border: 2px solid #30363d; }
        .log-container { flex: 1; background: #161b22; padding: 15px; border-radius: 8px; border: 1px solid #30363d; }
        .log-item { background: #21262d; margin-bottom: 10px; padding: 10px; border-left: 4px solid #238636; border-radius: 4px; }
        h1, h2 { color: #c9d1d9; }
    </style>
</head>
<body>
    <div class="video-container">
        <h1>Kamera Aktif</h1>
        <img src="/video_feed" alt="Video Stream">
    </div>
    <div class="log-container">
        <h2>Riwayat Absensi</h2>
        <div id="logs"></div>
    </div>
    <script>
        // Update tabel history setiap 1 detik
        setInterval(() => {
            fetch('/history')
                .then(response => response.json())
                .then(data => {
                    const logsDiv = document.getElementById('logs');
                    logsDiv.innerHTML = '';
                    data.forEach(item => {
                        logsDiv.innerHTML += `<div class="log-item">
                            <strong>${item.name}</strong><br>
                            <small>${item.time}</small>
                        </div>`;
                    });
                });
        }, 1000);
    </script>
</body>
</html>
"""

def generate_mjpeg():
    global current_frame
    while True:
        if current_frame is not None:
            # Encode frame ke JPEG untuk stream web
            ret, buffer = cv2.imencode('.jpg', current_frame)
            frame_bytes = buffer.tobytes()
            yield (b'--frame\r\n'
                   b'Content-Type: image/jpeg\r\n\r\n' + frame_bytes + b'\r\n')

@app.route('/')
def index():
    return render_template_string(html_template)

@app.route('/video_feed')
def video_feed():
    return Response(generate_mjpeg(), mimetype='multipart/x-mixed-replace; boundary=frame')

@app.route('/history')
def history():
    # Mengembalikan history absensi terbaru ke Web Browser
    return jsonify(list(attendance_history))

def start_web_server():
    # Menjalankan server Flask di thread latar belakang agar tidak mengganggu kamera AI
    app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False)

def add_to_web_history(name, time):
    attendance_history.appendleft({"name": name, "time": time})

# CATATAN PENGGUNAAN:
# Panggil `start_web_server()` di dalam fungsi run() di engine.py sebagai thread terpisah:
# web_thread = threading.Thread(target=start_web_server, daemon=True)
# web_thread.start()
#
# Lalu di loop kamera engine.py, update `current_frame` dengan frame dari cv2.VideoCapture.
