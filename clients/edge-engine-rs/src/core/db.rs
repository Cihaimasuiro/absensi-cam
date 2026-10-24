use aes_gcm::{
    aead::{Aead, KeyInit, Payload},
    Aes256Gcm, Nonce,
};
use anyhow::Result;
use libsql::{Builder, Connection, Database};
use std::path::Path;
use tracing::info;

use crate::core::models::{OutboxRecord, TemplateRecord};

pub struct EdgeDb {
    pub db: Database,
    pub conn: Connection,
    pub allow_plaintext: std::sync::atomic::AtomicBool,
}

impl EdgeDb {
    /// Buka database SQLite lokal dalam WAL mode
    pub async fn init(db_path: impl AsRef<Path>) -> Result<Self> {
        let path = db_path.as_ref();
        if let Some(parent) = path.parent() {
            if !parent.as_os_str().is_empty() {
                let _ = std::fs::create_dir_all(parent);
            }
        }
        info!("Menginisialisasi database SQLite lokal: {:?}", path);
        let db = Builder::new_local(path).build().await?;
        let conn = db.connect()?;

        // Aktifkan WAL mode dan synchronous normal untuk concurrency aman
        conn.execute_batch(
            r#"
            PRAGMA journal_mode = WAL;
            PRAGMA synchronous = NORMAL;
            PRAGMA busy_timeout = 5000;
            PRAGMA temp_store = MEMORY;
            "#,
        )
        .await?;

        let allow_plaintext_env = std::env::var("ALLOW_PLAINTEXT_EMBEDDINGS")
            .map(|v| v == "1" || v.eq_ignore_ascii_case("true"))
            .unwrap_or(false);

        let edge_db = Self {
            db,
            conn,
            allow_plaintext: std::sync::atomic::AtomicBool::new(allow_plaintext_env),
        };
        edge_db.migrate().await?;
        Ok(edge_db)
    }

    /// Memeriksa apakah plaintext template diizinkan pada instance database ini.
    #[inline]
    pub fn is_plaintext_allowed(&self) -> bool {
        self.allow_plaintext.load(std::sync::atomic::Ordering::Relaxed)
    }

    /// Menyetel izin plaintext template pada instance database ini (hanya untuk pengujian unit test).
    #[cfg(test)]
    #[inline]
    pub fn set_plaintext_allowed(&self, allowed: bool) {
        self.allow_plaintext.store(allowed, std::sync::atomic::Ordering::Relaxed);
    }

