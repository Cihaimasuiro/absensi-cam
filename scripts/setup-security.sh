#!/bin/bash
set -e

EDGE_HOST="${EDGE_HOST:-192.168.1.200}"
ROOT_USER="root"
NEW_USER="${NEW_USER:-absensi}"

echo "=== Smart Absensi Edge Security Hardening ==="
echo "Target: $ROOT_USER@$EDGE_HOST"

# 1. Generate SSH Key (ed25519) if it doesn't exist
KEY_PATH="$HOME/.ssh/id_ed25519"
if [ ! -f "$KEY_PATH" ]; then
    echo "Generating ed25519 SSH key..."
    ssh-keygen -t ed25519 -f "$KEY_PATH" -N ""
fi

# 2. Create non-root user and copy key
echo "Connecting to board as root to create non-root user '$NEW_USER'..."
ssh -t $ROOT_USER@$EDGE_HOST << EOF
    if ! id "$NEW_USER" &>/dev/null; then
        adduser --disabled-password --gecos "" $NEW_USER
        echo "Please set a password for the new user ($NEW_USER):"
        passwd $NEW_USER
        usermod -aG sudo $NEW_USER
    fi
    mkdir -p /home/$NEW_USER/.ssh
    chmod 700 /home/$NEW_USER/.ssh
    chown $NEW_USER:$NEW_USER /home/$NEW_USER/.ssh
EOF

echo "Copying SSH key for $NEW_USER. You will be prompted for the NEW user password."
ssh-copy-id -i "$KEY_PATH" $NEW_USER@$EDGE_HOST

# 3. Disable Root Login and Password Authentication
echo "Verifying key login and disabling password auth/root login..."
ssh -o BatchMode=yes -i "$KEY_PATH" $NEW_USER@$EDGE_HOST << 'EOF'
    sudo sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
    sudo sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
    sudo systemctl restart sshd
EOF

echo "=== Security Hardening Complete ==="
echo "Connect with: ssh $NEW_USER@$EDGE_HOST"
