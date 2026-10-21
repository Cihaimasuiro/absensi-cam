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

    // Top-level arguments for default sync daemon behavior (backward compatibility)
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

    /// Run pure libc V4L2 MJPEG Camera capture, HTTP stream server, and native AI Vision
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

        #[arg(long, default_value_t = true)]
        vision: bool,

        #[arg(long, default_value_t = true)]
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

    /// Audit flash storage health, tmpfs RAM mounts, read-only status, and database durability
    Doctor,

    /// Provision Read-Only RootFS (OverlayFS), tmpfs RAM mounts, and persistent /data partition
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
