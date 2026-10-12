#!/usr/bin/env bash
set -euo pipefail

# Script untuk cross-compile edge-engine-rs ke target ARM64 (Allwinner H6 / Cortex-A53)
# Target: aarch64-unknown-linux-gnu

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "=== Building Smart Absensi Sync Daemon for aarch64-unknown-linux-gnu ==="

if command -v cross &> /dev/null; then
    echo "Using 'cross' tool..."
    cd "${ROOT_DIR}"
    cross build --target aarch64-unknown-linux-gnu --release --bin engine-cli
    echo "Binary produced at: target/aarch64-unknown-linux-gnu/release/engine-cli"
else
    echo "'cross' is not installed. You can install it via: cargo install cross"
    echo "Alternatively, build directly on the Orange Pi using:"
    echo "  cargo build --release --bin engine-cli"
fi
