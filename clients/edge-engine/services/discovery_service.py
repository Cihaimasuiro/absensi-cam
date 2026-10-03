"""
services/discovery_service.py — UDP Broadcast Auto-Discovery Service

This runs on the Edge Engine and listens for UDP broadcast packets from the Laravel backend.
When it receives 'ABSENSI_DISCOVER', it responds with its configuration so Laravel can find it.
"""

import json
import logging

logger = logging.getLogger(__name__)
import socket
import threading

from config.settings import load_env

UDP_IP = "0.0.0.0"
UDP_PORT = 55555
BUFFER_SIZE = 1024


class DiscoveryService:
    def __init__(self):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        # Allow multiple instances to bind to same port just in case
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind((UDP_IP, UDP_PORT))
        self.running = False
        self.thread = None

    def start(self):
        if not self.running:
            self.running = True
            self.thread = threading.Thread(target=self._listen_loop, daemon=True)
            self.thread.start()
            logger.info(f"[Discovery] UDP Listener started on port {UDP_PORT}")

    def stop(self):
        self.running = False
        if self.sock:
            self.sock.close()

    def _listen_loop(self):
        while self.running:
            try:
                data, addr = self.sock.recvfrom(BUFFER_SIZE)
                message = data.decode("utf-8").strip()

                if message == "ABSENSI_DISCOVER":
                    self._handle_discover(addr)

            except Exception as e:  # noqa: BLE001

                if self.running:
                    logger.error(f"[Discovery] Error in UDP loop: {e}")

    def _handle_discover(self, addr):
        """Respond to a discover request with our details."""
        cfg = load_env()
        device_id = cfg.get("SMART_ABSENSI_DEVICE_ID", "UNKNOWN")
        backend_url = cfg.get("SMART_ABSENSI_URL", "")

        status = "unpaired"
        if backend_url and "example.com" not in backend_url:
            status = "paired"

        # Get local IP
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(("8.8.8.8", 80))
            local_ip = s.getsockname()[0]
            s.close()
        except Exception:  # noqa: BLE001

            local_ip = "127.0.0.1"

        response = {
            "device_id": device_id,
            "ip_address": local_ip,
            "status": status,
            "port": 5000,  # HTTP API port
        }

        reply = json.dumps(response).encode("utf-8")
        try:
            self.sock.sendto(reply, addr)
            logger.info(f"[Discovery] Answered discover broadcast from {addr[0]}")
        except Exception as e:  # noqa: BLE001

            logger.error(f"[Discovery] Failed to send response: {e}")


def start():
    """Start the discovery service in background"""
    service = DiscoveryService()
    service.start()
    return service
