use anyhow::Result;
use clap::Parser;

pub mod args;
pub mod diagnostics;
pub mod extract;
pub mod stream;
pub mod sync_runner;
pub mod utils;

pub use args::{Cli, Commands};
pub use extract::{extract_embedding, run_extract};
pub use stream::run_stream;
pub use sync_runner::run_sync;
pub use crate::vision::TrackedStudent;
pub use utils::{find_model_path, model_search_dirs, wait_for_shutdown_signal};

/// Titik masuk utama untuk binary `engine-cli` dan `smart-absensi-sync`.
pub async fn run_cli() -> Result<()> {
    // 1. Muat file .env jika ada (cek CWD, lalu fallback ke /data/config/.env, lalu /opt/smart-absensi/edge-engine/.env)
    dotenvy::dotenv().ok();
    dotenvy::from_path("/data/config/.env").ok();
    dotenvy::from_path("/opt/smart-absensi/edge-engine/.env").ok();

    // 2. Inisialisasi logging ke stderr agar stdout tetap bersih untuk output JSON CLI
    tracing_subscriber::fmt().with_writer(std::io::stderr).init();
    let cli = Cli::parse();

    match cli.command {
        Some(Commands::Preflight { models_dir, ort_path, no_inference }) => {
            let ok = diagnostics::run_preflight(models_dir.as_deref(), ort_path.as_deref(), !no_inference);
            if !ok {
                std::process::exit(1);
            }
            return Ok(());
        }
        Some(Commands::Stream {
            device,
            port,
            width,
            height,
            db,
            models_dir,
            ort_path,
            no_inference,
            skip_preflight,
            liveness,
            embed_key,
            model_version,
            threshold,
            cooldown,
            liveness_margin,
            confirm_frames,
            min_face_size,
            arduino_port,
            arduino_baud,
            arduino_stream,
            framebuffer,
            rfid_port,
            gpio_green,
            gpio_red,
            gpio_buzzer,
        }) => {
            let vision = !no_inference;

            // Fail-fast preflight check: Cegah silent failure & loop zombie jika dependensi kritis hilang
            if !skip_preflight {
                if !diagnostics::run_preflight(models_dir.as_deref(), ort_path.as_deref(), vision) {
                    eprintln!("Preflight gagal. Harap penuhi dependensi di atas atau jalankan dengan --skip-preflight untuk memaksa.");
                    std::process::exit(1);
                }
            }
            run_stream(
                device,
                port,
                width,
                height,
                db,
                models_dir,
                ort_path,
                vision,
                liveness,
                embed_key,
                model_version,
                threshold,
                cooldown,
                liveness_margin,
                confirm_frames,
                min_face_size,
                arduino_port,
                arduino_baud,
                arduino_stream,
                framebuffer,
                rfid_port,
                gpio_green,
                gpio_red,
                gpio_buzzer,
            )
            .await?;
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
        Some(Commands::Doctor) => {
            crate::core::run_doctor()?;
        }
        Some(Commands::HardenStorage {
            data_mount,
            partition,
            fs_type,
            dry_run,
        }) => {
            crate::core::harden_storage(&data_mount, partition.as_deref(), &fs_type, dry_run)?;
        }
        None => {
            // Default: jalankan sync memakai flag level atas (agar kompatibel dengan service systemd lama)
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
