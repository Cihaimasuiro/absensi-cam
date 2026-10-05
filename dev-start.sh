#!/bin/bash
# Dev launcher for Linux & macOS.
# NOTE: On Orange Pi (production), use 'systemctl start smart-absensi' — NOT this script.

echo "╔═══════════════════════════════════════════╗"
echo "║   Smart Absensi — Development Launcher    ║"
echo "╚═══════════════════════════════════════════╝"
echo

# ─── Prerequisite Checks ───────────────────────────────────────────────────

if [ ! -f ".env" ]; then
    echo "[ERROR] File .env tidak ditemukan."
    echo "        Jalankan: cp .env.example .env && php artisan key:generate"
    exit 1
fi

# Detect Python binary (Linux: bin/python, macOS may use bin/python3)
if [ -f "clients/edge-engine/venv/bin/python" ]; then
    PYTHON="clients/edge-engine/venv/bin/python"
elif [ -f "clients/edge-engine/venv/bin/python3" ]; then
    PYTHON="clients/edge-engine/venv/bin/python3"
else
    echo "[ERROR] Python venv Edge Engine belum dibuat."
    echo "        Jalankan: cd clients/edge-engine && python3 -m venv venv && venv/bin/pip install -r requirements.txt"
    exit 1
fi

# ─── Cleanup on Exit ───────────────────────────────────────────────────────

cleanup() {
    echo
    echo "Menghentikan semua layanan..."
    kill $LARAVEL_PID $VITE_PID $ENGINE_PID 2>/dev/null
    exit 0
}
trap cleanup SIGINT SIGTERM

# ─── Start Services ────────────────────────────────────────────────────────

echo "[1/3] Menjalankan Laravel..."
php artisan serve &
LARAVEL_PID=$!

sleep 2

echo "[2/3] Menjalankan Vite..."
npm run dev &
VITE_PID=$!

sleep 2

echo "[3/3] Menjalankan Edge Engine..."
cd clients/edge-engine
../../$PYTHON engine.py &
ENGINE_PID=$!
cd ../..

echo
echo "✓ Semua layanan sudah berjalan."
echo
echo "  Laravel  → http://127.0.0.1:8000"
echo "  Vite HMR → http://127.0.0.1:5173"
echo "  Stream   → http://127.0.0.1:5000"
echo
echo "Tekan Ctrl+C untuk menghentikan semua layanan."
wait
