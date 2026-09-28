#!/usr/bin/env python3
"""
Smart Absen — Display Service
Runs on Orange Pi Lite 2 (Debian Buster, kernel 5.4.65).
Sends system status and absensi events to Arduino via USB-Serial.

Usage:
    python3 display_service.py [--port /dev/ttyACM0] [--baud 115200]

Protocol (newline-terminated, sent to Arduino):
    BOOT                               — sent once at startup
    SYS:<cpu%>:<mem%>:<temp>:<ip>      — every 5 s (background thread)
    STANDBY                            — waiting for face
    PROC                               — face detected, processing
    OK:<name>:<dept>:<HH:MM:SS>        — recognized
    FAIL                               — unknown face
"""

import argparse
import os
import re
import socket
import threading
import time

import serial  # pip3 install pyserial

# ── Config ────────────────────────────────────────────────────────────────────
DEFAULT_PORT   = "auto"      # "auto" = scan ttyACM*/ttyUSB* automatically
DEFAULT_BAUD   = 115200
SYS_INTERVAL   = 5          # seconds between SYS updates
RESULT_HOLD    = 4          # seconds to show OK/FAIL before returning to STANDBY
CONNECT_RETRY  = 10         # seconds between port-open retries

# Candidate ports tried in order when DEFAULT_PORT == "auto"
AUTO_PORTS = [
    "/dev/ttyACM0", "/dev/ttyACM1",
    "/dev/ttyUSB0", "/dev/ttyUSB1",
]

# ── Serial helpers ────────────────────────────────────────────────────────────
_ser: serial.Serial = None
_lock = threading.Lock()


def send(line: str) -> None:
    """Thread-safe send of one command line to Arduino."""
    with _lock:
        try:
            _ser.write((line.strip() + "\n").encode())
        except serial.SerialException as e:
            print(f"[WARN] serial send failed: {e}")


# ── System info ───────────────────────────────────────────────────────────────
def _cpu_percent() -> str:
    """Read /proc/stat delta — no psutil dependency."""
    def read_stat():
        with open("/proc/stat") as f:
            parts = f.readline().split()
        idle, total = int(parts[4]), sum(int(x) for x in parts[1:])
        return idle, total

    idle1, total1 = read_stat()
    time.sleep(0.2)
    idle2, total2 = read_stat()
    diff_idle  = idle2  - idle1
    diff_total = total2 - total1
    if diff_total == 0:
        return "0"
    return str(round((1 - diff_idle / diff_total) * 100))


def _mem_percent() -> str:
    info = {}
    with open("/proc/meminfo") as f:
        for line in f:
            k, v = line.split(":", 1)
            info[k.strip()] = int(v.split()[0])
    used = info["MemTotal"] - info.get("MemAvailable", info.get("MemFree", 0))
    return str(round(used / info["MemTotal"] * 100))


