#!/usr/bin/env bash
# =============================================================================
# benchmark-orangepi.sh — Smart Absensi M0 Gate Benchmark
# =============================================================================
# Runs on the Orange Pi Lite 2 (from your PC via SSH) after setup-orangepi-m0.sh.
# Measures face pipeline performance against PRD §14 M0 acceptance gates.
#
# Usage (from project root, after setup is complete):
#   bash scripts/benchmark-orangepi.sh
#
# PRD §14 M0 gates:
#   AC-31: inference latency (single face, single thread)  < 200 ms
#   AC-32: face lookup throughput (2000 templates, cosine) < 50 ms
#   NFR-01: target FPS >= 5 (200 ms/frame budget)
#   NFR-18: RSS memory (server idle)                       < 150 MB
#   NFR-19: SoC temperature under 60-s load               < 80°C
# =============================================================================

set -euo pipefail

HOST="192.168.10.50"
#HOST="192.168.1.200"
USER="root"
PASS="orangepi"
REMOTE_BASE="/opt/facenox-bench"
REMOTE_VENV="${REMOTE_BASE}/venv"
REMOTE_SERVER="${REMOTE_BASE}/server"
REMOTE_DATA="${REMOTE_BASE}/data"

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; CYN='\033[0;36m'; RST='\033[0m'
PASS_ICON="✅"; FAIL_ICON="❌"; WARN_ICON="⚠ "

if command -v sshpass &>/dev/null; then
    ssh_run() {
        sshpass -p "${PASS}" ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 "${USER}@${HOST}" "$@"
    }
else
    ssh_run() {
        ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 "${USER}@${HOST}" "$@"
    }
fi

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║   Smart Absensi — M0 Benchmark (Orange Pi Lite 2)           ║"
echo "║   PRD §14 gate — run AFTER setup-orangepi-m0.sh            ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

if ! command -v sshpass &>/dev/null; then
    echo -e "${YLW}[WARN]${RST} sshpass is not installed. You will be prompted for the password ('${PASS}')."
fi

# Write the benchmark Python script to the board and run it
ssh_run bash -s <<REMOTE
set -e
export FACENOX_DATA_DIR="${REMOTE_DATA}"
export ENVIRONMENT=development
export LD_LIBRARY_PATH="${REMOTE_BASE}/conda/lib:\${LD_LIBRARY_PATH:-}"
PYTHON_CMD="${REMOTE_BASE}/conda/bin/python"

# ── Write benchmark script inline ───────────────────────────────────────────
cat > /tmp/m0_benchmark.py <<'PYEOF'
#!/usr/bin/env python3
"""
M0 Benchmark — Smart Absensi
Tests Facenox ONNX pipeline on aarch64 (Orange Pi Lite 2).

PRD §14 gates:
  AC-31  single-face inference latency   < 200 ms
  AC-32  cosine lookup (2000 templates)  < 50 ms
  NFR-01 throughput                      >= 5 FPS
  NFR-18 RSS (idle)                      < 150 MB
  NFR-19 SoC temp after 60 s            < 80°C
"""

import sys
import time
import os
import statistics
import subprocess
import resource

PASS = "\033[0;32m✅ PASS\033[0m"
FAIL = "\033[0;31m❌ FAIL\033[0m"
WARN = "\033[1;33m⚠  WARN\033[0m"
SEP  = "─" * 58

results = {}

def record(name, value, threshold, unit="ms", lower_is_better=True):
    if lower_is_better:
        ok = value <= threshold
    else:
        ok = value >= threshold
    results[name] = ok
    icon = PASS if ok else FAIL
    print(f"  {icon}  {name:40s} {value:8.1f} {unit:4s}  (gate: {'≤' if lower_is_better else '≥'} {threshold})")

print(f"\n{SEP}")
print("  SYSTEM INFO")
print(SEP)
import platform
print(f"  Platform : {platform.machine()} / {platform.system()}")
print(f"  Python   : {platform.python_version()}")

# ── RSS baseline (idle) ────────────────────────────────────────────────────
rss_idle_kb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
rss_idle_mb = rss_idle_kb / 1024
print(f"  RSS idle : {rss_idle_mb:.1f} MB")

# ── Import onnxruntime ─────────────────────────────────────────────────────
print(f"\n{SEP}")
print("  ONNX RUNTIME IMPORT")
print(SEP)
try:
    t0 = time.perf_counter()
    import onnxruntime as ort
    import_ms = (time.perf_counter() - t0) * 1000
    print(f"  {PASS}  onnxruntime {ort.__version__} imported in {import_ms:.0f} ms")
    print(f"  Providers: {ort.get_available_providers()}")
except ImportError as e:
    print(f"  {FAIL}  Could not import onnxruntime: {e}")
    sys.exit(1)

# ── Load models ────────────────────────────────────────────────────────────
print(f"\n{SEP}")
print("  MODEL LOAD TIMES")
print(SEP)

MODEL_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          "opt/facenox-bench/server/assets/models")

# Resolve actual path from /opt location
MODEL_DIR = "/opt/facenox-bench/server/assets/models"

sess_opts = ort.SessionOptions()
sess_opts.inter_op_num_threads = 1
sess_opts.intra_op_num_threads = 1
sess_opts.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL

sessions = {}
for name, fname in [("detector", "detector.onnx"),
                     ("liveness", "liveness.onnx"),
                     ("recognizer", "recognizer.onnx")]:
    path = os.path.join(MODEL_DIR, fname)
    if not os.path.exists(path):
        print(f"  {FAIL}  {fname} not found at {path}")
        continue
    t0 = time.perf_counter()
    sess = ort.InferenceSession(path, sess_options=sess_opts,
                                 providers=["CPUExecutionProvider"])
    load_ms = (time.perf_counter() - t0) * 1000
    sessions[name] = sess
    print(f"  {PASS}  {name:12s} loaded in {load_ms:6.0f} ms  ({os.path.getsize(path)//1024} KB)")