    /// Skema tabel SQLite identik dengan Python edge-engine database.py
    async fn migrate(&self) -> Result<()> {
        self.conn
            .execute_batch(
                r#"
                CREATE TABLE IF NOT EXISTS templates (
                    id TEXT PRIMARY KEY,
                    student_id TEXT NOT NULL,
                    name TEXT,
                    embedding BLOB NOT NULL,
                    version TEXT,
                    updated_at TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT
                );

                CREATE TABLE IF NOT EXISTS outbox_attendance (
                    id TEXT PRIMARY KEY,
                    student_id TEXT NOT NULL,
                    captured_at TEXT NOT NULL,
                    direction TEXT NOT NULL,
                    score REAL,
                    liveness_score REAL,
                    time_source TEXT NOT NULL,
                    synced INTEGER DEFAULT 0
                );

                CREATE TABLE IF NOT EXISTS quarantined_templates (
                    student_id TEXT PRIMARY KEY,
                    reason TEXT NOT NULL,
                    version TEXT,
                    quarantined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                "#,
            )
            .await?;

        // Migrasi otomatis template legacy tanpa enkripsi (2048 byte) agar perangkat hasil upgrade tidak terjebak crash loop saat boot
        if !self.is_plaintext_allowed() {
            let mut rows = self
                .conn
                .query("SELECT COUNT(*) FROM templates WHERE length(embedding) = 2048", ())
                .await?;
            let legacy_count: i64 = if let Some(row) = rows.next().await? {
                row.get(0).unwrap_or(0)
            } else {
                0
            };
            if legacy_count > 0 {
                tracing::warn!(
                    "[MIGRASI UPGRADE] Terdeteksi {} template lama (2048-byte plaintext). Memindahkan ke karantina dan me-reset sync cursor 'last_sync' agar server Laravel mengirim ulang data terenkripsi AES-256-GCM secara otomatis.",
                    legacy_count
                );
                let tx = self.conn.transaction().await?;
                tx.execute(
                    "INSERT OR REPLACE INTO quarantined_templates (student_id, reason, version) \
                     SELECT student_id, 'legacy_2048_plaintext', COALESCE(version, '1') \
                     FROM templates WHERE length(embedding) = 2048",
                    (),
                )
                .await?;
                tx.execute("DELETE FROM templates WHERE length(embedding) = 2048", ()).await?;
                tx.execute("DELETE FROM metadata WHERE key = 'last_sync'", ()).await?;
                tx.commit().await?;
                tracing::info!("[MIGRASI UPGRADE] Migrasi selesai. Database lokal siap menerima template terenkripsi dari server.");
            }
        }

        Ok(())
    }


    /// Ambil baris absensi yang belum tersinkronisasi (synced = 0)
    pub async fn get_unsynced_attendance(&self, limit: usize) -> Result<Vec<OutboxRecord>> {
        let mut rows = self
            .conn
            .query(
                "SELECT id, student_id, captured_at, direction, score, liveness_score, time_source, synced \
                 FROM outbox_attendance WHERE synced = 0 ORDER BY rowid ASC LIMIT ?",
                [limit as i64],
            )
            .await?;

        let mut records = Vec::new();
        while let Some(row) = rows.next().await? {
            records.push(OutboxRecord {
                id: row.get(0)?,
                student_id: row.get(1)?,
                captured_at: row.get(2)?,
                direction: row.get(3)?,
                score: row.get::<Option<f64>>(4)?.unwrap_or(0.5),
                liveness_score: row.get::<Option<f64>>(5)?.unwrap_or(1.0),
                time_source: row.get(6)?,
                synced: row.get(7)?,
            });
        }
        Ok(records)
    }

    /// Ambil timestamp absensi terakhir per student_id dalam database
    /// untuk menginisialisasi cooldown_map saat engine startup (mencegah duplikasi pasca-restart)
    pub async fn get_recent_student_attendances(&self) -> Result<Vec<(String, String)>> {
        let mut rows = self
            .conn
            .query(
                "SELECT student_id, MAX(captured_at) FROM outbox_attendance GROUP BY student_id",
                (),
            )
            .await?;

        let mut list = Vec::new();
        while let Some(row) = rows.next().await? {
            let student_id: String = row.get(0)?;
            let captured_at: String = row.get(1)?;
            list.push((student_id, captured_at));
        }
        Ok(list)
    }

    /// Tandai baris absensi sebagai tersinkronisasi (synced = 1)
    pub async fn mark_as_synced(&self, ids: &[String]) -> Result<()> {
        if ids.is_empty() {
            return Ok(());
        }

        let tx = self.conn.transaction().await?;
        for id in ids {
            tx.execute(
                "UPDATE outbox_attendance SET synced = 1 WHERE id = ?",
                [id.as_str()],
            )
            .await?;
        }
        tx.commit().await?;
        Ok(())
    }

    /// Tandai baris absensi yang ditolak/rusak permanen sebagai dikarantina (synced = -1) agar tidak memblokir antrean
    pub async fn quarantine_records(&self, ids: &[String]) -> Result<()> {
        if ids.is_empty() {
            return Ok(());
        }

        let tx = self.conn.transaction().await?;
        for id in ids {
            tx.execute(
                "UPDATE outbox_attendance SET synced = -1 WHERE id = ?",
                [id.as_str()],
            )
            .await?;
        }
        tx.commit().await?;
        Ok(())
    }


    /// Hitung jumlah baris outbox yang belum tersinkronisasi
    pub async fn get_unsynced_count(&self) -> Result<usize> {
        let mut rows = self
            .conn
            .query("SELECT COUNT(*) FROM outbox_attendance WHERE synced = 0", ())
            .await?;
        if let Some(row) = rows.next().await? {
            let count: i64 = row.get(0)?;
            Ok(count as usize)
        } else {
            Ok(0)
        }
    }

    /// Hitung jumlah baris outbox yang dikarantina (synced = -1)
    pub async fn get_quarantined_outbox_count(&self) -> Result<usize> {
        let mut rows = self
            .conn
            .query("SELECT COUNT(*) FROM outbox_attendance WHERE synced = -1", ())
            .await?;
        if let Some(row) = rows.next().await? {
            let count: i64 = row.get(0)?;
            Ok(count as usize)
        } else {
            Ok(0)
        }
    }

    /// Ambil timestamp / cursor sync terakhir dari metadata atau templates
    pub async fn get_last_template_sync_time(&self) -> Result<Option<i64>> {
        let mut rows = self
            .conn
            .query("SELECT value FROM metadata WHERE key = 'last_sync'", ())
            .await?;
        if let Some(row) = rows.next().await? {
            if let Ok(val_str) = row.get::<String>(0) {
                if let Ok(cursor) = val_str.parse::<i64>() {
                    return Ok(Some(cursor));
                }
            }
        }

        let mut max_rows = self
            .conn
            .query("SELECT MAX(CAST(version AS INTEGER)) FROM templates", ())
            .await?;
        if let Some(row) = max_rows.next().await? {
            if let Ok(val) = row.get::<Option<i64>>(0) {
                return Ok(val);
            }
        }
        Ok(None)
    }

    /// Reset cursor sync template agar rekonsiliasi dapat menarik ulang seluruh template
    pub async fn reset_template_sync_cursor(&self) -> Result<()> {
        self.conn
            .execute("DELETE FROM metadata WHERE key = 'last_sync'", ())
            .await?;
        Ok(())
    }

    /// Ambil penanda versi templates (jumlah baris dan versi maksimum) untuk deteksi perubahan hot-reload
    pub async fn get_templates_signature(&self) -> Result<(i64, i64)> {
        let mut rows = self
            .conn
            .query(
                "SELECT COUNT(*), COALESCE(MAX(CAST(version AS INTEGER)), 0) FROM templates",
                (),
            )
            .await?;
        if let Some(row) = rows.next().await? {
            let count: i64 = row.get(0)?;
            let max_v: i64 = row.get(1)?;
            Ok((count, max_v))
        } else {
            Ok((0, 0))
        }
    }

    /// Hitung jumlah dan hash SHA-256 dari seluruh template lokal (student_id:version terurut)
    pub async fn get_templates_hash(&self) -> Result<(usize, String)> {
        let mut rows = self
            .conn
            .query(
                "SELECT student_id, COALESCE(version, '1') FROM templates ORDER BY student_id ASC",
                (),
            )
            .await?;
        let mut combined = String::new();
        let mut count = 0usize;
        while let Some(row) = rows.next().await? {
            let sid: String = row.get(0)?;
            let ver: String = row.get(1)?;
            combined.push_str(&sid);
            combined.push(':');
            combined.push_str(&ver);
            combined.push('\n');
            count += 1;
        }

        use sha2::{Digest, Sha256};
        let mut hasher = Sha256::new();
        hasher.update(combined.as_bytes());
        let hash = format!("{:x}", hasher.finalize());
        Ok((count, hash))
    }

    /// Bersihkan template yang tidak terdaftar dalam valid_ids secara transaksional
    pub async fn purge_unlisted_templates(&self, valid_ids: &[String]) -> Result<usize> {
        let mut rows = self
            .conn
            .query("SELECT student_id FROM templates", ())
            .await?;
        let mut local_ids = Vec::new();
        while let Some(row) = rows.next().await? {
            let sid: String = row.get(0)?;
            local_ids.push(sid);
        }

        let valid_set: std::collections::HashSet<&str> = valid_ids.iter().map(|s| s.as_str()).collect();
        let to_remove: Vec<String> = local_ids
            .into_iter()
            .filter(|id| !valid_set.contains(id.as_str()))
            .collect();

        if to_remove.is_empty() {
            return Ok(0);
        }

        let count = to_remove.len();
        let tx = self.conn.transaction().await?;
        for id in &to_remove {
            tx.execute("DELETE FROM templates WHERE student_id = ?", [id.as_str()]).await?;
        }
        tx.commit().await?;
        Ok(count)
    }

    /// Simpan data templates wajah dari server ke DB lokal
    pub async fn save_templates(
        &self,
        templates_data: &[serde_json::Value],
        next_cursor: Option<i64>,
    ) -> Result<()> {
        let tx = self.conn.transaction().await?;

        if let Some(cursor) = next_cursor {
            tx.execute(
                "INSERT OR REPLACE INTO metadata (key, value) VALUES ('last_sync', ?)",
                [cursor.to_string()],
            )
            .await?;
        }

        for t in templates_data {
            let op = t.get("op").and_then(|v| v.as_str()).unwrap_or("upsert");
            let student_id = match t.get("student_id").and_then(|v| v.as_str()) {
                Some(s) => s,
                None => continue,
            };

            if op == "delete" {
                tx.execute(
                    "DELETE FROM templates WHERE student_id = ?",
                    [student_id],
                )
                .await?;
                continue;
            }

            let allow_plaintext = self.is_plaintext_allowed();

            let embedding_raw = t
                .get("embedding_enc")
                .or_else(|| t.get("embedding_b64"))
                .and_then(|v| v.as_str())
                .unwrap_or("");

            use base64::Engine;
            let embedding_bytes = base64::engine::general_purpose::STANDARD
                .decode(embedding_raw)
                .unwrap_or_default();

            let version_str = match t.get("version_cursor") {
                Some(serde_json::Value::Number(n)) => n.to_string(),
                Some(serde_json::Value::String(s)) => s.clone(),
                _ => "1".to_string(),
            };
            let version = version_str.as_str();

            match embedding_bytes.len() {
                2076 => {} // Valid AES-256-GCM ciphertext dari Laravel
                2048 if allow_plaintext => {
                    tracing::warn!("Sinkronisasi student {}: Memuat template plaintext 2048-byte (ALLOW_PLAINTEXT_EMBEDDINGS aktif).", student_id);
                }
                2048 => {
                    tracing::error!("Sinkronisasi student {}: DITOLAK. Template plaintext (2048 bytes) dilarang di produksi. Masuk karantina.", student_id);
                    tx.execute(
                        "INSERT OR REPLACE INTO quarantined_templates (student_id, reason, version) VALUES (?, 'plaintext_2048_rejected', ?)",
                        (student_id, version),
                    )
                    .await?;
                    continue;
                }
                n => {
                    tracing::warn!("Sinkronisasi student {}: Ukuran blob tidak valid (len={}). Masuk karantina.", student_id, n);
                    tx.execute(
                        "INSERT OR REPLACE INTO quarantined_templates (student_id, reason, version) VALUES (?, ?, ?)",
                        (student_id, format!("invalid_length_{}", n), version),
                    )
                    .await?;
                    continue;
                }
            }

            let name = t.get("name").and_then(|v| v.as_str()).unwrap_or("Anggota");
            let updated_at = t
                .get("updated_at")
                .and_then(|v| v.as_str())
                .unwrap_or("");

            tx.execute(
                "INSERT OR REPLACE INTO templates (id, student_id, name, embedding, version, updated_at) \
                 VALUES (?, ?, ?, ?, ?, ?)",
                (
                    student_id,
                    student_id,
                    name,
                    embedding_bytes,
                    version,
                    updated_at,
                ),
            )
            .await?;

            tx.execute(
                "DELETE FROM quarantined_templates WHERE student_id = ?",
                [student_id],
            )
            .await?;
        }

        tx.commit().await?;
        Ok(())
    }

    /// Hitung jumlah template yang masuk karantina karena format tidak valid atau plaintext ditolak
    pub async fn get_quarantined_templates_count(&self) -> Result<usize> {
        let mut rows = self
            .conn
            .query("SELECT COUNT(*) FROM quarantined_templates", ())
            .await?;
        if let Some(row) = rows.next().await? {
            let count: i64 = row.get(0)?;
            Ok(count as usize)
        } else {
            Ok(0)
        }
    }

    /// Tambahkan absensi baru ke tabel outbox_attendance
    pub async fn insert_attendance(&self, record: &OutboxRecord) -> Result<()> {
        self.conn
            .execute(
                "INSERT OR REPLACE INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (
                    record.id.as_str(),
                    record.student_id.as_str(),
                    record.captured_at.as_str(),
                    record.direction.as_str(),
                    record.score,
                    record.liveness_score,
                    record.time_source.as_str(),
                    record.synced,
                ),
            )
            .await?;
        Ok(())
    }

