@echo off
setlocal enabledelayedexpansion

set SCRIPT_DIR=%~dp0
set ROOT_DIR=%SCRIPT_DIR%..

echo === Building Smart Absensi Sync Daemon for aarch64 (glibc 2.28 / Buster) ===

cd /d "%ROOT_DIR%"

where zig >nul 2>nul
if %errorlevel% neq 0 (
    for /f "delims=" %%i in ('python -c "import ziglang, os; print(os.path.dirname(ziglang.__file__))" 2^>nul') do (
        set "PATH=%%i;!PATH!"
    )
)

where cargo-zigbuild >nul 2>nul
if %errorlevel% equ 0 (
    echo Using cargo-zigbuild with target aarch64-unknown-linux-gnu.2.28...
    rustup target add aarch64-unknown-linux-gnu 2>nul
    cargo zigbuild --target aarch64-unknown-linux-gnu.2.28 --release --bin engine-cli --bin serial_bench
    if !errorlevel! neq 0 (
        echo ERROR: cargo-zigbuild failed.
        exit /b 1
    )
    echo.
    echo === Generating SHA256SUMS ===
    powershell -NoProfile -Command "(Get-FileHash target\aarch64-unknown-linux-gnu\release\engine-cli -Algorithm SHA256).Hash.ToLower() + '  smart-absensi-sync' | Out-File -Encoding ascii target\aarch64-unknown-linux-gnu\release\SHA256SUMS"
    type target\aarch64-unknown-linux-gnu\release\SHA256SUMS
    echo.
    echo === Build Success! ===
    echo Binary: target\aarch64-unknown-linux-gnu\release\engine-cli
    goto :eof
)

where docker >nul 2>nul
if %errorlevel% equ 0 (
    echo cargo-zigbuild not found. Using Docker debian:buster container...
    docker run --rm -v "%ROOT_DIR%:/workspace" -w /workspace debian:buster bash -c "apt-get update -qq && apt-get install -y -qq curl build-essential gcc-aarch64-linux-gnu && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable && source $HOME/.cargo/env && rustup target add aarch64-unknown-linux-gnu && CARGO_TARGET_AARCH64_UNKNOWN_LINUX_GNU_LINKER=aarch64-linux-gnu-gcc cargo build --target aarch64-unknown-linux-gnu --release --bin engine-cli"
    if %errorlevel% neq 0 (
        echo ERROR: Docker build failed.
        exit /b 1
    )
    echo.
    echo === Build Success! ===
    echo Binary: target\aarch64-unknown-linux-gnu\release\engine-cli
    goto :eof
)

echo ERROR: Neither 'cargo-zigbuild' nor 'docker' is installed on Windows.
echo.
echo To cross-compile from Windows:
echo   1. pip install ziglang
echo   2. cargo install cargo-zigbuild
echo   3. .\build-aarch64.bat
echo.
echo Or compile directly on Orange Pi (EASIEST, NO EXTRA TOOLS):
echo   ssh edge@^<BOARD_IP^>
echo   cd /home/edge/absensi-cam/clients/edge-engine-rs
echo   cargo build --release --bin engine-cli
echo   sudo install -m 755 target/release/engine-cli /usr/local/bin/smart-absensi-sync
exit /b 1
