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
    /// Buka database lokal atau remote replica Turso
    pub async fn init(
        db_path: impl AsRef<Path>,
        turso_url: Option<&str>,
        turso_token: Option<&str>,
    ) -> Result<Self> {
        let db = match (turso_url, turso_token) {
            (Some(url), Some(token)) if !url.is_empty() && !token.is_empty() => {
                info!("Menginisialisasi Turso Embedded Replica: {}", url);
                Builder::new_remote_replica(
                    db_path.as_ref().to_str().unwrap(),
                    url.to_string(),
                    token.to_string(),
                )
                .build()
                .await?
            }
            _ => {
                info!("Menginisialisasi database lokal SQLite: {:?}", db_path.as_ref());
                Builder::new_local(db_path.as_ref()).build().await?
            }
        };

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

    /// Sinkronkan replica dengan Turso cloud jika dalam mode remote replica
    pub async fn sync(&self) -> Result<()> {
        self.db.sync().await?;
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
        let db = EdgeDb::init(":memory:", None, None)
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
}


