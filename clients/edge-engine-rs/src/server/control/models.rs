use serde::{Deserialize, Serialize};
use std::env;
use std::net::Ipv4Addr;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Instant;
use tracing::{info, warn};

use crate::server::auth::AuthManager;
use crate::server::AppState;

#[derive(Debug, Clone, Default)]
pub struct NetworkRollbackState {
    pub active: bool,
    pub expires_at: Option<Instant>,
    pub backup_settings: Option<NetworkSettings>,
    pub staged_settings: Option<NetworkSettings>,
}

#[derive(Clone)]
pub struct ServerConfig {
    pub db_path: String,
    pub camera_device: String,
    pub width: u32,
    pub height: u32,
    pub model_version: String,
    pub start_time: Instant,
    pub device_code: String,
    pub settings_path: PathBuf,
}

impl Default for ServerConfig {
    fn default() -> Self {
        let db_path = env::var("SMART_ABSENSI_DB").unwrap_or_else(|_| "/data/absensi.db".to_string());
        let settings_path = Path::new(&db_path)
            .parent()
            .unwrap_or(Path::new("."))
            .join("edge_settings.json");

        Self {
            db_path,
            camera_device: env::var("CAMERA_DEVICE").unwrap_or_else(|_| "/dev/video0".to_string()),
            width: 640,
            height: 480,
            model_version: "v1.0-yunet".to_string(),
            start_time: Instant::now(),
            device_code: env::var("SMART_ABSENSI_DEVICE_ID").unwrap_or_else(|_| "GATE-01".to_string()),
            settings_path,
        }
    }
}

#[derive(Clone)]
pub struct ControlState {
    pub app_state: AppState,
    pub config: ServerConfig,
    pub auth: Arc<AuthManager>,
    pub network_rollback: Arc<tokio::sync::Mutex<NetworkRollbackState>>,
}

