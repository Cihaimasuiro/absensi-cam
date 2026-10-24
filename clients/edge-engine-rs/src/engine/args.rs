use clap::{Parser, Subcommand};

#[derive(Parser, Debug)]
#[command(
    name = "smart-absensi-sync",
    author = "Smart Absensi Team",
    version = "0.1.0",
    about = "Smart Absensi Edge Engine in Rust (Sync Daemon, MJPEG Streamer & AI Vision)"
)]
pub struct Cli {
    #[command(subcommand)]
    pub command: Option<Commands>,

    // Argumen level atas untuk perilaku default daemon sync (kompatibilitas mundur)
    #[arg(short, long, default_value = "local_edge.db")]
    pub db: String,

    #[arg(long)]
    pub api_url: Option<String>,

    #[arg(long)]
    pub api_token: Option<String>,

    #[arg(long)]
    pub embed_key: Option<String>,

    #[arg(long)]
    pub model_version: Option<String>,
}

#[derive(Subcommand, Debug)]
pub enum Commands {
    /// Kirim habis outbox SQLite lokal ke API Laravel (default)
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

    /// Jalankan diagnosis pra-terbang perangkat & lingkungan (kamera, Python, ONNX, model AI, GPIO)
    Preflight {
        #[arg(long)]
        models_dir: Option<String>,

        #[arg(long)]
        ort_path: Option<String>,

        /// Nonaktifkan pengecekan dependensi AI vision (mode capture-only)
        #[arg(long, default_value_t = false)]
        no_inference: bool,
    },

    /// Jalankan capture kamera MJPEG V4L2 libc murni, server stream HTTP, dan AI Vision native
    Stream {
        #[arg(short, long, default_value = "/dev/video0")]
        device: String,

        #[arg(short, long, default_value_t = 5000)]
        port: u16,

        #[arg(long, default_value_t = 640)]
        width: u32,

        #[arg(long, default_value_t = 480)]
        height: u32,

        #[arg(long, default_value = "local_edge.db")]
        db: String,

        #[arg(long)]
        models_dir: Option<String>,

        #[arg(long)]
        ort_path: Option<String>,

        /// Nonaktifkan AI vision inference (mode capture-only untuk SBC 32-bit / armv7)
        #[arg(long, default_value_t = false)]
        no_inference: bool,

        /// Lewati pengecekan preflight diagnostik saat startup stream
        #[arg(long, default_value_t = false)]
        skip_preflight: bool,

        #[arg(long, num_args = 0..=1, default_value_t = true, default_missing_value = "true", action = clap::ArgAction::Set)]
        liveness: bool,

        #[arg(long)]
        embed_key: Option<String>,

        #[arg(long)]
        model_version: Option<String>,

        #[arg(long, default_value_t = 0.60)]
        threshold: f32,

        #[arg(long)]
        cooldown: Option<u64>,

        #[arg(long)]
        liveness_margin: Option<f32>,

        #[arg(long)]
        confirm_frames: Option<u32>,

        #[arg(long)]
        min_face_size: Option<f32>,

        #[arg(long)]
        arduino_port: Option<String>,

        #[arg(long)]
        arduino_baud: Option<u32>,

        #[arg(long, default_value_t = false)]
        arduino_stream: bool,

        #[arg(long)]
        framebuffer: Option<String>,

        #[arg(long)]
        rfid_port: Option<String>,

        #[arg(long)]
        gpio_green: Option<u32>,

        #[arg(long)]
        gpio_red: Option<u32>,

        #[arg(long)]
        gpio_buzzer: Option<u32>,
    },

    /// Audit kesehatan penyimpanan flash, mount RAM tmpfs, status read-only, dan durabilitas database
    Doctor,

    /// Siapkan RootFS Read-Only (OverlayFS), mount RAM tmpfs, dan partisi /data persisten
    HardenStorage {
        #[arg(long, default_value = "/data")]
        data_mount: String,

        #[arg(long)]
        partition: Option<String>,

        #[arg(long, default_value = "ext4")]
        fs_type: String,

        #[arg(long, default_value_t = false)]
        dry_run: bool,
    },
}

