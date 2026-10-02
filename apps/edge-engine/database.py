import sqlite3
import logging
import os

class DatabaseManager:
    def __init__(self, db_path="local_edge.db"):
        self.db_path = os.path.join(os.path.dirname(__file__), db_path)
        self._init_db()

    def get_connection(self):
        # Timeout 10s is important for WAL mode to avoid locked database errors
        conn = sqlite3.connect(self.db_path, timeout=10.0)
        conn.row_factory = sqlite3.Row
        return conn

    def _init_db(self):
        logging.info(f"Initializing Edge Database: {self.db_path}")
        with self.get_connection() as conn:
            # Mengaktifkan WAL mode agar baca & tulis tidak saling blokir (mencegah corrupt)
            conn.execute("PRAGMA journal_mode=WAL")
            conn.execute("PRAGMA synchronous=NORMAL")
            
            # Tabel untuk menyimpan template wajah anggota yang sudah dienkripsi
            conn.execute("""
                CREATE TABLE IF NOT EXISTS templates (
                    id TEXT PRIMARY KEY,
                    student_id TEXT NOT NULL,
                    embedding BLOB NOT NULL,
                    version TEXT,
                    updated_at TIMESTAMP
                )
            """)
            
            # Tabel Outbox untuk menyimpan log absensi yang belum terkirim ke Laravel
            conn.execute("""
                CREATE TABLE IF NOT EXISTS outbox_attendance (
                    id TEXT PRIMARY KEY,
                    student_id TEXT NOT NULL,
                    captured_at TEXT NOT NULL,
                    direction TEXT NOT NULL,
                    score REAL,
                    liveness_score REAL,
                    time_source TEXT NOT NULL,
                    synced INTEGER DEFAULT 0
                )
            """)
            conn.commit()

    def insert_attendance(self, id: str, student_id: str, captured_at: str, direction: str, score: float, liveness_score: float, time_source: str):
        """Menyimpan absensi ke antrean lokal sebelum dikirim ke server."""
        with self.get_connection() as conn:
            conn.execute(
                """INSERT INTO outbox_attendance 
                   (id, student_id, captured_at, direction, score, liveness_score, time_source) 
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (id, student_id, captured_at, direction, score, liveness_score, time_source)
            )
            conn.commit()

    def get_unsynced_attendance(self, limit=50):
        with self.get_connection() as conn:
            cur = conn.execute(
                "SELECT * FROM outbox_attendance WHERE synced = 0 ORDER BY id ASC LIMIT ?", 
                (limit,)
            )
            return [dict(row) for row in cur.fetchall()]

    def mark_as_synced(self, record_ids: list):
        if not record_ids:
            return
        with self.get_connection() as conn:
            placeholders = ','.join('?' * len(record_ids))
            conn.execute(
                f"UPDATE outbox_attendance SET synced = 1 WHERE id IN ({placeholders})",
                tuple(record_ids)
            )
            # Secara opsional bisa langsung di-DELETE agar tabel tidak bengkak
            # conn.execute(f"DELETE FROM outbox_attendance WHERE id IN ({placeholders})", tuple(record_ids))
            conn.commit()
    def get_last_template_sync_time(self):
        with self.get_connection() as conn:
            cur = conn.execute("SELECT MAX(updated_at) as last_sync FROM templates")
            row = cur.fetchone()
            return row['last_sync'] if row and row['last_sync'] else None

    def save_templates(self, templates_data: list):
        if not templates_data:
            return
        
        with self.get_connection() as conn:
            for t in templates_data:
                # Assuming embedding is base64 encoded string from API, we should convert to bytes. 
                # For now just save the string/bytes as is
                embedding_data = t.get('embedding', b'')
                
                # Check if exists
                cur = conn.execute("SELECT id FROM templates WHERE student_id = ?", (t['student_id'],))
                if cur.fetchone():
                    conn.execute(
                        "UPDATE templates SET embedding = ?, version = ?, updated_at = ? WHERE student_id = ?",
                        (embedding_data, t.get('version', '1'), t.get('updated_at'), t['student_id'])
                    )
                else:
                    conn.execute(
                        "INSERT INTO templates (id, student_id, embedding, version, updated_at) VALUES (?, ?, ?, ?, ?)",
                        (t.get('id', t['student_id']), t['student_id'], embedding_data, t.get('version', '1'), t.get('updated_at'))
                    )
            conn.commit()
