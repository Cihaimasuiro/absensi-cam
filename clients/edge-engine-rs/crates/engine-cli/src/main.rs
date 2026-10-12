use anyhow::Result;
use clap::Parser;
use engine_core::EdgeDb;
use engine_sync::SyncWorker;
use std::env;
use tracing::{error, info};

#[derive(Parser, Debug)]
#[command(
    name = "smart-absensi-sync",
    author = "Smart Absensi Team",
    version = "0.1.0",
    about = "Smart Absensi Edge Sync Daemon in Rust (Drains outbox to Laravel API)"
)]
struct Args {
    #[arg(short, long, default_value = "local_edge.db")]
    db: String,

    #[arg(long)]
    api_url: Option<String>,

    #[arg(long)]
    api_token: Option<String>,

    #[arg(long)]
    embed_key: Option<String>,

    #[arg(long)]
    model_version: Option<String>,
}

#[tokio::main]
async fn main() -> Result<()> {
    // 1. Muat file .env jika ada
    dotenvy::dotenv().ok();

    // 2. Inisialisasi logging
    tracing_subscriber::fmt::init();
    let args = Args::parse();

    info!("Memulai Smart Absensi Sync Daemon (Rust)...");

    // 3. Resolusi konfigurasi dari CLI atau Environment Variable
    let api_url = args
        .api_url
        .or_else(|| env::var("SMART_ABSENSI_URL").ok())
        .unwrap_or_else(|| "http://127.0.0.1:8000".to_string());

    let api_token = args
        .api_token
        .or_else(|| env::var("SMART_ABSENSI_TOKEN").ok())
        .unwrap_or_default();

    let embed_key_b64 = args
        .embed_key
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok());

    let embed_key_bytes = embed_key_b64.and_then(|k| {
        use base64::Engine;
        base64::engine::general_purpose::STANDARD.decode(k).ok()
    });

    let model_version = args
        .model_version
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "v1.0-yunet".to_string());

    // 4. Inisialisasi Database SQLite lokal
    info!("Membuka database SQLite pada: {}", args.db);
    let db = EdgeDb::init(&args.db).await?;

    // 5. Buat dan jalankan SyncWorker
    let worker = SyncWorker::new(db, api_url, api_token, embed_key_bytes, model_version)?;

    // Beritahu systemd bahwa daemon telah siap melayani (Type=notify)
    engine_sync::notify_systemd("READY=1\n");

    let worker_handle = tokio::spawn(async move {
        worker.run_loop().await;
    });

    info!("Daemon aktif. Menjalankan jadwal PRD (Outbox: 15s, Templates: 60s, Heartbeat: 60s). Tekan Ctrl+C untuk berhenti.");

    // 6. Tunggu sinyal shutdown
    tokio::select! {
        _ = tokio::signal::ctrl_c() => {
            info!("Menerima sinyal Ctrl+C, mematikan sync daemon secara aman...");
        }
        res = worker_handle => {
            if let Err(e) = res {
                error!("Worker task berhenti tidak terduga: {:?}", e);
            }
        }
    }

    Ok(())
}