def _cpu_temp() -> str:
    paths = [
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/devices/virtual/thermal/thermal_zone0/temp",
    ]
    for p in paths:
        if os.path.exists(p):
            with open(p) as f:
                raw = int(f.read().strip())
            # Allwinner H6 reports millidegrees
            return str(raw // 1000 if raw > 1000 else raw)
    return "--"


def _local_ip() -> str:
    """Best-effort local IP without external network call."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return "0.0.0.0"


def sys_loop() -> None:
    """Background thread: send SYS update every SYS_INTERVAL seconds."""
    while True:
        cpu  = _cpu_percent()
        mem  = _mem_percent()
        temp = _cpu_temp()
        ip   = _local_ip()
        send(f"SYS:{cpu}:{mem}:{temp}:{ip}")
        time.sleep(SYS_INTERVAL)


# ── Public API (called by your face-recognition pipeline) ─────────────────────
def notify_standby() -> None:
    send("STANDBY")


def notify_processing() -> None:
    send("PROC")


def notify_recognized(name: str, dept: str = "") -> None:
    t = time.strftime("%H:%M:%S")
    # replace colons in name/dept to avoid breaking protocol
    name = name.replace(":", "-")
    dept = dept.replace(":", "-")
    send(f"OK:{name}:{dept}:{t}")
    # auto-return to standby after hold
    threading.Timer(RESULT_HOLD, notify_standby).start()


def notify_unknown() -> None:
    send("FAIL")
    threading.Timer(RESULT_HOLD, notify_standby).start()


# ── Standalone demo / self-test ───────────────────────────────────────────────
def _demo() -> None:
    """Quick smoke-test: cycles through all screens."""
    print("Demo mode — cycling screens every 3s. Ctrl-C to stop.")
    send("BOOT")
    time.sleep(3)
    notify_standby()
    time.sleep(3)
    notify_processing()
    time.sleep(2)
    notify_recognized("Budi Santoso", "Engineering")
    time.sleep(5)
    notify_unknown()
    time.sleep(5)
    notify_standby()
    print("Demo done.")


def _detect_port() -> str:
    """Return first existing port from AUTO_PORTS, or '' if none found."""
    import glob
    # Expand globs so hotplug names (ttyACM1, ttyUSB1 …) are also caught
    for candidate in AUTO_PORTS:
        matches = glob.glob(candidate)
        if matches:
            return matches[0]
    return ""


def _open_serial(port: str, baud: int) -> serial.Serial:
    """
    Try to open serial port; if 'auto', scan candidates.
    Retries every CONNECT_RETRY seconds until success.
    Prints actionable hint on first failure.
    """
    first_try = True
    while True:
        target = port if port != "auto" else _detect_port()
        if target:
            try:
                ser = serial.Serial(target, baud, timeout=1)
                print(f"Connected to {target}")
                return ser
            except serial.SerialException as e:
                if first_try:
                    print(f"[ERROR] Cannot open {target}: {e}")
                    _print_hint(target)
                    first_try = False
        else:
            if first_try:
                print("[ERROR] Arduino not found. Tried:", ", ".join(AUTO_PORTS))
                _print_hint("")
                first_try = False
        print(f"  Retrying in {CONNECT_RETRY}s ... (Ctrl-C to abort)")
        time.sleep(CONNECT_RETRY)


def _print_hint(port: str) -> None:
    print("\nDiagnosis:")
    print("  1. Pastikan kabel USB Arduino sudah terhubung ke Orange Pi.")
    print("  2. Jalankan: ls /dev/tty{ACM,USB}*")
    print("  3. Jalankan: dmesg | tail -20 | grep -E 'tty|usb|ACM'")
    print("  4. Jika port berbeda, jalankan dengan: --port /dev/ttyUSB0")
    if port:
        print(f"  5. Cek izin: ls -l {port}  (harus masuk group dialout)")
        print(f"     Fix    : sudo usermod -aG dialout $USER && newgrp dialout")
    print()


def main() -> None:
    global _ser

    ap = argparse.ArgumentParser(description="Smart Absen display service")
    ap.add_argument("--port", default=DEFAULT_PORT,
                    help="Serial port atau 'auto' untuk deteksi otomatis")
    ap.add_argument("--baud", default=DEFAULT_BAUD, type=int)
    ap.add_argument("--demo", action="store_true", help="Jalankan demo layar lalu keluar")
    ap.add_argument("--list", action="store_true", help="Tampilkan port serial yang tersedia")
    args = ap.parse_args()

    if args.list:
        import glob
        found = [p for c in AUTO_PORTS for p in glob.glob(c)]
        print("Port serial tersedia:", found if found else "(tidak ada)")
        return

    print(f"Mencari Arduino (port={args.port}, baud={args.baud}) ...")
    _ser = _open_serial(args.port, args.baud)
    time.sleep(2)   # tunggu bootloader Arduino reset setelah koneksi

    send("BOOT")

    # Background SYS info thread
    threading.Thread(target=sys_loop, daemon=True).start()

    if args.demo:
        _demo()
        return

    notify_standby()
    print("Display service berjalan. Ctrl-C untuk berhenti.")
    try:
        while True:
            time.sleep(60)
    except KeyboardInterrupt:
        print("Stopped.")


if __name__ == "__main__":
    main()
