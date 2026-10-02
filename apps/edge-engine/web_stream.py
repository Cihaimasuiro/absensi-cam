import cv2
import threading
from flask import Flask, Response, jsonify

app = Flask(__name__)

# Global variable to store the latest frame and recognition text
latest_frame = None
latest_status_text = ""
frame_lock = threading.Lock()

def update_frame(frame, status_text=""):
    global latest_frame, latest_status_text
    with frame_lock:
        latest_frame = frame.copy() if frame is not None else None
        latest_status_text = status_text

def generate_mjpeg():
    global latest_frame, latest_status_text
    while True:
        with frame_lock:
            if latest_frame is None:
                continue
            
            # Copy frame to add overlay without affecting original
            display_frame = latest_frame.copy()
            
            # Add status text if any
            if latest_status_text:
                cv2.putText(display_frame, latest_status_text, (10, 30), 
                            cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 255, 0), 2)
            
            # Encode frame to JPEG
            ret, buffer = cv2.imencode('.jpg', display_frame)
            if not ret:
                continue
                
            frame_bytes = buffer.tobytes()
            
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + frame_bytes + b'\r\n')

@app.route('/video_feed')
def video_feed():
    return Response(generate_mjpeg(), mimetype='multipart/x-mixed-replace; boundary=frame')

@app.route('/status')
def status():
    return jsonify({
        "status": "ok",
        "latest_recognition": latest_status_text
    })

def start_server(port=5000):
    app.run(host='0.0.0.0', port=port, debug=False, use_reloader=False)