    /// Muat seluruh template wajah yang tersimpan di DB lokal
    pub async fn load_templates(
        &self,
        embed_key: Option<&[u8]>,
        model_version: &str,
    ) -> Result<Vec<TemplateRecord>> {
        let mut rows = self
            .conn
            .query("SELECT student_id, name, embedding FROM templates", ())
            .await?;

        let mut templates = Vec::new();
        let mut total_in_db = 0usize;
        let mut failed_decrypt = 0usize;

        let allow_plaintext = self.is_plaintext_allowed();

        while let Some(row) = rows.next().await? {
            total_in_db += 1;
            let student_id: String = row.get(0)?;
            let name: String = row.get(1).unwrap_or_else(|_| "Siswa".to_string());
            let blob: Vec<u8> = row.get(2)?;

            let raw_embedding = if blob.len() == 2076 {
                if let Some(key) = embed_key {
                    match Self::decrypt_embedding(key, &blob, &student_id, model_version) {
                        Ok(decrypted) => decrypted,
                        Err(e) => {
                            tracing::warn!("Gagal mendekripsi template student {}: {:#}", student_id, e);
                            failed_decrypt += 1;
                            continue;
                        }
                    }
                } else {
                    tracing::warn!("Embedding student {} terenkripsi tapi embed_key tidak disediakan", student_id);
                    failed_decrypt += 1;
                    continue;
                }
            } else if blob.len() == 2048 {
                if allow_plaintext {
                    tracing::warn!("PERINGATAN: Memuat template student {} yang TIDAK terenkripsi (ALLOW_PLAINTEXT_EMBEDDINGS aktif).", student_id);
                    blob
                } else {
                    tracing::error!("KEAMANAN DITOLAK: Template student {} disimpan tanpa enkripsi (len=2048). Enkripsi AES-256-GCM wajib aktif di produksi.", student_id);
                    failed_decrypt += 1;
                    continue;
                }
            } else {
                tracing::warn!("Ukuran blob template student {} tidak valid (len={})", student_id, blob.len());
                failed_decrypt += 1;
                continue;
            };

            // Parse 512 float32 little endian
            let mut embedding = Vec::with_capacity(512);
            for chunk in raw_embedding.chunks_exact(4) {
                let f = f32::from_le_bytes([chunk[0], chunk[1], chunk[2], chunk[3]]);
                embedding.push(f);
            }

            // Normalisasi L2
            let norm: f32 = embedding.iter().map(|x| x * x).sum::<f32>().sqrt();
            if norm > 1e-6 {
                for v in &mut embedding {
                    *v /= norm;
                }
            }

            templates.push(TemplateRecord {
                student_id,
                name,
                embedding,
            });
        }

        if total_in_db > 0 && templates.is_empty() {
            anyhow::bail!(
                "Database lokal memiliki {} template tersimpan, tetapi TIDAK ADA SATUPUN yang berhasil dimuat/didekripsi ({} gagal). Periksa ENROLLMENT_EMBED_KEY dan MODEL_VERSION.",
                total_in_db, failed_decrypt
            );
        }

        Ok(templates)
    }

