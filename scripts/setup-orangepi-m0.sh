#!/usr/bin/env bash
# =============================================================================
# setup-orangepi-m0.sh — Smart Absensi M0 Benchmark Environment
# =============================================================================
# Installs the Facenox Python pipeline on Orange Pi Lite 2 for benchmarking.
# Runs entirely from your Windows PC over SSH (no manual steps on the board).
#
# Usage (from project root, Git Bash or WSL):
#   bash scripts/setup-orangepi-m0.sh
#
# Target:  Orange Pi Lite 2 · Allwinner H6 · aarch64 · Debian 12
# Facenox: AGPL-3.0 (templates/facenox_repo/) — benchmark use only.
#          Do NOT ship any Facenox-derived code without complying with AGPL.
#
# PRD reference:
#   §4.1 hardware table — Orange Pi Lite 2, 1 GB LPDDR3
#   §14  M0 gate — latency, RSS, temperature
#   §12.4 AGPL licence obligation
# =============================================================================

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
HOST="192.168.10.50"
#HOST="192.168.1.200"
USER="root"
PASS="orangepi"            # ⚠ Change on the board after M0 is done: passwd root
REMOTE_BASE="/opt/facenox-bench"
REMOTE_SERVER="${REMOTE_BASE}/server"
REMOTE_VENV="${REMOTE_BASE}/venv"
LOCAL_SERVER="templates/facenox_repo/server"

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYN='\033[0;36m'; RST='\033[0m'
info()  { echo -e "${CYN}[INFO]${RST}  $*"; }
ok()    { echo -e "${GRN}[ OK ]${RST}  $*"; }
warn()  { echo -e "${YLW}[WARN]${RST}  $*"; }
die()   { echo -e "${RED}[FAIL]${RST}  $*"; exit 1; }

# ── Helper: run a command on the board via SSH ─────────────────────────────────
if command -v sshpass &>/dev/null; then
    ssh_run() {
        sshpass -p "${PASS}" ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${USER}@${HOST}" "$@"
    }
else
    ssh_run() {
        ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${USER}@${HOST}" "$@"
    }
fi

# =============================================================================
# PHASE 0 — Preflight
# =============================================================================
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   Smart Absensi — M0 Benchmark Environment Setup            ║"
echo "║   Target: ${HOST} (Orange Pi Lite 2)                  ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

warn "⚠  Security: root/orangepi is the default password."
warn "   After M0 benchmark: run 'passwd root' on the board."
warn "   Better: add SSH key auth and disable password login."
echo ""

if ! command -v sshpass &>/dev/null; then
    warn "sshpass is not installed. You will be prompted to type the password ('${PASS}') several times."
fi

# Check scp
if ! command -v scp &>/dev/null; then
    die "scp not found. It is required to transfer files."
fi

# Check reachability (ping skipped to avoid Windows/Linux flag conflicts)
info "Target board: ${HOST}"

# Check local Facenox server source exists
if [ ! -f "${LOCAL_SERVER}/run.py" ]; then
    die "Facenox server source not found at ${LOCAL_SERVER}/run.py\nRun from project root: bash scripts/setup-orangepi-m0.sh"
fi

# Check ONNX models
for model in detector liveness recognizer; do
    if [ ! -f "${LOCAL_SERVER}/assets/models/${model}.onnx" ]; then
        die "Missing ONNX model: ${LOCAL_SERVER}/assets/models/${model}.onnx"
    fi
done
ok "All 3 ONNX models found locally."

# =============================================================================
# PHASE 1 — System packages
# =============================================================================
info "[1/7] Installing system packages..."
ssh_run bash -s <<'REMOTE'
set -e
export DEBIAN_FRONTEND=noninteractive

apt-get update -qq

# Python + build deps
apt-get install -y --no-install-recommends \
    python3 python3-pip python3-venv python3-dev \
    build-essential pkg-config \
    libgl1 libglib2.0-0 libsm6 libxext6 \
    v4l-utils \
    git curl htop sshpass

python3 --version
echo "System packages installed."
REMOTE
ok "System packages ready."

# =============================================================================
# PHASE 2 — Create remote directory structure
# =============================================================================
info "[2/7] Creating remote directories..."
ssh_run bash -s <<REMOTE
set -e
mkdir -p ${REMOTE_SERVER}/assets/models
mkdir -p ${REMOTE_BASE}/data
echo "Directories created."
REMOTE
ok "Remote directories ready."

# =============================================================================
# PHASE 3 — Python 3.10+ (via Miniforge) + pip packages
# =============================================================================
info "[3/7] Setting up Python 3.10+ and installing pip packages..."
info "      (Using Miniforge because OrangePi Buster has Python 3.7 which is too old)"

ssh_run bash -s <<REMOTE
set -e

CONDA_DIR="${REMOTE_BASE}/conda"

# Install Miniforge if missing
if [ ! -d "\${CONDA_DIR}" ]; then
    echo "Downloading Miniforge3 (aarch64)..."
    curl -L -O "https://github.com/conda-forge/miniforge/releases/latest/download/Miniforge3-Linux-aarch64.sh"
    bash Miniforge3-Linux-aarch64.sh -b -p "\${CONDA_DIR}"
    rm Miniforge3-Linux-aarch64.sh
fi

