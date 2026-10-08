#!/bin/bash
set -e

echo "Memulai instalasi ulang Edge Engine di Orange Pi..."

cd /opt/smart-absensi/edge-engine

echo "1. Membersihkan sisa environment lama (jika ada)..."
rm -rf venv miniforge3

echo "2. Membuat Python Virtual Environment baru..."
apt-get update -qq
apt-get install -y python3 python3-venv python3-pip python3-dev libgl1 libglib2.0-0 v4l-utils

python3 -m venv venv
source venv/bin/activate

echo "3. Menginstal library dasar..."
pip install --upgrade pip

echo "4. Menginstal ONNX Runtime khusus arsitektur ARM (AArch64)..."
pip install --extra-index-url https://pkgs.dev.azure.com/onnxruntime/onnxruntime/_packaging/onnxruntime-aarch64-aarch64/pypi/simple/ onnxruntime==1.17.1

echo "5. Menginstal requirements lainnya..."
pip install opencv-python-headless==4.9.0.80 numpy==1.26.4 python-dotenv requests flask pyserial
pip install -r requirements.lock || echo "Beberapa lock gagal, menggunakan versi fallback..."

echo "6. Mengatur ulang hak akses (permissions)..."
chown -R edge:edge /opt/smart-absensi/edge-engine
chmod +x /opt/smart-absensi/edge-engine/install_edge.sh

echo "7. Memperbarui systemd service (mengganti Miniforge dengan VENV)..."
sed -i 's|/opt/smart-absensi/edge-engine/miniforge3/envs/edge_env/bin/python|/opt/smart-absensi/edge-engine/venv/bin/python|g' /etc/systemd/system/smart-absensi.service
systemctl daemon-reload
systemctl restart smart-absensi

echo "Selesai! Silakan cek status dengan: journalctl -u smart-absensi -n 50 -f"
