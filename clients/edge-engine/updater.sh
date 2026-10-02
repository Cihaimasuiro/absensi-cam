#!/usr/bin/env bash
# =============================================================================
# Automated OTA Updater for Absensi Edge Engine
# Triggered remotely or periodically to download and install new binaries.
# =============================================================================

set -e

# Configuration
UPDATE_URL="$1"    # Passed by the caller (e.g. Laravel)
CHECKSUM="$2"      # Expected MD5 checksum
INSTALL_DIR="/opt/absensi-edge/src"
BINARY_NAME="absensi-engine"
SERVICE_NAME="absensi-edge.service"

if [ -z "$UPDATE_URL" ]; then
    echo "[ERROR] No update URL provided."
    exit 1
fi

echo "[OTA] Downloading update from $UPDATE_URL ..."
curl -L -o /tmp/absensi-engine.new "$UPDATE_URL"

if [ -n "$CHECKSUM" ]; then
    echo "[OTA] Verifying checksum..."
    DOWNLOADED_CHECKSUM=$(md5sum /tmp/absensi-engine.new | awk '{print $1}')
    if [ "$DOWNLOADED_CHECKSUM" != "$CHECKSUM" ]; then
        echo "[ERROR] Checksum mismatch! Expected $CHECKSUM but got $DOWNLOADED_CHECKSUM"
        rm /tmp/absensi-engine.new
        exit 1
    fi
    echo "[OTA] Checksum verified."
fi

echo "[OTA] Stopping service $SERVICE_NAME..."
systemctl stop $SERVICE_NAME

echo "[OTA] Backing up old binary..."
if [ -f "$INSTALL_DIR/$BINARY_NAME" ]; then
    mv "$INSTALL_DIR/$BINARY_NAME" "$INSTALL_DIR/${BINARY_NAME}.bak"
fi

echo "[OTA] Installing new binary..."
# Extract if it's a tar.gz (since PyInstaller COLLECT makes a folder)
# Wait, for OTA we should assume they tar.gz the dist/absensi-engine folder.
# Let's assume the download is a .tar.gz of the compiled folder.
if [[ "$UPDATE_URL" == *.tar.gz ]]; then
    tar -xzf /tmp/absensi-engine.new -C $INSTALL_DIR/
else
    # Single file fallback
    mv /tmp/absensi-engine.new "$INSTALL_DIR/$BINARY_NAME"
    chmod +x "$INSTALL_DIR/$BINARY_NAME"
fi

echo "[OTA] Restarting service..."
systemctl start $SERVICE_NAME

echo "[OTA] Update complete!"
