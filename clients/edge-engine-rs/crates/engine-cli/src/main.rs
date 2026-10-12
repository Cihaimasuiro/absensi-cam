use anyhow::Result;
use bytes::Bytes;
use clap::{Parser, Subcommand};
use engine_core::EdgeDb;
use engine_server::FrameHub;
use engine_sync::SyncWorker;
use std::env;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use tracing::{error, info, warn};

#[derive(Parser, Debug)]
#[command(
    name = "smart-absensi-sync",
    author = "Smart Absensi Team",
    version = "0.1.0",
    about = "Smart Absensi Edge Engine in Rust (Sync Daemon & MJPEG Streamer)"
)]
struct Cli {
    #[command(subcommand)]
    command: Option<Commands>,

    // Top-level arguments for default sync daemon behavior (backward compatibility)
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

#[derive(Subcommand, Debug)]
enum Commands {
    /// Drain local SQLite outbox to Laravel API (default)
    Sync {
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
    },

    /// Run pure libc V4L2 MJPEG Camera capture and HTTP stream server
    Stream {
        #[arg(short, long, default_value = "/dev/video0")]
        device: String,

        #[arg(short, long, default_value_t = 5000)]
        port: u16,

        #[arg(long, default_value_t = 640)]
        width: u32,

        #[arg(long, default_value_t = 480)]
        height: u32,
    },
}

#[tokio::main]
async fn main() -> Result<()> {
    // 1. Muat file .env jika ada
    dotenvy::dotenv().ok();

    // 2. Inisialisasi logging
    tracing_subscriber::fmt::init();
    let cli = Cli::parse();

    match cli.command {
        Some(Commands::Stream {
            device,
            port,
            width,
            height,
        }) => {
            run_stream(device, port, width, height).await?;
        }
        Some(Commands::Sync {
            db,
            api_url,
            api_token,
            embed_key,
            model_version,
        }) => {
            run_sync(db, api_url, api_token, embed_key, model_version).await?;
        }
        None => {
            // Default: run sync using top-level flags (for backward-compatible systemd service)
            run_sync(
                cli.db,
                cli.api_url,
                cli.api_token,
                cli.embed_key,
                cli.model_version,
            )
            .await?;
        }
    }

    Ok(())
}

async fn run_stream(device: String, port: u16, width: u32, height: u32) -> Result<()> {
    info!(
        "Memulai Smart Absensi Stream Server pada port {} (Device: {}, Resolusi: {}x{})...",
        port, device, width, height
    );

    let hub = FrameHub::new();
    let hub_clone = hub.clone();
    let running = Arc::new(AtomicBool::new(true));
    let running_clone = running.clone();

    #[cfg(target_os = "linux")]
    {
        engine_vision::camera::linux::spawn_capture_thread(
            device,
            width,
            height,
            running_clone,
            move |frame_bytes| {
                hub_clone.publish(Bytes::copy_from_slice(frame_bytes));
            },
        );
    }

    #[cfg(not(target_os = "linux"))]
    {
        warn!("V4L2 camera capture is only supported on Linux. Operating in stream-only mode on this platform.");
        // Generate a 1-pixel dummy JPEG or keep None
        let _ = running_clone;
        let _ = hub_clone;
    }

    // Jalankan Axum HTTP stream server
    tokio::select! {
        res = engine_server::start_stream_server(port, hub) => {
            if let Err(e) = res {
                error!("Stream server error: {:#}", e);
            }
        }
        _ = tokio::signal::ctrl_c() => {
            info!("Menerima Ctrl+C, menghentikan stream server...");
            running.store(false, Ordering::Relaxed);
        }
    }

    Ok(())
}

async fn run_sync(
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

    let api_token = api_token_opt
        .or_else(|| env::var("SMART_ABSENSI_TOKEN").ok())
        .unwrap_or_default();

    let embed_key_b64 = embed_key_opt
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok());

    let embed_key_bytes = embed_key_b64.and_then(|k| {
        use base64::Engine;
        base64::engine::general_purpose::STANDARD.decode(k).ok()
    });

    let model_version = model_version_opt
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "v1.0-yunet".to_string());

    info!("Membuka database SQLite pada: {}", db_path);
    let db = EdgeDb::init(&db_path).await?;

    let worker = SyncWorker::new(db, api_url, api_token, embed_key_bytes, model_version)?;

    // Beritahu systemd bahwa daemon telah siap (Type=notify)
    engine_sync::notify_systemd("READY=1\n");

    let worker_handle = tokio::spawn(async move {
        worker.run_loop().await;
    });

    info!("Daemon aktif. Menjalankan jadwal PRD (Outbox: 15s, Templates: 60s, Heartbeat: 60s). Tekan Ctrl+C untuk berhenti.");

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
