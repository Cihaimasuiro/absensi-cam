use aes_gcm::{
    aead::{Aead, KeyInit, Payload},
    Aes256Gcm, Nonce,
};
use anyhow::Result;
use libsql::{Builder, Connection, Database};
use std::path::Path;
use tracing::info;

use crate::models::OutboxRecord;

pub struct EdgeDb {
    pub db: Database,
    pub conn: Connection,
}

impl EdgeDb {
    /// Buka database SQLite lokal dalam WAL mode
    pub async fn init(db_path: impl AsRef<Path>) -> Result<Self> {
        info!("Menginisialisasi database SQLite lokal: {:?}", db_path.as_ref());
        let db = Builder::new_local(db_path.as_ref()).build().await?;
        let conn = db.connect()?;

        // Aktifkan WAL mode dan synchronous normal untuk concurrency aman bersama Python
        let _ = conn
            .execute_batch(

                r#"
                PRAGMA journal_mode = WAL;
                PRAGMA synchronous = NORMAL;
                PRAGMA busy_timeout = 5000;
                "#,
            )
            .await;

        let edge_db = Self { db, conn };
        edge_db.migrate().await?;
        Ok(edge_db)
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
                "#,
            )
            .await?;
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

            let embedding_raw = t
                .get("embedding_enc")
                .or_else(|| t.get("embedding_b64"))
                .and_then(|v| v.as_str())
                .unwrap_or("");

            use base64::Engine;
            let embedding_bytes = base64::engine::general_purpose::STANDARD
                .decode(embedding_raw)
                .unwrap_or_default();

            let name = t.get("name").and_then(|v| v.as_str()).unwrap_or("Anggota");
            let version = t
                .get("version_cursor")
                .and_then(|v| v.as_str())
                .unwrap_or("1");
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
        }

        tx.commit().await?;
        Ok(())
    }

    /// Dekripsi AES-256-GCM face embedding identik dengan Python database.py
    pub fn decrypt_embedding(
        key: &[u8],
        encrypted_blob: &[u8],
        student_id: &str,
        model_version: &str,
    ) -> Result<Vec<u8>> {
        if encrypted_blob.len() != 2076 {
            if encrypted_blob.len() == 2048 {
                return Ok(encrypted_blob.to_vec());
            }
            anyhow::bail!("Invalid encrypted blob length: {}", encrypted_blob.len());
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
        let key = [42u8; 32];
        let student_id = "std-12345";
        let model_version = "v1.0-yunet";
        let raw_embedding = vec![0.5f32.to_bits() as u8; 2048];

        let cipher = Aes256Gcm::new_from_slice(&key).unwrap();
        let nonce = [7u8; 12];
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
        // Key 32 bytes 'k'
        let key = b"kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk";
        let student_id = "std-python-99";
        let model_version = "v1.0-yunet";
        let expected_plaintext = vec![b'e'; 2048];

        // Ciphertext 2076 bytes yang di-generate langsung oleh Python cryptography (AESGCM)
        let blob_b64 = "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";
        let blob = base64::engine::general_purpose::STANDARD.decode(blob_b64).unwrap();
        assert_eq!(blob.len(), 2076);

        let decrypted = EdgeDb::decrypt_embedding(key, &blob, student_id, model_version)
            .expect("Harus berhasil mendekripsi ciphertext dari Python");
        assert_eq!(decrypted, expected_plaintext);
    }
}



