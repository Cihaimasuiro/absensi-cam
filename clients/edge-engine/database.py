import os
import sqlite3
import logging

logger = logging.getLogger(__name__)


from config.settings import DEFAULT_MODEL_VERSION

class DatabaseManager:
    def __init__(self, key_b64: str = None, db_path="local_edge.db", model_version: str = DEFAULT_MODEL_VERSION):
        self.db_path = os.path.join(os.path.dirname(__file__), db_path)
        import base64
        self.key = base64.b64decode(key_b64) if key_b64 else None
        self.model_version = model_version
        self._template_cache = None
        self._cache_version_cursor = -1
        self._init_db()

    def get_connection(self):
        # Timeout 10s is important for WAL mode to avoid locked database errors
        conn = sqlite3.connect(self.db_path, timeout=10.0)
        conn.row_factory = sqlite3.Row
        return conn

    def _init_db(self):
        logger.info(f"Initializing Edge Database: {self.db_path}")
        with self.get_connection() as conn:
            # Mengaktifkan WAL mode agar baca & tulis tidak saling blokir (mencegah corrupt)
            conn.execute("PRAGMA journal_mode=WAL")
            conn.execute("PRAGMA synchronous=NORMAL")

            # Tabel untuk menyimpan template wajah anggota yang sudah dienkripsi
            conn.execute("""
                CREATE TABLE IF NOT EXISTS templates (
                    id TEXT PRIMARY KEY,
                    student_id TEXT NOT NULL,
                    name TEXT,
                    embedding BLOB NOT NULL,
                    version TEXT,
                    updated_at TIMESTAMP
                )
            """)
            try:
                conn.execute("ALTER TABLE templates ADD COLUMN name TEXT")
            except sqlite3.OperationalError:
                pass

            # Tabel Metadata untuk melacak cursor sync terakhir (walaupun isinya hanya delete)
            conn.execute("""
                CREATE TABLE IF NOT EXISTS metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT
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

    def insert_attendance(
        self,
        id: str,
        student_id: str,
        captured_at: str,
        direction: str,
        score: float,
        liveness_score: float,
        time_source: str,
    ):
        """Menyimpan absensi ke antrean lokal sebelum dikirim ke server."""
        with self.get_connection() as conn:
            conn.execute(
                """INSERT INTO outbox_attendance 
                   (id, student_id, captured_at, direction, score, liveness_score, time_source) 
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (
                    id,
                    student_id,
                    captured_at,
                    direction,
                    score,
                    liveness_score,
                    time_source,
                ),
            )
            conn.commit()

    def get_unsynced_attendance(self, limit=50):
        with self.get_connection() as conn:
            cur = conn.execute(
                "SELECT * FROM outbox_attendance WHERE synced = 0 ORDER BY id ASC LIMIT ?",
                (limit,),
            )
            return [dict(row) for row in cur.fetchall()]

    def mark_as_synced(self, record_ids: list):
        if not record_ids:
            return
        with self.get_connection() as conn:
            placeholders = ",".join("?" * len(record_ids))
            conn.execute(
                f"UPDATE outbox_attendance SET synced = 1 WHERE id IN ({placeholders})",
                tuple(record_ids),
            )
            conn.commit()

    def get_last_template_sync_time(self) -> int:
        with self.get_connection() as conn:
            try:
                cur = conn.execute("SELECT value FROM metadata WHERE key = 'last_sync'")
                row = cur.fetchone()
                if row:
                    return int(row["value"])
            except sqlite3.OperationalError:
                pass

            cur = conn.execute("SELECT MAX(CAST(version AS INTEGER)) as last_sync FROM templates")
            row = cur.fetchone()
            return int(row["last_sync"]) if row and row["last_sync"] else 0

    def save_templates(self, templates_data: list, next_cursor: int = None):
        with self.get_connection() as conn:
            if next_cursor is not None:
                conn.execute("INSERT OR REPLACE INTO metadata (key, value) VALUES ('last_sync', ?)", (str(next_cursor),))

            if not templates_data:
                conn.commit()
                return
            for t in templates_data:
                op = t.get("op", "upsert")
                student_id = t["student_id"]
                
                if op == "delete":
                    conn.execute("DELETE FROM templates WHERE student_id = ?", (student_id,))
                    continue
                    
                import base64
                embedding_data = t.get("embedding_enc", t.get("embedding_b64", ""))
                if isinstance(embedding_data, str):
                    try:
                        embedding_data = base64.b64decode(embedding_data)
                    except Exception:
                        embedding_data = b""

                cur = conn.execute(
                    "SELECT id FROM templates WHERE student_id = ?", (student_id,)
                )
                if cur.fetchone():
                    conn.execute(
                        "UPDATE templates SET embedding = ?, version = ?, updated_at = ?, name = ? WHERE student_id = ?",
                        (
                            embedding_data,
                            str(t.get("version_cursor", "1")),
                            t.get("updated_at"),
                            t.get("name", "Anggota"),
                            student_id,
                        ),
                    )
                else:
                    conn.execute(
                        "INSERT INTO templates (id, student_id, name, embedding, version, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                        (
                            student_id,
                            student_id,
                            t.get("name", "Anggota"),
                            embedding_data,
                            str(t.get("version_cursor", "1")),
                            t.get("updated_at"),
                        ),
                    )
            conn.commit()
            
            # Invalidate cache so it decrypts again
            self._cache_version_cursor = -1

    def decrypt_embedding(self, encrypted_blob: bytes, student_id: str, model_version: str) -> bytes:
        if not self.key or len(encrypted_blob) != 2076:
            # Fallback for unencrypted dummy data (2048 bytes)
            return encrypted_blob if len(encrypted_blob) == 2048 else None
            
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        
        nonce = encrypted_blob[:12]
        ciphertext_and_tag = encrypted_blob[12:]
        aad = f"{student_id}_{model_version}".encode('utf-8')
        
        try:
            aesgcm = AESGCM(self.key)
            return aesgcm.decrypt(nonce, ciphertext_and_tag, aad)
        except Exception as e:
            logger.error(f"Failed to decrypt template for {student_id}: {e}")
            return None

    def get_all_templates(self) -> list:
        """Ambil seluruh face embeddings dari DB lokal untuk proses pencocokan dengan cache in-memory."""
        latest_version = self.get_last_template_sync_time()
        
        # Return cache if valid
        if self._template_cache is not None and self._cache_version_cursor == latest_version:
            return self._template_cache
            
        with self.get_connection() as conn:
            cur = conn.execute(
                "SELECT id, student_id, name, embedding, version FROM templates"
            )
            rows = cur.fetchall()
            
        decrypted_templates = []
        for row in rows:
            student_id = row["student_id"]
            name = row["name"] if ("name" in row.keys() and row["name"]) else "Anggota"
            model_version = self.model_version
            dec_bytes = self.decrypt_embedding(row["embedding"], student_id, model_version)
            if dec_bytes:
                decrypted_templates.append({
                    "student_id": student_id,
                    "embedding": dec_bytes,
                    "name": name
                })
                
        self._template_cache = decrypted_templates
        self._cache_version_cursor = latest_version
        return decrypted_templates
