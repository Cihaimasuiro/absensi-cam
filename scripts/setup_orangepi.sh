#!/usr/bin/env bash
# setup_orangepi.sh
# Run once on Orange Pi Lite 2 (Debian Buster) to install dependencies
# and deploy Smart Absen display service.
#
# Usage: sudo bash setup_orangepi.sh

set -euo pipefail

INSTALL_DIR="/opt/smart-absen"

echo "=== Smart Absen Display — Setup ==="

# 1. System deps
apt-get update -q
apt-get install -y python3-pip python3-serial

# 2. pyserial (in case python3-serial is too old)
pip3 install --quiet "pyserial>=3.5"

# 3. Add current user (root or pi) to dialout for serial access
# ponytail: skip udev rule; dialout group is simpler and sufficient
usermod -aG dialout "${SUDO_USER:-root}" || true

# 4. Deploy app
mkdir -p "$INSTALL_DIR/scripts"
cp -r . "$INSTALL_DIR/"

# 5. Install systemd service
cp "$INSTALL_DIR/scripts/smart-absen-display.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable smart-absen-display
systemctl start  smart-absen-display

echo ""
echo "Done. Check status:"
echo "  systemctl status smart-absen-display"
echo "  journalctl -fu smart-absen-display"
echo ""
echo "Quick test (Arduino must be connected):"
echo "  python3 $INSTALL_DIR/scripts/display_service.py --demo"
