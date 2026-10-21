#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Smart Absensi Edge Engine - ONNX Runtime Dynamic Library Packager
#
# Packages official Microsoft ONNX Runtime shared libraries (`libonnxruntime.so`)
# for `x86_64` and `aarch64` architectures, matching the `ort` v2.0.0-rc.13
# C-API requirement (ONNX Runtime v1.24.4 / api-24).
# ==============================================================================

ORT_VERSION="1.24.4"
X64_SHA256="3a211fbea252c1e66290658f1b735b772056149f28321e71c308942cdb54b747"
AARCH64_SHA256="866109a9248d057671a039b9d725be4bd86888e3754140e6701ec621be9d4d7e"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist/onnxruntime"
TEMP_DIR="${ROOT_DIR}/target/ort-download-tmp"

TARGET_ARCH="all"
DO_INSTALL=false
INSTALL_DEST="/usr/local/lib"

usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --arch <x86_64|aarch64|all>   Target architecture (default: all)"
    echo "  --output-dir <DIR>            Output directory for packaged assets (default: clients/edge-engine-rs/dist/onnxruntime)"
    echo "  --install                     Install library for host arch to /usr/local/lib (requires sudo if not root)"
    echo "  --install-dir <DIR>           Custom install directory (default: /usr/local/lib)"
    echo "  -h, --help                    Show this help message"
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --arch)
            TARGET_ARCH="$2"
            shift 2
            ;;
        --output-dir)
            DIST_DIR="$2"
            shift 2
            ;;
        --install)
            DO_INSTALL=true
            shift
            ;;
        --install-dir)
            INSTALL_DEST="$2"
            shift 2
            ;;
        -h|--help)
            usage
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage
            ;;
    esac
done

package_arch() {
    local ARCH="$1"
    local TARBALL_NAME=""
    local EXPECTED_SHA=""
    local EXTRACT_DIR=""

    if [ "$ARCH" = "x86_64" ]; then
        TARBALL_NAME="onnxruntime-linux-x64-${ORT_VERSION}.tgz"
        EXPECTED_SHA="$X64_SHA256"
        EXTRACT_DIR="onnxruntime-linux-x64-${ORT_VERSION}"
    elif [ "$ARCH" = "aarch64" ]; then
        TARBALL_NAME="onnxruntime-linux-aarch64-${ORT_VERSION}.tgz"
        EXPECTED_SHA="$AARCH64_SHA256"
        EXTRACT_DIR="onnxruntime-linux-aarch64-${ORT_VERSION}"
    else
        echo "ERROR: Unsupported architecture '$ARCH'. Must be 'x86_64' or 'aarch64'." >&2
        exit 1
    fi

    local DOWNLOAD_URL="https://github.com/microsoft/onnxruntime/releases/download/v${ORT_VERSION}/${TARBALL_NAME}"
    local ARCH_OUT_DIR="${DIST_DIR}/${ARCH}"
    local TARBALL_PATH="${TEMP_DIR}/${TARBALL_NAME}"

    echo "===> Processing ONNX Runtime v${ORT_VERSION} for [${ARCH}]..."
    mkdir -p "${TEMP_DIR}" "${ARCH_OUT_DIR}"

    if [ ! -f "${TARBALL_PATH}" ]; then
        echo "Downloading ${DOWNLOAD_URL}..."
        curl -fSL --retry 3 "${DOWNLOAD_URL}" -o "${TARBALL_PATH}"
    else
        echo "Using existing download cache at ${TARBALL_PATH}"
    fi

    echo "Verifying SHA256 checksum..."
    local ACTUAL_SHA
    ACTUAL_SHA="$(sha256sum "${TARBALL_PATH}" | awk '{print $1}')"
    if [ "${ACTUAL_SHA}" != "${EXPECTED_SHA}" ]; then
        echo "ERROR: Checksum mismatch for ${TARBALL_NAME}!" >&2
        echo "Expected: ${EXPECTED_SHA}" >&2
        echo "Actual:   ${ACTUAL_SHA}" >&2
        rm -f "${TARBALL_PATH}"
        exit 1
    fi
    echo "Checksum verified: OK"

    echo "Extracting shared library..."
    tar -xzf "${TARBALL_PATH}" -C "${TEMP_DIR}"

    local LIB_SO="${TEMP_DIR}/${EXTRACT_DIR}/lib/libonnxruntime.so.${ORT_VERSION}"
    if [ ! -f "${LIB_SO}" ]; then
        echo "ERROR: ${LIB_SO} not found in extracted archive!" >&2
        exit 1
    fi

    # Copy binary and set up symlinks
    cp -f "${LIB_SO}" "${ARCH_OUT_DIR}/libonnxruntime.so.${ORT_VERSION}"
    (cd "${ARCH_OUT_DIR}" && ln -sf "libonnxruntime.so.${ORT_VERSION}" libonnxruntime.so)

    # Generate checksum file for distribution
    (cd "${ARCH_OUT_DIR}" && sha256sum "libonnxruntime.so.${ORT_VERSION}" > SHA256SUMS)

    # Create distributable tarball
    local ARCH_ARCHIVE="${DIST_DIR}/onnxruntime-${ARCH}-v${ORT_VERSION}.tar.gz"
    (cd "${ARCH_OUT_DIR}" && tar -czf "${ARCH_ARCHIVE}" "libonnxruntime.so.${ORT_VERSION}" libonnxruntime.so SHA256SUMS)

    echo "Packaged [${ARCH}] into:"
    echo "  Directory: ${ARCH_OUT_DIR}/"
    echo "  Archive:   ${ARCH_ARCHIVE}"
    echo ""
}

mkdir -p "${DIST_DIR}"

if [ "${TARGET_ARCH}" = "all" ]; then
    package_arch "x86_64"
    package_arch "aarch64"
elif [ "${TARGET_ARCH}" = "x86_64" ] || [ "${TARGET_ARCH}" = "aarch64" ]; then
    package_arch "${TARGET_ARCH}"
else
    echo "ERROR: Invalid arch '${TARGET_ARCH}'. Use 'x86_64', 'aarch64', or 'all'." >&2
    exit 1
fi

if [ "${DO_INSTALL}" = true ]; then
    HOST_ARCH="$(uname -m)"
    if [ "${HOST_ARCH}" != "x86_64" ] && [ "${HOST_ARCH}" != "aarch64" ]; then
        echo "ERROR: Host arch ${HOST_ARCH} not supported for direct installation." >&2
        exit 1
    fi

    SOURCE_DIR="${DIST_DIR}/${HOST_ARCH}"
    if [ ! -f "${SOURCE_DIR}/libonnxruntime.so.${ORT_VERSION}" ]; then
        package_arch "${HOST_ARCH}"
    fi

    echo "===> Installing ONNX Runtime ${HOST_ARCH} to ${INSTALL_DEST}..."
    mkdir -p "${INSTALL_DEST}"
    cp -f "${SOURCE_DIR}/libonnxruntime.so.${ORT_VERSION}" "${INSTALL_DEST}/"
    (cd "${INSTALL_DEST}" && ln -sf "libonnxruntime.so.${ORT_VERSION}" libonnxruntime.so)

    if command -v ldconfig &> /dev/null; then
        echo "Running ldconfig..."
        ldconfig "${INSTALL_DEST}" || true
    fi
    echo "Installation complete: ${INSTALL_DEST}/libonnxruntime.so -> libonnxruntime.so.${ORT_VERSION}"
fi

# Cleanup temporary extraction directory
rm -rf "${TEMP_DIR}"

echo "=== ONNX Runtime Packaging Finished Successfully ==="
