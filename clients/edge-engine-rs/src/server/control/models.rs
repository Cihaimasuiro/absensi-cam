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
        if let Some(parent) = Path::new(&db_path).parent() {
            if !parent.as_os_str().is_empty() {
                let _ = std::fs::create_dir_all(parent);
            }
        }
        let settings_path = super::resolve_settings_path(&db_path);

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

// ── Model Data Pengaturan ────────────────────────────────────────────────────

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
    #[serde(default = "default_dark_luma_threshold")]
    pub dark_luma_threshold: f64,
    #[serde(default = "default_static_variance_threshold")]
    pub static_variance_threshold: f64,
}

fn default_dark_luma_threshold() -> f64 {
    10.0
}

fn default_static_variance_threshold() -> f64 {
    4.0
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
pub struct GpioSettings {
    #[serde(default)]
    pub enabled: bool,
    #[serde(default)]
    pub green_pin: Option<u32>,
    #[serde(default)]
    pub red_pin: Option<u32>,
    #[serde(default)]
    pub buzzer_pin: Option<u32>,
    #[serde(default = "default_active_high")]
    pub active_high: bool,
}

fn default_active_high() -> bool {
    true
}

impl Default for GpioSettings {
    fn default() -> Self {
        Self {
            enabled: false,
            green_pin: None,
            red_pin: None,
            buzzer_pin: None,
            active_high: true,
        }
    }
}

/// Selesaikan prioritas pin GPIO: CLI flags > Environment variables > edge_settings.json.
pub fn resolve_gpio_pins(
    cli_green: Option<u32>,
    cli_red: Option<u32>,
    cli_buzzer: Option<u32>,
    env_green: Option<u32>,
    env_red: Option<u32>,
    env_buzzer: Option<u32>,
    env_active_high: Option<bool>,
    settings: &GpioSettings,
) -> (Option<u32>, Option<u32>, Option<u32>, bool) {
    let resolved_active_high = env_active_high.unwrap_or(settings.active_high);

    let (green, red, buzzer) = if settings.enabled {
        (
            cli_green.or(env_green).or(settings.green_pin),
            cli_red.or(env_red).or(settings.red_pin),
            cli_buzzer.or(env_buzzer).or(settings.buzzer_pin),
        )
    } else {
        (
            cli_green.or(env_green),
            cli_red.or(env_red),
            cli_buzzer.or(env_buzzer),
        )
    };

    (green, red, buzzer, resolved_active_high)
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FullSettings {
    pub general: GeneralSettings,
    pub camera: CameraSettings,
    pub network: NetworkSettings,
    pub time: TimeSettings,
    pub server: ServerSettings,
    #[serde(default)]
    pub gpio: GpioSettings,
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
                dark_luma_threshold: 10.0,
                static_variance_threshold: 4.0,
            },
            network: NetworkSettings {
                interface: "eth0".to_string(),
                mode: "dhcp".to_string(),
                wifi_ssid: String::new(),
                wifi_password: None,
                static_ip: String::new(),
                subnet: "255.255.255.0".to_string(),
                gateway: String::new(),
                dns: String::new(),
            },
            time: TimeSettings {
                timezone: std::fs::read_link("/etc/localtime")
                    .ok()
                    .and_then(|p| p.to_string_lossy().split("zoneinfo/").nth(1).map(|s| s.to_string()))
                    .or_else(|| std::fs::read_to_string("/etc/timezone").ok().map(|s| s.trim().to_string()))
                    .filter(|s| !s.is_empty())
                    .unwrap_or_else(|| "Asia/Jakarta".to_string()),
                ntp_enabled: true,
            },
            server: ServerSettings {
                url: env::var("SMART_ABSENSI_URL").unwrap_or_default(),
            },
            gpio: GpioSettings::default(),
        }
    }
}

// ── Payload Request / Response ───────────────────────────────────────────────

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

#[derive(Debug, Deserialize)]
pub struct GpioTestRequest {
    pub pin: u32,
    #[serde(default = "default_active_high")]
    pub active_high: bool,
    #[serde(default = "default_test_duration")]
    pub duration_ms: u64,
}

