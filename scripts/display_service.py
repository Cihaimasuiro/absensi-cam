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
DEFAULT_PORT  = "/dev/ttyACM0"
DEFAULT_BAUD  = 115200
SYS_INTERVAL  = 5   # seconds between SYS updates
RESULT_HOLD   = 4   # seconds to show OK/FAIL before returning to STANDBY

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


def main() -> None:
    global _ser

    ap = argparse.ArgumentParser(description="Smart Absen display service")
    ap.add_argument("--port",  default=DEFAULT_PORT)
    ap.add_argument("--baud",  default=DEFAULT_BAUD, type=int)
    ap.add_argument("--demo",  action="store_true", help="Run screen demo then exit")
    args = ap.parse_args()

    # Open serial — Arduino resets on connect; wait for it to boot
    print(f"Connecting to Arduino on {args.port} @ {args.baud} baud ...")
    _ser = serial.Serial(args.port, args.baud, timeout=1)
    time.sleep(2)   # wait for Arduino bootloader reset
    print("Connected.")

    send("BOOT")

    # Start background SYS thread
    t = threading.Thread(target=sys_loop, daemon=True)
    t.start()

    if args.demo:
        _demo()
        return

    # Normal operation: show STANDBY and keep service alive.
    # Your face-recognition pipeline imports and calls:
    #   from scripts.display_service import notify_recognized, notify_unknown, notify_processing
    notify_standby()
    print("Display service running. Import this module to send events.")
    try:
        while True:
            time.sleep(60)
    except KeyboardInterrupt:
        print("Stopped.")


if __name__ == "__main__":
    main()
