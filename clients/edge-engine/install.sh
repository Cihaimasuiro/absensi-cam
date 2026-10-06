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
INSTALL_DIR="/opt/smart-absensi/edge-engine"
SERVICE_NAME="smart-absensi.service"
ENV_NAME="edge_env"
PYTHON_VERSION="3.11"
USER_NAME="edge"

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
mkdir -p "$INSTALL_DIR"
mkdir -p "$INSTALL_DIR/models"

# Copy current directory contents to INSTALL_DIR if we are not already there
CURRENT_DIR=$(pwd)
MODELS_SRC=${MODELS_SRC:-"$CURRENT_DIR/../../packages/models"}
if [ "$CURRENT_DIR" != "$INSTALL_DIR" ]; then
    log_info "Copying files from $CURRENT_DIR to $INSTALL_DIR..."
    rsync -a --exclude 'venv' --exclude '__pycache__' --exclude '.git' "$CURRENT_DIR/" "$INSTALL_DIR/"
    if [ -d "$MODELS_SRC" ]; then
        log_info "Copying models from $MODELS_SRC..."
        rsync -L -a "$MODELS_SRC/" "$INSTALL_DIR/models/"
    else
        log_warn "Models directory not found at $MODELS_SRC. Set MODELS_SRC env var if it's elsewhere."
    fi
fi

if ! ls "$INSTALL_DIR/models"/*.onnx 1> /dev/null 2>&1; then
    log_error "No .onnx files found in '$INSTALL_DIR/models'! Please copy your AI models to $MODELS_SRC or set MODELS_SRC."
    exit 1
fi

cd "$INSTALL_DIR"

# 3. Install Miniforge for Modern Python (3.11) on AARCH64
if [ ! -d "miniforge3" ]; then
    log_info "Downloading Miniforge3 (AARCH64)..."
    wget -qO Miniforge3.sh "https://github.com/conda-forge/miniforge/releases/latest/download/Miniforge3-Linux-aarch64.sh"
    log_info "Installing Miniforge3..."
    bash Miniforge3.sh -b -p "$INSTALL_DIR/miniforge3"
    rm Miniforge3.sh
else
    log_info "Miniforge3 is already installed, skipping..."
fi

# Set ownership early so conda env works correctly for the edge user
log_info "Setting ownership of $INSTALL_DIR to $USER_NAME..."
chown -R $USER_NAME:$USER_NAME "$INSTALL_DIR" || log_warn "Could not set ownership to $USER_NAME."

# 4. Create and Setup Conda Environment as user
sudo -u $USER_NAME bash <<EOF
source "$INSTALL_DIR/miniforge3/etc/profile.d/conda.sh" || true
source "$INSTALL_DIR/miniforge3/bin/activate" || true

if ! conda env list | grep -q "$ENV_NAME"; then
    echo "[INFO] Creating conda environment '$ENV_NAME' with Python $PYTHON_VERSION..."
    conda create -y -n "$ENV_NAME" python="$PYTHON_VERSION"
else
    echo "[INFO] Conda environment '$ENV_NAME' already exists, updating..."
fi

conda activate "$ENV_NAME"

echo "[INFO] Installing Python dependencies..."
pip install --upgrade pip --quiet

if [ -f "$INSTALL_DIR/requirements.txt" ]; then
    pip install -r "$INSTALL_DIR/requirements.txt" --quiet
else
    pip install --quiet numpy opencv-python-headless flask requests pyserial python-dotenv
fi
EOF

# 5. Setup Environment variables
cd "$INSTALL_DIR"
if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    log_info "Setting up default .env file..."
    cp .env.example .env
    chown $USER_NAME:$USER_NAME .env || true
fi

# 6. Create Systemd Service for Auto-start
log_info "Configuring systemd service..."
if [ -f "$SERVICE_NAME" ]; then
    cp "$SERVICE_NAME" "/etc/systemd/system/$SERVICE_NAME"
    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME"
    log_info "Systemd service installed and enabled."
else
    log_warn "$SERVICE_NAME not found, skipping service installation."
fi

log_success "============================================================================"
log_success "✅ Edge Engine Installation Complete!"
log_success "============================================================================"
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Configure your API URL in $INSTALL_DIR/.env if needed."
echo "2. Run the pairing script to connect to the Laravel backend:"
echo "   cd $INSTALL_DIR && sudo -u $USER_NAME $INSTALL_DIR/miniforge3/envs/$ENV_NAME/bin/python pairing.py"
echo "3. Models are placed in '$INSTALL_DIR/models'."
echo "4. Start the service: 'systemctl start $SERVICE_NAME'"
echo "5. View logs: 'journalctl -fu $SERVICE_NAME'"
echo "============================================================================"