    /// Dekripsi AES-256-GCM face embedding identik dengan Python database.py
    pub fn decrypt_embedding(
        key: &[u8],
        encrypted_blob: &[u8],
        student_id: &str,
        model_version: &str,
    ) -> Result<Vec<u8>> {
        if encrypted_blob.len() != 2076 {
            anyhow::bail!(
                "Invalid encrypted blob length: expected 2076 (12-byte nonce + 2048-byte ciphertext + 16-byte tag), got {}",
                encrypted_blob.len()
            );
        }

        let cipher = Aes256Gcm::new_from_slice(key)
            .map_err(|_| anyhow::anyhow!("Invalid AES key length (must be 32 bytes)"))?;

        let nonce = Nonce::from_slice(&encrypted_blob[..12]);
        let ciphertext_and_tag = &encrypted_blob[12..];
        let aad = format!("{}_{}", student_id, model_version);

        let payload = Payload {
            msg: ciphertext_and_tag,
            aad: aad.as_bytes(),
        };

        cipher
            .decrypt(nonce, payload)
            .map_err(|e| anyhow::anyhow!("AES-GCM decryption failed for student {}: {:?}", student_id, e))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_aes_gcm_decryption_with_aad() {
        use aes_gcm::aead::{AeadCore, OsRng};
        let key = Aes256Gcm::generate_key(OsRng);
        let student_id = "std-12345";
        let model_version = "v1.0-yunet";
        let raw_embedding = vec![0.5f32.to_bits() as u8; 2048];

        let cipher = Aes256Gcm::new(&key);
        let nonce = Aes256Gcm::generate_nonce(OsRng);
        let aad = format!("{}_{}", student_id, model_version);
        let payload = Payload {
            msg: &raw_embedding,
            aad: aad.as_bytes(),
        };

        let ciphertext_and_tag = cipher.encrypt(Nonce::from_slice(&nonce), payload).unwrap();
        assert_eq!(ciphertext_and_tag.len(), 2048 + 16);

        let mut encrypted_blob = Vec::with_capacity(2076);
        encrypted_blob.extend_from_slice(&nonce);
        encrypted_blob.extend_from_slice(&ciphertext_and_tag);
        assert_eq!(encrypted_blob.len(), 2076);

        // 1. Sukses dengan AAD yang cocok
        let decrypted = EdgeDb::decrypt_embedding(&key, &encrypted_blob, student_id, model_version)
            .expect("Harus berhasil didekripsi");
        assert_eq!(decrypted, raw_embedding);

        // 2. Gagal jika student_id berbeda (AAD mismatch)
        let failed = EdgeDb::decrypt_embedding(&key, &encrypted_blob, "std-wrong", model_version);
        assert!(failed.is_err(), "Harus gagal jika AAD student_id tidak cocok");

        // 3. Gagal jika model_version berbeda (AAD mismatch)
        let failed_ver = EdgeDb::decrypt_embedding(&key, &encrypted_blob, student_id, "v2.0-arcface");
        assert!(failed_ver.is_err(), "Harus gagal jika AAD model_version tidak cocok");
    }

    #[tokio::test]
    async fn test_edge_db_outbox_operations() {
        let db = EdgeDb::init(":memory:")
            .await
            .expect("Inisialisasi in-memory DB harus berhasil");

        // Masukkan outbox attendance dummy
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-1', 'std-1', '2026-10-12 08:00:00', 'in', 0.92, 0.99, 'rtc', 0), \
                        ('att-2', 'std-2', '2026-10-12 08:05:00', 'in', 0.88, 0.95, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        assert_eq!(db.get_unsynced_count().await.unwrap(), 2);

        let unsynced = db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 2);
        assert_eq!(unsynced[0].id, "att-1");
        assert_eq!(unsynced[1].id, "att-2");

        // Tandai 'att-1' sebagai synced
        db.mark_as_synced(&["att-1".to_string()]).await.unwrap();

        assert_eq!(db.get_unsynced_count().await.unwrap(), 1);

        let remaining = db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(remaining.len(), 1);
        assert_eq!(remaining[0].id, "att-2");
    }

