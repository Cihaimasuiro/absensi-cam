use anyhow::Result;
use engine_core::EdgeDb;
use std::time::Duration;
use tracing::{error, info};

pub struct SyncWorker {
    pub db: EdgeDb,
    pub api_url: String,
    pub api_token: String,
}

impl SyncWorker {
    pub fn new(db: EdgeDb, api_url: String, api_token: String) -> Self {
        Self {
            db,
            api_url,
            api_token,
        }
    }

    /// Loop sinkronisasi latar belakang
    pub async fn run_loop(&self, interval_secs: u64) {
        let mut interval = tokio::time::interval(Duration::from_secs(interval_secs));
        loop {
            interval.tick().await;

            // 1. Sync Turso Replica jika aktif
            if let Err(e) = self.db.sync().await {
                error!("Gagal menyinkronkan Turso replica: {:?}", e);
            }

            // 2. Push Outbox ke Laravel API
            if let Err(e) = self.push_outbox_to_laravel().await {
                error!("Gagal push outbox absensi ke Laravel: {:?}", e);
            }
        }
    }

    async fn push_outbox_to_laravel(&self) -> Result<()> {
        info!("Mengecek outbox absensi untuk dikirim ke server pusat...");
        // TODO: Baca baris unsynced dari attendance_outbox dan POST ke /api/v1/attendance
        Ok(())
    }
}
