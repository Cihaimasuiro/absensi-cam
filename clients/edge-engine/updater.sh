#!/usr/bin/env bash
# =============================================================================
# Automated OTA Updater for Absensi Edge Engine
# Triggered remotely to download and install new binaries or models.
# Includes automatic rollback if the service fails to start.
# =============================================================================

set -e

# Configuration
UPDATE_URL="$1"
CHECKSUM="$2"
UPDATE_TYPE="${3:-binary}" # "binary" or "model"
INSTALL_DIR="/opt/absensi-edge/src"
BINARY_NAME="absensi-engine"
SERVICE_NAME="absensi-edge.service"

if [ -z "$UPDATE_URL" ]; then
    echo "[ERROR] No update URL provided."
    exit 1
fi

echo "[OTA] Downloading $UPDATE_TYPE update from $UPDATE_URL ..."
curl -L -o /tmp/update_payload "$UPDATE_URL"

if [ -n "$CHECKSUM" ]; then
    echo "[OTA] Verifying SHA-256 checksum..."
    DOWNLOADED_CHECKSUM=$(sha256sum /tmp/update_payload | awk '{print $1}')
    if [ "$DOWNLOADED_CHECKSUM" != "$CHECKSUM" ]; then
        echo "[ERROR] Checksum mismatch! Expected $CHECKSUM but got $DOWNLOADED_CHECKSUM"
        rm -f /tmp/update_payload
        exit 1
    fi
    echo "[OTA] Checksum verified."
fi

echo "[OTA] Stopping service $SERVICE_NAME..."
systemctl stop $SERVICE_NAME

if [ "$UPDATE_TYPE" == "model" ]; then
    echo "[OTA] Backing up old models..."
    rm -rf "$INSTALL_DIR/models_bak"
    cp -r "$INSTALL_DIR/models" "$INSTALL_DIR/models_bak"

    echo "[OTA] Installing new AI models..."
    tar -xzf /tmp/update_payload -C "$INSTALL_DIR/models/"
else
    echo "[OTA] Backing up old binary..."
    if [ -f "$INSTALL_DIR/$BINARY_NAME" ]; then
        cp "$INSTALL_DIR/$BINARY_NAME" "$INSTALL_DIR/${BINARY_NAME}.bak"
    fi

    echo "[OTA] Installing new binary..."
    if [[ "$UPDATE_URL" == *.tar.gz ]]; then
        tar -xzf /tmp/update_payload -C "$INSTALL_DIR/"
    else
        mv /tmp/update_payload "$INSTALL_DIR/$BINARY_NAME"
        chmod +x "$INSTALL_DIR/$BINARY_NAME"
    fi
fi

echo "[OTA] Restarting service..."
systemctl start $SERVICE_NAME

# Verification & Rollback
sleep 5
if ! systemctl is-active --quiet $SERVICE_NAME; then
    echo "[ERROR] Service crashed after update! Initiating rollback..."
    systemctl stop $SERVICE_NAME
    
    if [ "$UPDATE_TYPE" == "model" ]; then
        rm -rf "$INSTALL_DIR/models"
        mv "$INSTALL_DIR/models_bak" "$INSTALL_DIR/models"
    else
        mv "$INSTALL_DIR/${BINARY_NAME}.bak" "$INSTALL_DIR/$BINARY_NAME"
    fi
    
    systemctl start $SERVICE_NAME
    echo "[OTA] Rollback complete. Update failed."
    exit 1
fi

echo "[OTA] $UPDATE_TYPE Update installed and verified successfully!"
rm -f /tmp/update_payload

