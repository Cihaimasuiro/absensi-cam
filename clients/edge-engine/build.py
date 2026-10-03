#!/usr/bin/env python3
"""
build.py — Compiler for Absensi Edge Engine
Compiles the Python source code into a single proprietary binary using PyInstaller.
"""

import os
import shutil
import subprocess
import sys


def main():
    print("========================================")
    print(" Building Proprietary Edge Engine")
    print("========================================")

    # Ensure PyInstaller is installed
    try:
        import PyInstaller
    except ImportError:
        print("[!] PyInstaller not found. Installing...")
        subprocess.check_call([sys.executable, "-m", "pip", "install", "pyinstaller"])

    # Clean old builds
    for d in ["build", "dist"]:
        if os.path.exists(d):
            shutil.rmtree(d)

    # Build command
    cmd = [sys.executable, "-m", "PyInstaller", "--noconfirm", "--clean", "engine.spec"]

    print("[*] Running PyInstaller...")
    result = subprocess.run(cmd)

    if result.returncode == 0:
        print("\n[SUCCESS] Compilation complete!")
        print("[*] The compiled binary is located at: dist/absensi-engine")
        print("[*] Deploy ONLY this binary and the 'models/' folder to production.")
    else:
        print("\n[ERROR] Compilation failed.")
        sys.exit(1)


if __name__ == "__main__":
    main()