# Activate Conda environment is flaky in non-interactive SSH, so we use absolute paths
PIP_CMD="\${CONDA_DIR}/bin/pip"
PYTHON_CMD="\${CONDA_DIR}/bin/python"

# We will use the base environment for the benchmark to keep it simple.
\${PIP_CMD} install --upgrade pip --quiet

# ── onnxruntime for aarch64 ──────────────────────────────────────────────────
ARCH=\$(uname -m)
echo "Detected arch: \${ARCH}"

if [ "\${ARCH}" = "aarch64" ]; then
    echo "Installing onnxruntime for aarch64..."
    \${PIP_CMD} install --quiet \
        onnxruntime==1.24.4 \
        --extra-index-url https://pkgs.dev.azure.com/onnxruntime/onnxruntime/_packaging/onnxruntime-aarch64-aarch64/pypi/simple/ \
        || \${PIP_CMD} install --quiet onnxruntime
else
    \${PIP_CMD} install --quiet onnxruntime==1.24.4
fi

# ── Core packages ────────────────────────────────────────────────────────────
\${PIP_CMD} install --quiet \
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

echo "All pip packages installed."
\${PYTHON_CMD} -c "import cv2, numpy, onnxruntime, fastapi; print('Import smoke-test: OK')"
REMOTE
ok "Python environment ready."

# =============================================================================
# PHASE 4 — Copy Facenox server source (rsync, exclude venv/cache)
# =============================================================================
info "[4/7] Packaging Facenox server source..."
tar -czf facenox-server.tar.gz \
    --exclude="venv" \
    --exclude="*.pyc" \
    --exclude="__pycache__" \
    --exclude="*.egg-info" \
    --exclude="dist" \
    --exclude=".git" \
    --exclude="tests" \
    -C "${LOCAL_SERVER}" .

info "      Uploading to board (using scp)..."
if command -v sshpass &>/dev/null; then
    sshpass -p "${PASS}" scp -o StrictHostKeyChecking=no facenox-server.tar.gz "${USER}@${HOST}:/tmp/"
else
    scp -o StrictHostKeyChecking=no facenox-server.tar.gz "${USER}@${HOST}:/tmp/"
fi

info "      Extracting on board..."
ssh_run "tar -xzf /tmp/facenox-server.tar.gz -C ${REMOTE_SERVER}/ && rm /tmp/facenox-server.tar.gz"
rm facenox-server.tar.gz
ok "Server source synced."

# =============================================================================
# PHASE 5 — ONNX model files
# =============================================================================
info "[5/7] Uploading ONNX model files (~12 MB)..."
if command -v sshpass &>/dev/null; then
    sshpass -p "${PASS}" scp -o StrictHostKeyChecking=no "${LOCAL_SERVER}/assets/models/"*.onnx "${USER}@${HOST}:${REMOTE_SERVER}/assets/models/"
else
    scp -o StrictHostKeyChecking=no "${LOCAL_SERVER}/assets/models/"*.onnx "${USER}@${HOST}:${REMOTE_SERVER}/assets/models/"
fi
ok "Model files transferred: detector.onnx, liveness.onnx, recognizer.onnx"

# =============================================================================
# PHASE 6 — USB camera check
# =============================================================================
info "[6/7] Checking USB camera devices..."
ssh_run bash -s <<'REMOTE'
echo "── v4l2 device list ──────────────────────────"
v4l2-ctl --list-devices 2>/dev/null || echo "(v4l2-ctl: no devices found yet — plug camera before benchmark)"
echo ""
echo "── /dev/video* ───────────────────────────────"
ls -la /dev/video* 2>/dev/null || echo "No /dev/video* found. Plug in USB camera and re-run benchmark."
REMOTE
# Not a fatal error — camera might not be plugged in yet

# =============================================================================
# PHASE 7 — Run migrations + API smoke test
# =============================================================================
info "[7/7] Running DB migrations and API smoke test..."
ssh_run bash -s <<REMOTE
set -e
PYTHON_CMD="${REMOTE_BASE}/conda/bin/python"
cd "${REMOTE_SERVER}"

# Set data dir so alembic writes to the right place
export FACENOX_DATA_DIR="${REMOTE_BASE}/data"
export ENVIRONMENT=development
export LD_LIBRARY_PATH="${REMOTE_BASE}/conda/lib:\${LD_LIBRARY_PATH:-}"

# Run migrations
\${PYTHON_CMD} -c "from database.migrate import run_migrations; run_migrations()" && echo "Migrations OK."

# Start server in background, wait, smoke-test, stop
\${PYTHON_CMD} run.py --host 127.0.0.1 --port 7400 &
SERVER_PID=\$!
sleep 6

echo "── Health check ──────────────────────────────"
curl -sf http://127.0.0.1:7400/ | python3 -m json.tool || echo "Health check failed — check server logs"

echo "── Model status ──────────────────────────────"
curl -sf http://127.0.0.1:7400/models | python3 -m json.tool || echo "Models endpoint failed"

kill \$SERVER_PID 2>/dev/null || true
wait \$SERVER_PID 2>/dev/null || true
echo "Server stopped."
REMOTE
ok "Smoke test complete."

# =============================================================================
# Done
# =============================================================================
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   M0 Environment Setup Complete!                            ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║   Run benchmark:                                            ║"
echo "║     bash scripts/benchmark-orangepi.sh                     ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
warn "Remember: change the root password on the board before production."
