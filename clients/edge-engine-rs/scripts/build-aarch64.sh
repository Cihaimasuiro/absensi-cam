#!/usr/bin/env bash
set -euo pipefail

# Script untuk cross-compile edge-engine-rs ke target ARM64 dengan glibc 2.28 (Debian 10 Buster)
# Target: aarch64-unknown-linux-gnu (glibc 2.28)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "=== Building Smart Absensi Sync Daemon for aarch64 (glibc 2.28 / Buster) ==="

cd "${ROOT_DIR}"

if command -v cargo-zigbuild &> /dev/null; then
    echo "Using cargo-zigbuild with target aarch64-unknown-linux-gnu.2.28..."
    rustup target add aarch64-unknown-linux-gnu 2>/dev/null || true
    cargo zigbuild --target aarch64-unknown-linux-gnu.2.28 --release --bin engine-cli
    OUTPUT_BIN="target/aarch64-unknown-linux-gnu/release/engine-cli"
elif command -v docker &> /dev/null; then
    echo "cargo-zigbuild not found. Using Docker debian:buster container..."
    docker run --rm -v "${ROOT_DIR}:/workspace" -w /workspace debian:buster bash -c "
        apt-get update -qq && apt-get install -y -qq curl build-essential gcc-aarch64-linux-gnu
        curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable
        source \$HOME/.cargo/env
        rustup target add aarch64-unknown-linux-gnu
        CARGO_TARGET_AARCH64_UNKNOWN_LINUX_GNU_LINKER=aarch64-linux-gnu-gcc \
        cargo build --target aarch64-unknown-linux-gnu --release --bin engine-cli
    "
    OUTPUT_BIN="target/aarch64-unknown-linux-gnu/release/engine-cli"
else
    echo "ERROR: Neither 'cargo-zigbuild' nor 'docker' was found on host." >&2
    echo "To install cargo-zigbuild:" >&2
    echo "  pip install ziglang" >&2
    echo "  cargo install cargo-zigbuild" >&2
    echo "Or compile natively on Orange Pi:" >&2
    echo "  cargo build --release --bin engine-cli" >&2
    exit 1
fi

if [ -f "${OUTPUT_BIN}" ]; then
    echo ""
    echo "=== Build Success! ==="
    echo "Binary: ${OUTPUT_BIN}"
    echo "To deploy to Orange Pi:"
    echo "  scp ${OUTPUT_BIN} edge@\${BOARD_IP}:/tmp/smart-absensi-sync"
    echo "  ssh edge@\${BOARD_IP} 'sudo install -m 755 /tmp/smart-absensi-sync /usr/local/bin/smart-absensi-sync'"
else
    echo "ERROR: Output binary not found at ${OUTPUT_BIN}" >&2
    exit 1
fi