    #[test]
    fn test_aes_gcm_cross_language_python_vector() {
        use base64::Engine;
        // Key 32 byte diturunkan secara dinamis untuk vektor uji lintas bahasa
        let key: Vec<u8> = (0..32).map(|_| b'k').collect();
        let student_id = "std-python-99";
        let model_version = "v1.0-yunet";
        let expected_plaintext = vec![b'e'; 2048];

        // Ciphertext 2076 bytes yang di-generate langsung oleh Python cryptography (AESGCM)
        let blob_b64 = "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";
        let blob = base64::engine::general_purpose::STANDARD.decode(blob_b64).unwrap();
        assert_eq!(blob.len(), 2076);

        let decrypted = EdgeDb::decrypt_embedding(&key, &blob, student_id, model_version)
            .expect("Harus berhasil mendekripsi ciphertext dari Python");
        assert_eq!(decrypted, expected_plaintext);
    }

    #[tokio::test]
    async fn test_sync_templates_accepts_laravel_embedding_b64_shape() {
        use aes_gcm::aead::{AeadCore, OsRng};
        use base64::Engine;

        let db = EdgeDb::init(":memory:").await.expect("In-memory DB harus berhasil");
        let key = Aes256Gcm::generate_key(OsRng);
        let student_id = "std-laravel-101";
        let model_version = "v1.0-yunet";
        let raw_embedding = vec![0.5f32.to_bits() as u8; 2048];

        let cipher = Aes256Gcm::new(&key);
        let nonce = Aes256Gcm::generate_nonce(OsRng);
        let aad = format!("{}_{}", student_id, model_version);
        let payload = Payload {
            msg: &raw_embedding,
            aad: aad.as_bytes(),
        };

        let ciphertext_and_tag = cipher.encrypt(Nonce::from_slice(&nonce), payload).unwrap();
        let mut encrypted_blob = Vec::with_capacity(2076);
        encrypted_blob.extend_from_slice(&nonce);
        encrypted_blob.extend_from_slice(&ciphertext_and_tag);
        assert_eq!(encrypted_blob.len(), 2076);

        let enc_b64 = base64::engine::general_purpose::STANDARD.encode(&encrypted_blob);

        // Simulasi respon JSON persis dari Laravel TemplateController (numeric version_cursor)
        let laravel_response_item = serde_json::json!({
            "student_id": student_id,
            "name": "Siti Rahma",
            "embedding_b64": enc_b64,
            "model_version": model_version,
            "version_cursor": 1001,
            "op": "upsert",
            "updated_at": "2026-10-21T12:00:00Z"
        });

        let sync_payload = vec![laravel_response_item];
        db.save_templates(&sync_payload, Some(1001)).await.expect("Sinkronisasi harus berhasil menerima embedding_b64 dari Laravel");

        // Verifikasi get_templates_signature membaca version_cursor numerik (1001) bukan fallback default (1)
        let (count, max_v) = db.get_templates_signature().await.expect("get_templates_signature harus berhasil");
        assert_eq!(count, 1);
        assert_eq!(max_v, 1001, "max_version harus bernilai 1001 dari JSON integer version_cursor");

        // Verifikasi load_templates berhasil memuat dan mendekripsi
        let loaded = db.load_templates(Some(&key), model_version).await.expect("load_templates harus berhasil");
        assert_eq!(loaded.len(), 1);
        assert_eq!(loaded[0].student_id, student_id);
        assert_eq!(loaded[0].name, "Siti Rahma");

        // Verifikasi load_templates GAGAL TOTAL jika key salah (mencegah silent boot ke reject everyone)
        let mut wrong_key = key;
        wrong_key[0] ^= 0xFF;
        let load_err = db.load_templates(Some(&wrong_key), model_version).await;
        assert!(load_err.is_err(), "Harus error jika database berisi template tapi gagal didekripsi karena key salah");
    }