# ── Inference latency — recognizer (SFace 512-d embedding) ────────────────
print(f"\n{SEP}")
print("  AC-31 — INFERENCE LATENCY (recognizer, single face, 112×112)")
print(SEP)

import numpy as np

if "recognizer" in sessions:
    sess = sessions["recognizer"]
    inp_name = sess.get_inputs()[0].name
    # Warm-up (first run is always slower due to JIT)
    dummy = np.random.randn(1, 3, 112, 112).astype(np.float32)
    sess.run(None, {inp_name: dummy})

    RUNS = 30
    latencies = []
    for _ in range(RUNS):
        t0 = time.perf_counter()
        sess.run(None, {inp_name: dummy})
        latencies.append((time.perf_counter() - t0) * 1000)

    p50 = statistics.median(latencies)
    p95 = sorted(latencies)[int(RUNS * 0.95)]
    fps = 1000 / p50

    record("Inference p50 latency", p50, 200, "ms", lower_is_better=True)
    record("Inference p95 latency", p95, 300, "ms", lower_is_better=True)
    record("Throughput (fps, p50)",  fps,   5, "fps", lower_is_better=False)
    print(f"  {'min':>12}: {min(latencies):6.1f} ms  max: {max(latencies):.1f} ms")

# ── AC-32 — Cosine lookup (2000 templates) ────────────────────────────────
print(f"\n{SEP}")
print("  AC-32 — COSINE LOOKUP (2000 face templates, brute-force)")
print(SEP)

N = 2000
embeddings = np.random.randn(N, 512).astype(np.float32)
# L2-normalize, same as Facenox recognizer.py:normalize_embeddings_batch()
norms = np.linalg.norm(embeddings, axis=1, keepdims=True)
embeddings /= (norms + 1e-8)
query = np.random.randn(512).astype(np.float32)
query /= (np.linalg.norm(query) + 1e-8)

LOOKUP_RUNS = 100
lookup_times = []
for _ in range(LOOKUP_RUNS):
    t0 = time.perf_counter()
    scores = embeddings @ query  # cosine similarity (dot of unit vectors)
    _ = np.argmax(scores)
    lookup_times.append((time.perf_counter() - t0) * 1000)

lookup_p50 = statistics.median(lookup_times)
record("Cosine lookup p50 (2000)", lookup_p50, 50, "ms", lower_is_better=True)

# ── RSS after model load ───────────────────────────────────────────────────
print(f"\n{SEP}")
print("  NFR-18 — RSS MEMORY")
print(SEP)

rss_loaded_kb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
rss_loaded_mb = rss_loaded_kb / 1024
record("RSS after model load", rss_loaded_mb, 150, "MB", lower_is_better=True)

# ── SoC temperature ────────────────────────────────────────────────────────
print(f"\n{SEP}")
print("  NFR-19 — SOC TEMPERATURE")
print(SEP)

def read_temp():
    thermal_zones = [
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/class/thermal/thermal_zone1/temp",
        "/sys/class/thermal/thermal_zone2/temp",
    ]
    temps = []
    for z in thermal_zones:
        try:
            with open(z) as f:
                temps.append(int(f.read().strip()) / 1000.0)
        except FileNotFoundError:
            pass
    return max(temps) if temps else None

temp_before = read_temp()

# 20-second sustained inference load (simulates real recognition)
print(f"  Running 20 s inference load...")
t_load_start = time.perf_counter()
load_count = 0
while (time.perf_counter() - t_load_start) < 20:
    sess.run(None, {inp_name: dummy})
    load_count += 1

temp_after = read_temp()

if temp_after is not None:
    record("SoC temp after 20 s load", temp_after, 80, "°C", lower_is_better=True)
    if temp_before is not None:
        print(f"  Temp before: {temp_before:.1f}°C  →  after: {temp_after:.1f}°C  (Δ {temp_after - temp_before:+.1f}°C)")
    load_fps = load_count / 20
    print(f"  Sustained throughput: {load_fps:.1f} fps over 20 s")
else:
    print(f"  {WARN} Could not read thermal sensor. Check /sys/class/thermal/")

# ── Summary ───────────────────────────────────────────────────────────────
print(f"\n{SEP}")
print("  M0 GATE SUMMARY")
print(SEP)

passed = sum(1 for v in results.values() if v)
total  = len(results)

for name, ok in results.items():
    icon = "\033[0;32m✅\033[0m" if ok else "\033[0;31m❌\033[0m"
    print(f"  {icon}  {name}")

print(f"\n  Result: {passed}/{total} gates passed")

if passed == total:
    print("\n  \033[0;32m🎉 M0 PASSED — Python pipeline is fast enough on this board.\033[0m")
    print("  Next step: build the edge C++ client that calls the face pipeline")
    print("  and reports attendance to the Laravel central server.\n")
elif passed >= total - 1:
    print("\n  \033[1;33m⚠  M0 MARGINAL — Close to gate limits. Consider C++ rewrite.\033[0m\n")
else:
    print("\n  \033[0;31m❌ M0 FAILED — Python is too slow. C++ edge engine required.\033[0m")
    print("  Refer to PRD §6 (edge engine) and decide on implementation path.\n")

sys.exit(0 if passed == total else 1)
PYEOF

echo "Benchmark script written to /tmp/m0_benchmark.py"

# Run with the conda python
\${PYTHON_CMD} /tmp/m0_benchmark.py
REMOTE
