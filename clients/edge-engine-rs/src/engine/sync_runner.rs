use anyhow::Result;
use std::env;
use std::path::{Path, PathBuf};
use tracing::{info, warn};

use crate::core::EdgeDb;
use crate::sync::{self, SyncWorker};
use super::utils::wait_for_shutdown_signal;

pub async fn run_sync(
    db_path: String,
    api_url_opt: Option<String>,
    api_token_opt: Option<String>,
    embed_key_opt: Option<String>,
    model_version_opt: Option<String>,
) -> Result<()> {
    info!("Memulai Smart Absensi Sync Daemon (Rust)...");

    let api_url = api_url_opt
        .or_else(|| env::var("SMART_ABSENSI_URL").ok())
        .unwrap_or_else(|| "http://127.0.0.1:8000".to_string());

    let api_token_raw = api_token_opt
        .or_else(|| env::var("SMART_ABSENSI_TOKEN").ok())
        .unwrap_or_default();

    let embed_key_raw = embed_key_opt
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok());

    let model_version = model_version_opt
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "v1.0-yunet".to_string());

    let env_path = if Path::new("/opt/smart-absensi/edge-engine/.env").exists()
        || Path::new("/opt/smart-absensi/edge-engine").exists()
    {
        PathBuf::from("/opt/smart-absensi/edge-engine/.env")
    } else {
        PathBuf::from(".env")
    };

    // Beritahu systemd bahwa daemon telah aktif (mencegah timeout saat menunggu persetujuan pairing)
    sync::notify_systemd("READY=1\n");

    let (api_token, embed_key_b64) = sync::ensure_authenticated_or_pair(
        &api_url,
        &api_token_raw,
        embed_key_raw,
        &env_path,
        &model_version,
    )
    .await?;

    let embed_key_bytes = embed_key_b64.and_then(|k| {
        use base64::Engine;
        base64::engine::general_purpose::STANDARD.decode(k).ok()
    });

    info!("Membuka database SQLite pada: {}", db_path);
    let db = EdgeDb::init(&db_path).await?;

    let worker = SyncWorker::new(db, api_url, api_token, embed_key_bytes, model_version)?;

    // Beritahu systemd bahwa daemon telah siap (Type=notify)
    sync::notify_systemd("READY=1\n");

    let worker_handle = tokio::spawn(async move {
        worker.run_loop().await;
    });

    info!("Daemon aktif. Menjalankan jadwal PRD (Outbox: 15s, Templates: 60s, Heartbeat: 60s). Tekan Ctrl+C untuk berhenti.");

    tokio::select! {
        _ = worker_handle => {
            warn!("Worker task berhenti.");
        }
        _ = wait_for_shutdown_signal() => {
            info!("Mematikan sync daemon secara tertib...");
        }
    }

    Ok(())
}