    #[tokio::test]
    async fn test_save_templates_quarantines_plaintext_2048_in_production() {
        let db = EdgeDb::init(":memory:").await.expect("DB memory init gagal");
        db.set_plaintext_allowed(false);

        use base64::Engine;
        let plaintext_blob = vec![0.1f32.to_bits() as u8; 2048];
        let b64_plaintext = base64::engine::general_purpose::STANDARD.encode(&plaintext_blob);

        let sync_payload = vec![serde_json::json!({
            "student_id": "std-legacy-01",
            "name": "Siswa Legacy",
            "embedding_b64": b64_plaintext,
            "version_cursor": "500",
            "op": "upsert"
        })];

        // Harus berhasil eksekusi tanpa panic, dan menolak plaintext ke karantina
        db.save_templates(&sync_payload, Some(500)).await.expect("save_templates harus sukses");

        let quarantined = db.get_quarantined_templates_count().await.expect("query karantina gagal");
        assert_eq!(quarantined, 1, "Template plaintext 2048 bytes harus masuk tabel karantina");

        // Tabel templates utama harus kosong
        let mut rows = db.conn.query("SELECT COUNT(*) FROM templates", ()).await.unwrap();
        let count: i64 = rows.next().await.unwrap().unwrap().get(0).unwrap();
        assert_eq!(count, 0, "Tabel templates utama tidak boleh menyimpan plaintext saat produksi");
    }

