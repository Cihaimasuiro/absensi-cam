#!/bin/bash
set -e

echo "=== M0 Benchmark Runner ==="
sshpass -p orangepi ssh root@192.168.1.200 << 'EOF'
cd /opt/facenox-bench/server
source ../venv/bin/activate

echo "[1/4] Starting server..."
python run.py --host 0.0.0.0 --port 7400 &
SERVER_PID=$!
sleep 15

echo "[2/4] Measuring Idle RSS Memory..."
RSS_KB=$(ps -o rss= -p $SERVER_PID)
RSS_MB=$(echo "$RSS_KB / 1024" | bc)
echo "Server Idle RSS: ${RSS_MB} MB"

echo "[3/4] Measuring Inference Latency & Throughput..."
# Using curl to send a sample image to the face matching endpoint repeatedly
if [ ! -f "test_face.jpg" ]; then
    echo "Creating a dummy test image for benchmarking..."
    # Normally we would use a real face, but for the benchmark we can download a public face image
    curl -s -o test_face.jpg https://raw.githubusercontent.com/opencv/opencv/master/samples/data/lena.jpg
fi

START_TIME=$(date +%s%N)
SUCCESS=0
FAILED=0
# Send 25 requests sequentially
for i in {1..25}; do
    T1=$(date +%s%N)
    # Adjust this endpoint to facenox's actual face matching API. 
    # Usually it's POST /api/recognize with multipart/form-data
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST -F "file=@test_face.jpg" http://localhost:7400/recognize)
    T2=$(date +%s%N)
    LATENCY_MS=$(( (T2 - T1) / 1000000 ))
    echo "Request $i: ${LATENCY_MS} ms (HTTP $HTTP_CODE)"
    if [ "$HTTP_CODE" == "200" ]; then
        SUCCESS=$((SUCCESS+1))
    else
        FAILED=$((FAILED+1))
    fi
done
END_TIME=$(date +%s%N)
TOTAL_MS=$(( (END_TIME - START_TIME) / 1000000 ))
THROUGHPUT=$(echo "scale=2; $SUCCESS / ($TOTAL_MS / 1000)" | bc)

echo "--- Latency Results ---"
echo "Avg Throughput: $THROUGHPUT faces/sec"
echo "Total requests: 25 (Success: $SUCCESS, Failed: $FAILED)"

echo "[4/4] Measuring SoC Temperature..."
TEMP_RAW=$(cat /sys/class/thermal/thermal_zone0/temp)
TEMP_C=$(echo "$TEMP_RAW / 1000" | bc)
echo "SoC Temperature: ${TEMP_C}°C"

echo "Shutting down server..."
kill $SERVER_PID
EOF
echo "=== Benchmark Complete ==="
