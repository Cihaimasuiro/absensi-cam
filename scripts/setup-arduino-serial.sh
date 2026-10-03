#!/usr/bin/env bash
# =============================================================================
# scripts/setup-arduino-serial.sh
# Run ONCE from Windows/PC to configure serial access to the Arduino Uno
# on the Orange Pi Lite 2.
#
# Usage (from project root, Git Bash or WSL):
#   bash scripts/setup-arduino-serial.sh
# =============================================================================

set -euo pipefail

HOST="192.168.10.50"
USER="root"

echo "══════════════════════════════════════════════"
echo "  Arduino Serial Bridge Setup — Orange Pi"
echo "══════════════════════════════════════════════"
echo "You may be prompted for the SSH password ('orangepi')"

ssh "${USER}@${HOST}" bash -s << 'REMOTE'
set -euo pipefail

UDEV_RULE="/etc/udev/rules.d/99-smart-arduino.rules"
SYMLINK_NAME="smart-arduino"

# ── 1. Find the Arduino on USB ────────────────────────────────────────────────
echo ""
echo "── Detecting Arduino Uno on USB..."
ARDUINO_PORT=""
for port in /dev/ttyACM* /dev/ttyUSB*; do
    [ -e "$port" ] || continue
    echo "  Found: $port"
    ARDUINO_PORT="$port"
    break
done

if [ -z "$ARDUINO_PORT" ]; then
    echo "  ERROR: No Arduino found on /dev/ttyACM* or /dev/ttyUSB*"
    echo "  Plug the Arduino into the Orange Pi USB port and retry."
    exit 1
fi

# ── 2. Get USB vendor/product IDs for stable udev rule ───────────────────────
echo ""
echo "── Reading USB vendor/product IDs..."
VID=$(udevadm info -q property -n "$ARDUINO_PORT" 2>/dev/null | grep "ID_VENDOR_ID="  | cut -d= -f2 || true)
PID=$(udevadm info -q property -n "$ARDUINO_PORT" 2>/dev/null | grep "ID_MODEL_ID="   | cut -d= -f2 || true)

if [ -z "$VID" ] || [ -z "$PID" ]; then
    VID="2341"; PID="0043"   # Standard Arduino Uno VID:PID
    echo "  Auto-detect failed — using Arduino Uno defaults (2341:0043)"
else
    echo "  VID=$VID  PID=$PID"
fi

# ── 3. Write udev rule ────────────────────────────────────────────────────────
echo ""
echo "── Writing udev rule to $UDEV_RULE..."
cat > "$UDEV_RULE" << EOF
# Smart Absensi — Arduino Uno serial bridge
# Stable symlink /dev/smart-arduino regardless of USB plug order.
SUBSYSTEM=="tty", ATTRS{idVendor}=="${VID}", ATTRS{idProduct}=="${PID}", \\
    SYMLINK+="${SYMLINK_NAME}", MODE="0660", GROUP="dialout"
EOF
echo "  Written."

# ── 4. Reload udev ────────────────────────────────────────────────────────────
echo ""
echo "── Reloading udev rules..."
udevadm control --reload-rules
udevadm trigger
sleep 1

if [ -L "/dev/${SYMLINK_NAME}" ]; then
    echo "  ✅ /dev/${SYMLINK_NAME} -> $(readlink /dev/${SYMLINK_NAME})"
else
    echo "  ⚠️  Symlink not yet visible — will appear after re-plugging the Arduino."
fi

# ── 5. Add current SSH user to dialout ───────────────────────────────────────
CURRENT_USER="${USER:-root}"
echo ""
echo "── Checking dialout group membership for '$CURRENT_USER'..."
if id "$CURRENT_USER" | grep -q "dialout"; then
    echo "  Already in dialout."
else
    usermod -a -G dialout "$CURRENT_USER"
    echo "  ✅ Added to dialout. Re-login to activate (or: newgrp dialout)."
fi

# ── 6. Quick ping test ────────────────────────────────────────────────────────
echo ""
echo "── Testing connection (5s window for Arduino ready event)..."
RAW_PORT="$ARDUINO_PORT"
[ -L "/dev/${SYMLINK_NAME}" ] && RAW_PORT="/dev/${SYMLINK_NAME}"

# Configure baud rate
stty -F "$RAW_PORT" 115200 raw -echo 2>/dev/null || true

# Listen for the ready event (Arduino sends it on boot / port open)
READY=$(timeout 5s cat "$RAW_PORT" 2>/dev/null | head -n 1 || true)

if echo "$READY" | grep -q '"evt":"ready"'; then
    echo "  ✅ Arduino responded: $READY"
else
    # Try sending a ping manually
    echo '{"cmd":"ping","seq":1}' > "$RAW_PORT" 2>/dev/null || true
    PONG=$(timeout 3s cat "$RAW_PORT" 2>/dev/null | head -n 1 || true)
    if echo "$PONG" | grep -q '"evt":"pong"'; then
        echo "  ✅ Ping/pong OK: $PONG"
    else
        echo "  ⚠️  No response. Normal if the Arduino was already powered"
        echo "     (it only sends 'ready' on first port open)."
        echo "     Re-plug the Arduino and run this script again to confirm."
    fi
fi

# ── 7. Summary ────────────────────────────────────────────────────────────────
echo ""
echo "══════════════════════════════════════════════"
echo "  DONE"
echo "══════════════════════════════════════════════"
echo "  Stable device : /dev/${SYMLINK_NAME}"
echo "  Raw port      : $ARDUINO_PORT"
echo "  Baud rate     : 115200  8N1"
echo ""
echo "  ⚠️  Auto-reset: Arduino resets when port is opened (DTR)."
echo "     Your edge service must wait ≥ 2s before first command."
echo "     Arduino sends {\"evt\":\"ready\"} when it is ready."
echo ""
echo "  Quick manual test (two terminals on Orange Pi):"
echo "    t1:  cat /dev/${SYMLINK_NAME}"
echo "    t2:  echo '{\"cmd\":\"ping\",\"seq\":1}' > /dev/${SYMLINK_NAME}"
echo "══════════════════════════════════════════════"
REMOTE

echo ""
echo "Setup complete — Arduino is configured on the Orange Pi."