    #[tokio::test]
    async fn test_save_templates_accepts_plaintext_2048_with_override() {
        let db = EdgeDb::init(":memory:").await.expect("DB memory init gagal");
        db.set_plaintext_allowed(true);

        use base64::Engine;
        let plaintext_blob = vec![0.1f32.to_bits() as u8; 2048];
        let b64_plaintext = base64::engine::general_purpose::STANDARD.encode(&plaintext_blob);

        let sync_payload = vec![serde_json::json!({
            "student_id": "std-dev-01",
            "name": "Siswa Dev",
            "embedding_b64": b64_plaintext,
            "version_cursor": "501",
            "op": "upsert"
        })];

        db.save_templates(&sync_payload, Some(501)).await.expect("save_templates harus sukses");

        let mut rows = db.conn.query("SELECT COUNT(*) FROM templates", ()).await.unwrap();
        let count: i64 = rows.next().await.unwrap().unwrap().get(0).unwrap();
        assert_eq!(count, 1, "Template plaintext harus tersimpan saat override aktif");
    }

    #[tokio::test]
    async fn test_legacy_2048_migration_clears_db_and_cursor() {
        let db = EdgeDb::init(":memory:").await.expect("DB memory init gagal");
        db.set_plaintext_allowed(false);

        // Simulasikan DB lama yang sudah menyimpan baris plaintext 2048 byte dan cursor 9999
        let fake_2048 = vec![0u8; 2048];
        db.conn.execute(
            "INSERT INTO templates (id, student_id, name, embedding, version) VALUES (?, ?, ?, ?, ?)",
            ("legacy-1", "legacy-1", "Old Student", fake_2048, "1"),
        ).await.unwrap();
        db.conn.execute(
            "INSERT INTO metadata (key, value) VALUES ('last_sync', '9999')",
            (),
        ).await.unwrap();

        // Jalankan migrate() seperti saat boot engine versi baru
        db.migrate().await.expect("migrate harus berhasil");

        // Verifikasi templates 2048 byte dibersihkan dari templates aktif
        let mut tmpl_rows = db.conn.query("SELECT COUNT(*) FROM templates", ()).await.unwrap();
        let tmpl_count: i64 = tmpl_rows.next().await.unwrap().unwrap().get(0).unwrap();
        assert_eq!(tmpl_count, 0, "Template plaintext lama harus dihapus otomatis saat migrasi");

        // Verifikasi template legacy dicatat di karantina
        let quarantined = db.get_quarantined_templates_count().await.unwrap();
        assert_eq!(quarantined, 1, "Template plaintext lama harus dicatat dalam tabel karantina");

        // Verifikasi cursor di-reset
        let mut cursor_rows = db.conn.query("SELECT COUNT(*) FROM metadata WHERE key = 'last_sync'", ()).await.unwrap();
        let cursor_count: i64 = cursor_rows.next().await.unwrap().unwrap().get(0).unwrap();
        assert_eq!(cursor_count, 0, "Cursor last_sync harus di-reset agar server mengirim ulang data");

        // Verifikasi load_templates mengembalikan Ok(kosong) dan TIDAK crash-loop
        let loaded = db.load_templates(None, "v1.0").await.expect("load_templates tidak boleh crash setelah migrasi");
        assert!(loaded.is_empty(), "Daftar template harus kosong siap menerima sinkronisasi baru");
    }
}



