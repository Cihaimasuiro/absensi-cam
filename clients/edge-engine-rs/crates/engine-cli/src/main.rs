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

    #[arg(long, default_value_t = 15)]
    sync_interval: u64,

    #[arg(long)]
    turso_url: Option<String>,

    #[arg(long)]
    turso_token: Option<String>,
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

    let turso_url = args.turso_url.or_else(|| env::var("TURSO_DATABASE_URL").ok());
    let turso_token = args.turso_token.or_else(|| env::var("TURSO_AUTH_TOKEN").ok());

    // 4. Inisialisasi Database SQLite / Turso
    info!("Membuka database SQLite pada: {}", args.db);
    let db = EdgeDb::init(&args.db, turso_url.as_deref(), turso_token.as_deref()).await?;

    // 5. Buat dan jalankan SyncWorker
    let worker = SyncWorker::new(db, api_url, api_token, embed_key_bytes)?;

    let worker_handle = tokio::spawn(async move {
        worker.run_loop(args.sync_interval).await;
    });

    info!(
        "Daemon aktif. Menguras outbox setiap {} detik. Tekan Ctrl+C untuk berhenti.",
        args.sync_interval
    );

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

