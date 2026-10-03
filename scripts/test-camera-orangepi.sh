#!/usr/bin/env bash
# =============================================================================
# test-camera-orangepi.sh — Capture a frame and test face detection
# =============================================================================

set -euo pipefail

HOST="192.168.10.50"
USER="root"

echo "============================================================"
echo "  Testing UVC Camera on Orange Pi (/dev/video0)"
echo "============================================================"
echo "You may be prompted for the SSH password ('orangepi')"

ssh "${USER}@${HOST}" bash -s << 'REMOTE'
set -e
export LD_LIBRARY_PATH="/opt/facenox-bench/conda/lib:\${LD_LIBRARY_PATH:-}"
PYTHON_CMD="/opt/facenox-bench/conda/bin/python"

echo "Checking connected USB devices..."
ls -l /dev/video* || echo "NO VIDEO DEVICES FOUND! Did the camera disconnect?"

# ── Capture frame using reliable fswebcam ─────────────────────────────────────
echo "Installing fswebcam if missing..."
apt-get update >/dev/null 2>&1 || true
apt-get install -y fswebcam >/dev/null 2>&1 || true

RAW_IMG="/tmp/camera_test_raw.jpg"
rm -f "$RAW_IMG"

echo "--- V4L2 Diagnostics for /dev/video0 ---"
v4l2-ctl -d /dev/video0 --all || true
echo "----------------------------------------"

echo "Capturing frame using fswebcam..."
# -S 1 skips 1 frame to let auto-exposure settle
fswebcam -d /dev/video0 -p YUYV -r 640x480 --jpeg 95 --no-banner -S 1 "$RAW_IMG" || {
    echo "❌ fswebcam failed on /dev/video0. Trying ffmpeg as fallback..."
    apt-get install -y ffmpeg >/dev/null 2>&1 || true
    ffmpeg -y -f v4l2 -input_format yuyv422 -video_size 640x480 -i /dev/video0 -vframes 1 "$RAW_IMG" || {
        echo "❌ Both fswebcam and ffmpeg failed to capture frames from /dev/video0. The camera is not sending image data!"
        exit 1
    }
}

# ── Write Python test script ──────────────────────────────────────────────────
cat > /tmp/test_camera.py << 'PYEOF'
import cv2
import sys

print("Loading captured frame into OpenCV...")
frame = cv2.imread("/tmp/camera_test_raw.jpg")
if frame is None:
    print("Error: Could not read /tmp/camera_test_raw.jpg")
    sys.exit(1)

print(f"Captured frame: {frame.shape[1]}x{frame.shape[0]}")

print("Loading FaceDetectorYN...")
detector = cv2.FaceDetectorYN_create(
    "/opt/facenox-bench/server/assets/models/detector.onnx",
    "",
    (frame.shape[1], frame.shape[0]),
    0.5,  # score_threshold
    0.3,  # nms_threshold
    50    # top_k
)

faces = detector.detect(frame)
if faces[1] is not None:
    count = len(faces[1])
    print(f"✅ Detected {count} face(s)!")
    for face in faces[1]:
        box = list(map(int, face[:4]))
        color = (0, 255, 0)
        # Draw bounding box
        cv2.rectangle(frame, (box[0], box[1]), (box[0]+box[2], box[1]+box[3]), color, 2)
        # Draw 5 landmarks
        landmarks = face[4:14].reshape((5, 2))
        for pt in landmarks:
            cv2.circle(frame, (int(pt[0]), int(pt[1])), 2, (0, 0, 255), -1)
else:
    print("❌ No faces detected. Make sure you are in front of the camera!")

# Save to disk
out_path = "/tmp/camera_test.jpg"
cv2.imwrite(out_path, frame)
print(f"Saved result to {out_path}")

cap.release()
PYEOF

# ── Run the Python script ─────────────────────────────────────────────────────
${PYTHON_CMD} /tmp/test_camera.py
REMOTE

echo ""
echo "Downloading image to local machine..."
scp "${USER}@${HOST}:/tmp/camera_test.jpg" "camera_test.jpg"

echo ""
echo "✅ Done! A picture has been saved to: camera_test.jpg"
echo "Open it in VS Code or File Explorer to verify the camera and detector!"
