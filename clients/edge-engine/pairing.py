#!/usr/bin/env python3
"""
pairing.py — Skrip interaktif untuk menghubungkan Orange Pi ke server Smart Absensi.

Jalankan SATU KALI setelah install:
    python pairing.py

Skrip akan menyimpan konfigurasi ke file .env di direktori yang sama.
"""

import os
import platform
import subprocess
import sys

try:
    import requests
except ImportError:
    subprocess.check_call([sys.executable, "-m", "pip", "install", "requests", "-q"])
    import requests

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
ENV_PATH = os.path.join(BASE_DIR, ".env")
from config.settings import DEFAULT_MODEL_VERSION

FW_VERSION = "1.0.0"


def get_device_name() -> str:
    """Generate nama perangkat default secara dinamis (mendukung OrangePi, RaspberryPi, Ubuntu, Windows, dll)."""
    hostname = platform.node()
    system = platform.system()
    
    if system == "Linux":
        # Coba deteksi nama hardware (SBC) dari device tree Linux
        try:
            with open("/sys/firmware/devicetree/base/model", "r") as f:
                model = f.read().replace("\x00", "").strip()
                # Hapus spasi agar rapi, contoh "Orange Pi Lite2" -> "OrangePiLite2"
                clean_model = model.replace(" ", "")
                return f"{clean_model}-{hostname}"
        except Exception:
            pass
    
    # Fallback untuk OS lain (contoh: Windows-DESKTOP123, Linux-ubuntu)
    return f"{system}-{hostname}"


def do_pair(server_url: str, code: str, device_name: str) -> dict:
    """POST /api/v1/devices/pair dan kembalikan JSON response."""
    url = f"{server_url.rstrip('/')}/api/v1/devices/pair"
    payload = {
        "code": code.strip().upper(),
        "device_name": device_name,
        "fw_version": FW_VERSION,
    }
    resp = requests.post(url, json=payload, timeout=10)

    if resp.status_code == 422:
        errors = resp.json().get("errors", {})
        msg = next(iter(errors.values()), ["Kode pairing tidak valid."])[0]
        raise ValueError(msg)

    resp.raise_for_status()
    return resp.json()


def save_env(server_url: str, token: str, device_id: str, embed_key: str, model_version: str) -> None:
    """Tulis konfigurasi ke file .env."""
    content = (
        f"SMART_ABSENSI_URL={server_url}\n"
        f"SMART_ABSENSI_TOKEN={token}\n"
        f"SMART_ABSENSI_DEVICE_ID={device_id}\n"
        f"ENROLLMENT_EMBED_KEY={embed_key}\n"
        f"MODEL_VERSION={model_version}\n"
    )
    with open(ENV_PATH, "w") as f:
        f.write(content)
    os.chmod(ENV_PATH, 0o600)  # read-only by owner


def check_already_paired() -> bool:
    """Cek apakah .env sudah ada dan berisi token."""
    if not os.path.exists(ENV_PATH):
        return False
    with open(ENV_PATH) as f:
        contents = f.read()
    return "SMART_ABSENSI_TOKEN" in contents and "SMART_ABSENSI_URL" in contents


def main():
    print("\n╔══════════════════════════════════════╗")
    print("║   Smart Absensi — Device Pairing     ║")
    print("╚══════════════════════════════════════╝\n")

    if check_already_paired():
        print("[!] Perangkat ini sudah terdaftar (.env ditemukan).")
        ulang = input("    Lakukan pairing ulang? (y/N): ").strip().lower()
        if ulang != "y":
            print("[✓] Pairing dibatalkan. Jalankan 'python engine.py' untuk memulai.")
            sys.exit(0)

    print("Masukkan informasi server Smart Absensi Anda.\n")

    server_url = input("  URL Server (contoh: http://192.168.10.1:8000): ").strip()
    if not server_url.startswith("http"):
        print("[!] URL tidak valid. Harus dimulai dengan http:// atau https://")
        sys.exit(1)

    code = input(
        "  Kode Pairing (dari Dashboard > Perangkat, contoh: A3B9-X8YZ): "
    ).strip()
    if len(code) != 9 or code[4] != "-":
        print("[!] Format kode salah. Harus 9 karakter dengan format: XXXX-XXXX")
        sys.exit(1)

    default_name = get_device_name()
    name_input = input(f"  Nama Perangkat [{default_name}]: ").strip()
    device_name = name_input if name_input else default_name

    print(f"\n[→] Menghubungi {server_url} ...")

    try:
        result = do_pair(server_url, code, device_name)
    except ValueError as e:
        print(f"[✗] Pairing gagal: {e}")
        sys.exit(1)
    except requests.exceptions.ConnectionError:
        print(
            f"[✗] Tidak dapat terhubung ke {server_url}. Periksa URL dan koneksi jaringan."
        )
        sys.exit(1)
    except requests.exceptions.HTTPError as e:
        print(f"[✗] Server error: {e}")
        sys.exit(1)

    token = result["token"]
    device_id = result["device_id"]
    embed_key = result.get("embed_key", "")
    model_version = result.get("model_version", DEFAULT_MODEL_VERSION)

    save_env(server_url, token, device_id, embed_key, model_version)

    print("\n╔══════════════════════════════════════╗")
    print("║           Pairing Berhasil!          ║")
    print("╚══════════════════════════════════════╝")
    print(f"  Nama       : {device_name}")
    print(f"  Device ID  : {device_id}")
    print(f"  Server     : {server_url}")
    print(f"  Config     : {ENV_PATH}")
    print(
        "\n[✓] Jalankan 'python engine.py' atau 'systemctl start smart-absensi' untuk mulai.\n"
    )


if __name__ == "__main__":
    main()
