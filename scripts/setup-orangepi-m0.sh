#!/bin/bash
set -e

# Phase 1 - Preflight
echo "=== Phase 1: Preflight ==="
if ! ping -c 1 -W 2 192.168.1.200 &> /dev/null; then
    echo "ERROR: 192.168.1.200 is unreachable."
    exit 1
fi
echo "s,?  Default root/orangepi password detected in this script."
echo "    After M0 benchmark, run: passwd root   (on the board)"
echo "    Or better: disable password auth, use SSH key only."

# Phase 2 - System packages (via SSH)
echo "=== Phase 2: System packages ==="
sshpass -p orangepi ssh root@192.168.1.200 << 'EOF'
apt-get update -qq
apt-get install -y --no-install-recommends \
    python3 python3-pip python3-venv python3-dev \
    libgl1 libglib2.0-0 \
    v4l-utils uvcdynctrl \
    git curl htop
EOF

# Phase 3 - Python venv + pip packages
echo "=== Phase 3: Python venv + pip packages ==="
sshpass -p orangepi ssh root@192.168.1.200 << 'EOF'
python3 -m venv /opt/facenox-bench/venv
source /opt/facenox-bench/venv/bin/activate
pip install --extra-index-url https://pkgs.dev.azure.com/onnxruntime/onnxruntime/_packaging/onnxruntime-aarch64-aarch64/pypi/simple/ \
    onnxruntime==1.24.4
pip install \
    fastapi==0.135.2 \
    "uvicorn[standard]==0.42.0" \
    opencv-python-headless==4.13.0.92 \
    numpy==2.4.3 \
    "sqlalchemy[asyncio]==2.0.48" \
    aiosqlite==0.22.1 \
    alembic==1.18.4 \
    pydantic==2.12.5 \
    python-multipart==0.0.22 \
    cryptography==46.0.5 \
    ulid==1.1
EOF

# Phase 4 - Copy Facenox server source
echo "=== Phase 4: Copy Facenox server source ==="
sshpass -p orangepi ssh root@192.168.1.200 "mkdir -p /opt/facenox-bench/server"
# Since templates/facenox_repo/server/ does not exist in our workspace yet, we will clone it temporarily if needed, 
# or assume the user has it in templates/facenox_repo/server/
# Actually wait, facenox repo doesn't exist locally. Facenox is AGPL-3.0. I should clone it locally first!
if [ ! -d "templates/facenox_repo" ]; then
    mkdir -p templates
    git clone https://github.com/facenox/facenox templates/facenox_repo
fi
sshpass -p orangepi rsync -a --exclude='venv/' --exclude='*.pyc' --exclude='__pycache__/' \
    templates/facenox_repo/server/ \
    root@192.168.1.200:/opt/facenox-bench/server/

# Phase 5 - ONNX model files
echo "=== Phase 5: ONNX model files ==="
sshpass -p orangepi rsync -a templates/facenox_repo/server/assets/models/ \
    root@192.168.1.200:/opt/facenox-bench/server/assets/models/

# Phase 6 - USB camera check
echo "=== Phase 6: USB camera check ==="
sshpass -p orangepi ssh root@192.168.1.200 << 'EOF'
v4l2-ctl --list-devices || echo "No camera devices found!"
ls /dev/video* || echo "No /dev/video devices found!"
EOF

# Phase 7 - Run DB migrations + smoke test
echo "=== Phase 7: Run DB migrations + smoke test ==="
sshpass -p orangepi ssh root@192.168.1.200 << 'EOF'
cd /opt/facenox-bench/server
source ../venv/bin/activate
alembic upgrade head
python run.py --host 0.0.0.0 --port 7400 &
SERVER_PID=$!
sleep 15
curl -s http://localhost:7400/ | python3 -m json.tool
curl -s http://localhost:7400/models | python3 -m json.tool
kill $SERVER_PID
EOF
echo "=== Setup Complete ==="
