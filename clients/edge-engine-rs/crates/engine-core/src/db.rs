use anyhow::Result;
use libsql::{Builder, Connection, Database};
use std::path::Path;
use tracing::info;

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
                Builder::new_remote_replica(db_path.as_ref().to_str().unwrap(), url.to_string(), token.to_string())
                    .build()
                    .await?
            }
            _ => {
                info!("Menginisialisasi database Turso lokal: {:?}", db_path.as_ref());
                Builder::new_local(db_path.as_ref()).build().await?
            }
        };

        let conn = db.connect()?;
        let edge_db = Self { db, conn };
        edge_db.migrate().await?;
        Ok(edge_db)
    }

    /// Buat skema tabel jika belum ada
    async fn migrate(&self) -> Result<()> {
        self.conn
            .execute_batch(
                r#"
                CREATE TABLE IF NOT EXISTS face_templates (
                    student_id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    embedding BLOB NOT NULL,
                    version INTEGER DEFAULT 1,
                    updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS attendance_outbox (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_id INTEGER NOT NULL,
                    timestamp TEXT NOT NULL,
                    confidence REAL NOT NULL,
                    liveness_score REAL NOT NULL,
                    synced INTEGER DEFAULT 0
                );

                CREATE TABLE IF NOT EXISTS device_meta (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
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
}
