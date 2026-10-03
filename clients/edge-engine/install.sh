#!/usr/bin/env bash
# =============================================================================
# Absensi Cam Edge Engine - Automated Installation Script
# Target: Orange Pi (Ubuntu/Debian AARCH64)
# =============================================================================

set -euo pipefail

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- Paths ---
INSTALL_DIR="/opt/absensi-edge"
SRC_DIR="$INSTALL_DIR/src"
ENV_NAME="edge_env"
PYTHON_VERSION="3.10"

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Please run this script as root (sudo ./install.sh)"
    exit 1
fi

log_info "Starting Absensi Cam Edge Engine Installation (Miniforge Edition)..."

# 1. Install System Dependencies
log_info "Updating apt repositories and installing system dependencies..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
    build-essential pkg-config \
    libgl1 libglib2.0-0 libsm6 libxext6 \
    v4l-utils curl wget rsync \
    libv4l-dev python3-dev

# 2. Setup Working Directory
log_info "Setting up working directory at $INSTALL_DIR..."
mkdir -p "$SRC_DIR"
mkdir -p "$SRC_DIR/models"

# Copy current directory contents to INSTALL_DIR if we are not already there
CURRENT_DIR=$(pwd)
if [ "$CURRENT_DIR" != "$SRC_DIR" ]; then
    log_info "Copying files from $CURRENT_DIR to $SRC_DIR..."
    rsync -a --exclude 'venv' --exclude '__pycache__' --exclude '.git' "$CURRENT_DIR/" "$SRC_DIR/"
fi

cd "$INSTALL_DIR"

# 3. Install Miniforge for Modern Python (3.10+) on AARCH64
if [ ! -d "miniforge3" ]; then
    log_info "Downloading Miniforge3 (AARCH64)..."
    wget -qO Miniforge3.sh "https://github.com/conda-forge/miniforge/releases/latest/download/Miniforge3-Linux-aarch64.sh"
    log_info "Installing Miniforge3..."
    bash Miniforge3.sh -b -p "$INSTALL_DIR/miniforge3"
    rm Miniforge3.sh
else
    log_info "Miniforge3 is already installed, skipping..."
fi

# 4. Create and Setup Conda Environment
# We need to use `source` which is not available in strict sh, but we use bash
source "$INSTALL_DIR/miniforge3/etc/profile.d/conda.sh" || true
source "$INSTALL_DIR/miniforge3/bin/activate" || true

if ! conda env list | grep -q "$ENV_NAME"; then
    log_info "Creating conda environment '$ENV_NAME' with Python $PYTHON_VERSION..."
    conda create -y -n "$ENV_NAME" python="$PYTHON_VERSION"
else
    log_info "Conda environment '$ENV_NAME' already exists, updating..."
fi

conda activate "$ENV_NAME"

log_info "Installing Python dependencies (this might take a few minutes)..."
pip install --upgrade pip --quiet
# Install standard requirements
if [ -f "$SRC_DIR/requirements.txt" ]; then
    pip install -r "$SRC_DIR/requirements.txt" --quiet
else
    pip install --quiet numpy opencv-python-headless flask requests pyserial python-dotenv
fi

# Install ONNX Runtime for AARCH64 (Orange Pi)
log_info "Installing ONNX Runtime for AARCH64..."
pip install --quiet onnxruntime==1.19.2 \
    --extra-index-url https://pkgs.dev.azure.com/onnxruntime/onnxruntime/_packaging/onnxruntime-aarch64-aarch64/pypi/simple/

# 5. Setup Environment variables
cd "$SRC_DIR"
if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    log_info "Setting up default .env file..."
    cp .env.example .env
fi

# 6. Create Systemd Service for Auto-start
log_info "Configuring systemd service..."
cat > /etc/systemd/system/absensi-edge.service <<EOF
[Unit]
Description=Absensi Cam Edge Engine
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$SRC_DIR
Environment="PATH=$INSTALL_DIR/miniforge3/envs/$ENV_NAME/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
ExecStart=$INSTALL_DIR/miniforge3/envs/$ENV_NAME/bin/python engine.py
Restart=always
RestartSec=5
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=absensi-edge

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable absensi-edge.service

log_success "============================================================================"
log_success "✅ Edge Engine Installation Complete!"
log_success "============================================================================"
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Configure your API URL in $SRC_DIR/.env if needed."
echo "2. Run the pairing script to connect to the Laravel backend:"
echo "   cd $SRC_DIR && $INSTALL_DIR/miniforge3/envs/$ENV_NAME/bin/python pairing.py"
echo "3. Copy your AI models (e.g., face detector) to '$SRC_DIR/models'."
echo "4. Start the service: 'systemctl start absensi-edge'"
echo "5. View logs: 'journalctl -fu absensi-edge'"
echo "============================================================================"