fn default_test_duration() -> u64 {
    1000
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
    pub camera_is_simulated: bool,
    pub is_streaming: bool,
    pub serial_connected: bool,
    pub camera_warning: Option<String>,
    // ── Telemetri tambahan ───────────────────────────────────────────────
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

// ── Helper Persistensi ───────────────────────────────────────────────────────

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

/// Hasilkan ID perangkat otomatis dari MAC address, machine-id, atau hostname sistem
pub fn generate_device_id() -> String {
    if let Ok(id) = std::env::var("SMART_ABSENSI_DEVICE_ID") {
        let trimmed = id.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }

    #[cfg(target_os = "linux")]
    {
        for iface in &["eth0", "end0", "enp1s0", "wlan0"] {
            let addr_path = format!("/sys/class/net/{}/address", iface);
            if let Ok(mac) = std::fs::read_to_string(&addr_path) {
                let clean: String = mac.chars().filter(|c| c.is_ascii_hexdigit()).collect();
                if clean.len() >= 6 {
                    let suffix = &clean[clean.len() - 6..];
                    return format!("GATE-{}", suffix.to_uppercase());
                }
            }
        }

        if let Ok(machine_id) = std::fs::read_to_string("/etc/machine-id") {
            let clean = machine_id.trim();
            if clean.len() >= 6 {
                return format!("GATE-{}", clean[..6].to_uppercase());
            }
        }
    }

    #[cfg(target_os = "windows")]
    {
        if let Ok(comp) = std::env::var("COMPUTERNAME") {
            let clean: String = comp.chars().filter(|c| c.is_ascii_alphanumeric()).collect();
            if !clean.is_empty() {
                let len = clean.len().min(8);
                return format!("GATE-{}", clean[..len].to_uppercase());
            }
        }
    }

    "GATE-01".to_string()
}

/// Simpan pengaturan ke disk secara atomik menggunakan file temporer dan rename.
/// Menjaga integritas file bila terjadi pemadaman listrik tiba-tiba.
pub fn save_settings_atomic(path: &Path, settings: &FullSettings) -> anyhow::Result<()> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)?;
    }
    let mut to_save = settings.clone();
    // Pertahankan wifi_password yang sudah ada jika pengaturan baru tidak menyediakannya
    if to_save.network.wifi_password.as_deref().unwrap_or("").is_empty() {
        let existing = load_settings(path);
        if let Some(pwd) = existing.network.wifi_password {
            if !pwd.is_empty() {
                to_save.network.wifi_password = Some(pwd);
            }
        }
    }
    let json = serde_json::to_string_pretty(&to_save)?;
    let tmp_path = path.with_extension(format!("tmp.{}", std::process::id()));
    std::fs::write(&tmp_path, json.as_bytes())?;
    std::fs::rename(&tmp_path, path)?;
    Ok(())
}

pub fn save_settings_to_file(path: &Path, settings: &FullSettings) -> anyhow::Result<()> {
    save_settings_atomic(path, settings)
}

/// Konfigurasi mandiri saat boot pertama (first-boot self-configuration).
/// Jika file edge_settings.json belum ada:
/// - Otomatis pilih kamera terbaik (resolusi & fps tertinggi, tolak kamera virtual)
/// - Hasilkan Device ID unik dari MAC address perangkat
/// - Pasang parameter default aman (fps_cap 15, resolusi 640x480)
/// - Simpan secara atomik ke disk sehingga pengguna tidak perlu mengedit JSON manual.
pub fn ensure_first_boot_config(settings_path: &Path) -> anyhow::Result<FullSettings> {
    if settings_path.exists() {
        return Ok(load_settings(settings_path));
    }

    let mut settings = FullSettings::default();

    // 1. Auto-select camera
    if let Some(cam) = crate::vision::auto_select_camera() {
        settings.camera.source = cam;
    }

    // 2. Generate device code dari MAC address / machine-id
    let dev_id = generate_device_id();
    settings.general.device_name = dev_id;

    // 3. Default SBC aman
    settings.camera.resolution = "640x480".to_string();
    settings.camera.fps_cap = 15;

    // 4. Tulis atomik ke disk
    save_settings_atomic(settings_path, &settings)?;
    tracing::info!(
        "Konfigurasi awal (first-boot) berhasil dibuat otomatis di: {}",
        settings_path.display()
    );

    Ok(settings)
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
        let known = ["id", "en"];
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
    if !url.is_empty() && !url.starts_with("http://") && !url.starts_with("https://") {
        return Err("Server URL must begin with http:// or https://".to_string());
    }

    if settings.gpio.enabled {
        let mut assigned = Vec::new();
        if let Some(pin) = settings.gpio.green_pin {
            if pin > 255 {
                return Err("Green LED pin must be between 0 and 255".to_string());
            }
            assigned.push(("Green LED", pin));
        }
        if let Some(pin) = settings.gpio.red_pin {
            if pin > 255 {
                return Err("Red LED pin must be between 0 and 255".to_string());
            }
            if assigned.iter().any(|(_, p)| *p == pin) {
                return Err("Red LED pin cannot duplicate Green LED pin".to_string());
            }
            assigned.push(("Red LED", pin));
        }
        if let Some(pin) = settings.gpio.buzzer_pin {
            if pin > 255 {
                return Err("Buzzer pin must be between 0 and 255".to_string());
            }
            if assigned.iter().any(|(_, p)| *p == pin) {
                return Err("Buzzer pin cannot duplicate an existing LED pin".to_string());
            }
        }
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
