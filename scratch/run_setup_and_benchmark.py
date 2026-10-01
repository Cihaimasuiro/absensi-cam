import paramiko
import os
import tarfile
import sys
import codecs

# Fix windows encoding for printing emojis
sys.stdout = codecs.getwriter("utf-8")(sys.stdout.detach())

HOST = "192.168.10.50"
USER = "root"
PASS = "orangepi"
LOCAL_SERVER = "templates/facenox_repo/server"

def run_remote(ssh, cmd):
    print(f"> {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    
    # Read output line by line as it arrives
    for line in iter(lambda: stdout.readline(), ""):
        print(line, end="")
    for line in iter(lambda: stderr.readline(), ""):
        print(line, end="")
        
    exit_status = stdout.channel.recv_exit_status()
    if exit_status != 0:
        print(f"Command failed with exit {exit_status}")

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting to {HOST}...")
    try:
        ssh.connect(HOST, username=USER, password=PASS, timeout=10)
    except Exception as e:
        print(f"SSH connect failed: {e}")
        return

    # Just run the benchmark part, the rest is already installed!
    print("--- 6. RUN BENCHMARK ---")
    run_remote(ssh, '''
    cat << 'EOF' > /tmp/run_bench.sh
    export FACENOX_DATA_DIR="/opt/facenox-bench/data"
    export ENVIRONMENT=development
    export LD_LIBRARY_PATH="/opt/facenox-bench/conda/lib:${LD_LIBRARY_PATH:-}"
    PYTHON_CMD="/opt/facenox-bench/conda/bin/python"
    
    # Extract the python script from the benchmark script
    sed -n '/cat > \/tmp\/m0_benchmark.py <<.PYEOF./,/PYEOF/p' /tmp/benchmark-orangepi.sh | grep -v 'PYEOF' | grep -v 'cat >' > /tmp/m0_benchmark_extracted.py
    
    ${PYTHON_CMD} /tmp/m0_benchmark_extracted.py
EOF
    bash /tmp/run_bench.sh
    ''')
    
    ssh.close()
    print("DONE!")

if __name__ == "__main__":
    main()
