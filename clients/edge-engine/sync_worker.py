import logging
import threading
import time

import socket
import requests

from database import DatabaseManager

logger = logging.getLogger(__name__)


class SyncWorker:
    def __init__(self, db: DatabaseManager, api_url: str, api_token: str):
        self.db = db
        self.api_url = api_url.strip().rstrip("/") if api_url else ""
        self.api_token = api_token.strip() if api_token else ""
        self.is_running = False
        self.thread = None
        self.sync_interval = 15  # detik

    def start(self):
        if not self.is_running:
            self.is_running = True
            self.thread = threading.Thread(target=self._loop, daemon=True)
            self.thread.start()
            logger.info("Sync Worker started in background.")

    def stop(self):
        self.is_running = False
        if self.thread:
            self.thread.join(timeout=2)
            logger.info("Sync Worker stopped.")

    def _loop(self):
        while self.is_running:
            try:
                self.send_heartbeat()
                self.push_attendance()
                self.pull_templates()
            except Exception as e:  # noqa: BLE001
                logger.error(f"Sync error: {e}")

            time.sleep(self.sync_interval)

    def _get_local_ip(self):
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            # Tidak perlu koneksi internet betulan
            s.connect(("10.255.255.255", 1))
            ip = s.getsockname()[0]
        except Exception:
            ip = "127.0.0.1"
        finally:
            s.close()
        return ip

    def send_heartbeat(self):
        payload = {
            "fw_version": "v1.0.0-python",
            "model_version": "v1.0-yunet",
            "cpu_temp": 45.0,  # TODO: baca dari sistem
            "ram_free_mb": 512, # TODO: baca dari sistem
            "disk_free_mb": 1024,
            "fps": 20.0,
            "outbox_len": len(self.db.get_unsynced_attendance(limit=100)),
            "serial_ok": False,
            "ip_address": self._get_local_ip(),
        }
        
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }
        
        try:
            res = requests.post(
                f"{self.api_url}/api/v1/devices/heartbeat",
                json=payload,
                headers=headers,
                timeout=5,
            )
            if res.status_code != 200:
                logger.error(f"Heartbeat gagal: {res.text}")
        except requests.exceptions.RequestException:
            pass # Abaikan log agar tidak spam saat offline


    def push_attendance(self):
        records = self.db.get_unsynced_attendance(limit=50)
        if not records:
            return  # Tidak ada data baru

        logger.info(f"Mencoba mengirim {len(records)} log absensi ke server...")

        # Sesuai dengan spesifikasi Laravel: /api/v1/attendance/batch
        payload = {
            "records": [
                {
                    "id": r["id"],
                    "student_id": r["student_id"],
                    "captured_at": r["captured_at"],
                    "direction": r["direction"],
                    "score": round(min(1.0, max(0.0, float(r["score"]))), 4) if r.get("score") is not None else 0.5,
                    "liveness_score": round(min(1.0, max(0.0, float(r["liveness_score"]))), 4) if r.get("liveness_score") is not None else 1.0,
                    "time_source": r["time_source"],
                }
                for r in records
            ]
        }

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }

        try:
            response = requests.post(
                f"{self.api_url}/api/v1/attendance/batch",
                json=payload,
                headers=headers,
                timeout=10,
            )

            if response.status_code == 200 or response.status_code == 201:
                # Tandai sebagai synced
                record_ids = [r["id"] for r in records]
                self.db.mark_as_synced(record_ids)
                logger.info(f"Berhasil mensinkronkan {len(record_ids)} absensi.")
            else:
                logger.error(
                    f"Gagal push absensi. HTTP {response.status_code}: {response.text}"
                )

        except requests.exceptions.RequestException:
            logger.info("Menunggu server online untuk push absensi (offline mode).")

    def pull_templates(self):
        last_sync = self.db.get_last_template_sync_time()

        url = f"{self.api_url}/api/v1/templates"
        if last_sync:
            url += f"?cursor={last_sync}"

        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.api_token}",
        }

        try:
            response = requests.get(url, headers=headers, timeout=15)
            if response.status_code == 200:
                data = response.json()
                templates = data.get("items", [])
                next_cursor = data.get("next_cursor")
                if templates or next_cursor is not None:
                    if templates:
                        logger.info(
                            f"Mengunduh {len(templates)} pembaruan wajah dari server..."
                        )
                    self.db.save_templates(templates, next_cursor=next_cursor)
                    if templates:
                        logger.info("Pembaruan data wajah berhasil disimpan.")
            elif response.status_code == 304:
                # No changes
                pass
            else:
                logger.error(
                    f"Gagal pull templates. HTTP {response.status_code}: {response.text}"
                )
        except requests.exceptions.RequestException:
            logger.info("Menunggu server online untuk pull templates (offline mode).")
