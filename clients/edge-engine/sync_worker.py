import logging
import threading
import time

import requests

from database import DatabaseManager

logger = logging.getLogger(__name__)


class SyncWorker:
    def __init__(self, db: DatabaseManager, api_url: str, api_token: str):
        self.db = db
        self.api_url = api_url.rstrip("/")
        self.api_token = api_token
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
                self.push_attendance()
                self.pull_templates()
            except Exception as e:  # noqa: BLE001
                logger.error(f"Sync error: {e}")

            time.sleep(self.sync_interval)

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
                    "score": r["score"],
                    "liveness_score": r["liveness_score"],
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
                if templates:
                    logger.info(
                        f"Mengunduh {len(templates)} pembaruan wajah dari server..."
                    )
                    self.db.save_templates(templates)
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
