#!/bin/bash
set -e

EDGE_HOST="${EDGE_HOST:-192.168.1.200}"
EDGE_USER="${EDGE_USER:-root}"

echo "=== Smart Absensi Edge Security Hardening ==="
echo "Target: $EDGE_USER@$EDGE_HOST"

# 1. Generate SSH Key if it doesn't exist
if [ ! -f "$HOME/.ssh/id_rsa" ]; then
    echo "Generating SSH key..."
    ssh-keygen -t rsa -b 4096 -f "$HOME/.ssh/id_rsa" -N ""
fi

# 2. Copy SSH Key to the board
echo "Copying SSH key to the board. You will be prompted for the CURRENT password."
ssh-copy-id $EDGE_USER@$EDGE_HOST

# 3. Change default password
echo "Changing default password on the board..."
ssh -t $EDGE_USER@$EDGE_HOST "echo 'Please enter the NEW password for $EDGE_USER:'; passwd"

echo "=== Security Hardening Complete ==="
echo "You can now connect to $EDGE_USER@$EDGE_HOST without a password using SSH keys."