// ── Settings Data Models ─────────────────────────────────────────────────────

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct GeneralSettings {
    pub language: String,
    pub device_name: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CameraSettings {
    pub source: String,
    pub resolution: String,
    pub fps_cap: u32,
    pub mirror_preview: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetworkSettings {
    pub interface: String,
    pub mode: String,
    pub wifi_ssid: String,
    #[serde(default)]
    pub wifi_password: Option<String>,
    pub static_ip: String,
    pub subnet: String,
    pub gateway: String,
    pub dns: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TimeSettings {
    pub timezone: String,
    pub ntp_enabled: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServerSettings {
    pub url: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FullSettings {
    pub general: GeneralSettings,
    pub camera: CameraSettings,
    pub network: NetworkSettings,
    pub time: TimeSettings,
    pub server: ServerSettings,
}

impl Default for FullSettings {
    fn default() -> Self {
        Self {
            general: GeneralSettings {
                language: "auto".to_string(),
                device_name: env::var("SMART_ABSENSI_DEVICE_ID").unwrap_or_else(|_| "Gate Terminal 01".to_string()),
            },
            camera: CameraSettings {
                source: env::var("CAMERA_DEVICE").unwrap_or_else(|_| "/dev/video0".to_string()),
                resolution: "640x480".to_string(),
                fps_cap: 15,
                mirror_preview: false,
            },
            network: NetworkSettings {
                interface: "eth0".to_string(),
                mode: "dhcp".to_string(),
                wifi_ssid: String::new(),
                wifi_password: None,
                static_ip: String::new(),
                subnet: "255.255.255.0".to_string(),
                gateway: String::new(),
                dns: "1.1.1.1".to_string(),
            },
            time: TimeSettings {
                timezone: "Asia/Jakarta".to_string(),
                ntp_enabled: true,
            },
            server: ServerSettings {
                url: env::var("SMART_ABSENSI_URL").unwrap_or_else(|_| "http://192.168.10.66:8000".to_string()),
            },
        }
    }
}

// ── Request / Response Payloads ──────────────────────────────────────────────

#[derive(Debug, Deserialize)]
pub struct SetupRequest {
    pub code: String,
    pub password: String,
}

#[derive(Debug, Deserialize)]
pub struct LoginRequest {
    pub password: String,
}

#[derive(Debug, Serialize)]
pub struct LoginResponse {
    pub success: bool,
    pub token: Option<String>,
    pub message: String,
}

#[derive(Debug, Deserialize)]
pub struct PasswordChangeRequest {
    pub current: String,
    pub new_pwd: String,
}

#[derive(Debug, Deserialize)]
pub struct ActionRequest {
    pub action: String,
    pub confirm_code: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ActionResponse {
    pub success: bool,
    pub message: String,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct ControlInfoResponse {
    pub device_code: String,
    pub hostname: String,
    pub firmware_version: String,
    pub model_version: String,
    pub local_ip: String,
    pub server_url: String,
    pub is_paired: bool,
    pub cpu_temp_c: f64,
    pub free_ram_mb: i64,
    pub free_disk_mb: i64,
    pub uptime_sec: u64,
    pub template_count: i64,
    pub outbox_count: i64,
    pub quarantined_count: i64,
    pub camera_device: String,
    pub camera_resolution: String,
    pub is_streaming: bool,
    pub serial_connected: bool,
    // ── Extended telemetry ──────────────────────────────────────────────
    pub cpu_usage_pct: f64,
    pub cpu_cores: usize,
    pub cpu_model: String,
    pub ram_total_mb: i64,
    pub ram_used_mb: i64,
    pub ram_usage_pct: f64,
    pub disk_total_mb: i64,
    pub disk_used_mb: i64,
    pub disk_usage_pct: f64,
    pub load_avg_1: f64,
    pub load_avg_5: f64,
    pub load_avg_15: f64,
    pub process_ram_mb: i64,
    pub process_cpu_pct: f64,
    pub os_name: String,
    pub kernel_version: String,
    pub arch: String,
    pub uptime_system_sec: u64,
    pub outbox_bytes: i64,
    pub db_size_mb: f64,
    pub last_sync_at: Option<String>,
    pub network_interface: String,
    pub network_ip: String,
    pub network_mac: String,
    pub network_rx_bytes: u64,
    pub network_tx_bytes: u64,
    pub wifi_ssid: Option<String>,
    pub wifi_signal_dbm: Option<i32>,
    pub throttled: Option<String>,
    pub power_volts: Option<f64>,
}

// ── Persistence Helpers ──────────────────────────────────────────────────────

pub fn load_settings(path: &Path) -> FullSettings {
    if path.exists() {
        if let Ok(c) = std::fs::read_to_string(path) {
            if let Ok(s) = serde_json::from_str::<FullSettings>(&c) {
                return s;
            }
        }
    }
    FullSettings::default()
}

pub fn save_settings_to_file(path: &Path, settings: &FullSettings) -> anyhow::Result<()> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)?;
    }
    let mut to_save = settings.clone();
    // Preserve existing wifi_password if the new settings did not provide one
    if to_save.network.wifi_password.as_deref().unwrap_or("").is_empty() {
        let existing = load_settings(path);
        if let Some(pwd) = existing.network.wifi_password {
            if !pwd.is_empty() {
                to_save.network.wifi_password = Some(pwd);
            }
        }
    }
    let json = serde_json::to_string_pretty(&to_save)?;
    std::fs::write(path, json)?;
    Ok(())
}

pub fn validate_settings(settings: &FullSettings) -> Result<(), String> {
    let dev_name = settings.general.device_name.trim();
    if dev_name.is_empty() || dev_name.len() > 64 {
        return Err("Device name must be between 1 and 64 characters".to_string());
    }

    if settings.camera.fps_cap < 5 || settings.camera.fps_cap > 60 {
        return Err("FPS cap must be between 5 and 60".to_string());
    }
    let src = settings.camera.source.trim();
    if src.is_empty() || src.len() > 64 {
        return Err("Camera source path is invalid".to_string());
    }
    let lang = settings.general.language.trim();
    if lang != "auto" && !lang.is_empty() {
        let known = ["id", "en", "ms", "ar", "zh", "ja"];
        if !known.contains(&lang) {
            return Err(format!(
                "Unsupported language '{}'. Use 'auto' or one of: {}",
                lang,
                known.join(", ")
            ));
        }
    }

    let res = settings.camera.resolution.trim();
    let parts: Vec<&str> = res.split('x').collect();
    if parts.len() != 2 || parts[0].parse::<u32>().is_err() || parts[1].parse::<u32>().is_err() {
        return Err("Camera resolution must be in format 'WIDTHxHEIGHT' (e.g. 640x480)".to_string());
    }

    let mode = settings.network.mode.trim().to_lowercase();
    if mode != "dhcp" && mode != "static" {
        return Err("Network mode must be 'dhcp' or 'static'".to_string());
    }
    if mode == "static" {
        if settings.network.static_ip.trim().parse::<Ipv4Addr>().is_err() {
            return Err("Invalid static IP address format".to_string());
        }
        if settings.network.subnet.trim().parse::<Ipv4Addr>().is_err() {
            return Err("Invalid subnet mask format".to_string());
        }
        if !settings.network.gateway.trim().is_empty() 
            && settings.network.gateway.trim().parse::<Ipv4Addr>().is_err() {
            return Err("Invalid gateway IP address format".to_string());
        }
        if !settings.network.dns.trim().is_empty() 
            && settings.network.dns.trim().parse::<Ipv4Addr>().is_err() {
            return Err("Invalid DNS IP address format".to_string());
        }
    }

    let tz = settings.time.timezone.trim();
    if !is_valid_timezone(tz) {
        return Err("Invalid timezone format (e.g. Asia/Jakarta)".to_string());
    }

    let url = settings.server.url.trim();
    if !url.starts_with("http://") && !url.starts_with("https://") {
        return Err("Server URL must begin with http:// or https://".to_string());
    }

    Ok(())
}

pub fn is_valid_timezone(tz: &str) -> bool {
    let trimmed = tz.trim();
    !trimmed.is_empty()
        && trimmed.len() <= 64
        && !trimmed.starts_with('-')
        && trimmed.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '/' | '_' | '-' | '+'))
}

pub fn apply_time_settings(time: &TimeSettings) {
    let tz = time.timezone.trim();
    if is_valid_timezone(tz) {
        info!("Applying system timezone: {}", tz);
        match std::process::Command::new("timedatectl")
            .args(["set-timezone", tz])
            .status()
        {
            Ok(s) if !s.success() => warn!("timedatectl set-timezone exited with status: {s}"),
            Err(e) => warn!("timedatectl command execution failed: {e}"),
            _ => {}
        }
    } else if !tz.is_empty() {
        warn!("Skipping invalid timezone in time settings: {}", tz);
    }

    let ntp_arg = if time.ntp_enabled { "true" } else { "false" };
    info!("Setting NTP synchronization: {}", ntp_arg);
    match std::process::Command::new("timedatectl")
        .args(["set-ntp", ntp_arg])
        .status()
    {
        Ok(s) if !s.success() => warn!("timedatectl set-ntp exited with status: {s}"),
        Err(e) => warn!("timedatectl command execution failed: {e}"),
        _ => {}
    }
}
