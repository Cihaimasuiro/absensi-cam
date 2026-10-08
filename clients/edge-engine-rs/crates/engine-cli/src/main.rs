use anyhow::Result;
use clap::Parser;
use engine_core::EdgeDb;
use tracing::info;

#[derive(Parser, Debug)]
#[command(name = "smart-absensi-edge", author, version, about = "Smart Absensi Edge Engine in Rust")]
struct Args {
    #[arg(short, long, default_value = "local_edge.db")]
    db: String,

    #[arg(long)]
    turso_url: Option<String>,

    #[arg(long)]
    turso_token: Option<String>,

    #[arg(short, long, default_value_t = 5000)]
    port: u16,
}

#[tokio::main]
async fn main() -> Result<()> {
    tracing_subscriber::fmt::init();
    let args = Args::parse();

    info!("Memulai Smart Absensi Edge Engine (Rust + Turso)...");

    // Inisialisasi Database Turso (Lokal / Remote Replica)
    let db = EdgeDb::init(&args.db, args.turso_url.as_deref(), args.turso_token.as_deref()).await?;
    info!("Database Turso berhasil diinisialisasi pada: {}", args.db);

    // Jalankan server HTTP stream secara asinkron
    tokio::spawn(async move {
        if let Err(e) = engine_server::start_stream_server(args.port).await {
            tracing::error!("Server error: {:?}", e);
        }
    });

    // Menunggu sinyal shutdown Ctrl+C
    tokio::signal::ctrl_c().await?;
    info!("Mematikan Smart Absensi Edge Engine secara aman.");

    Ok(())
}
