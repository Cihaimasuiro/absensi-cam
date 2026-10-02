import time
import threading
import logging
import requests
import json
from database import DatabaseManager

class SyncWorker:
    def __init__(self, db: DatabaseManager, api_url: str, api_token: str):
        self.db = db
        self.api_url = api_url.rstrip('/')
        self.api_token = api_token
        self.is_running = False
        self.thread = None
        self.sync_interval = 15  # detik

    def start(self):
        if not self.is_running:
            self.is_running = True
            self.thread = threading.Thread(target=self._loop, daemon=True)
            self.thread.start()
            logging.info("Sync Worker started in background.")

    def stop(self):
        self.is_running = False
        if self.thread:
            self.thread.join(timeout=2)
            logging.info("Sync Worker stopped.")

    def _loop(self):
        while self.is_running:
            try:
                self.push_attendance()
                self.pull_templates()
            except Exception as e:
                logging.error(f"Sync error: {e}")
            
            time.sleep(self.sync_interval)

    def push_attendance(self):
        records = self.db.get_unsynced_attendance(limit=50)
        if not records:
            return  # Tidak ada data baru

        logging.info(f"Mencoba mengirim {len(records)} log absensi ke server...")
        
        # Sesuai dengan spesifikasi Laravel: /api/v1/attendance/batch
        payload = {
            "records": [
                {
                    "id": r["id"],
                    "member_id": r["member_id"],
                    "captured_at": r["captured_at"],
                    "direction": r["direction"],
                    "score": r["score"],
                    "liveness_score": r["liveness_score"],
                    "time_source": r["time_source"]
                }
                for r in records
            ]
        }

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {self.api_token}"
        }

        try:
            response = requests.post(
                f"{self.api_url}/api/v1/attendance/batch", 
                json=payload, 
                headers=headers,
                timeout=10
            )
            
            if response.status_code == 200 or response.status_code == 201:
                # Tandai sebagai synced
                record_ids = [r["id"] for r in records]
                self.db.mark_as_synced(record_ids)
                logging.info(f"Berhasil mensinkronkan {len(record_ids)} absensi.")
            else:
                logging.error(f"Gagal push absensi. HTTP {response.status_code}: {response.text}")
                
        except requests.exceptions.RequestException as e:
            logging.warning(f"Server tidak dapat dihubungi, absensi tetap aman di Outbox: {e}")

    def pull_templates(self):
        # logging.info("Mengecek pembaruan data wajah dari server...")
        # TODO: Implement GET /api/v1/templates?cursor={last_cursor}
        # 1. Ambil versi/waktu sinkronisasi terakhir dari DB lokal
        # 2. Request ke Laravel
        # 3. Simpan embedding BLOB dan update member_id ke tabel `templates`
        pass

