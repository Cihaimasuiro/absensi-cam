use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{Html, IntoResponse, Json, Response},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use std::env;
use std::net::Ipv4Addr;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Instant;
use tracing::{info, warn};

use crate::server::auth::AuthManager;
use crate::server::AppState;

const UI_HTML: &str = include_str!("ui.html");
const ALPINE_JS: &str = include_str!("vendor/alpine.min.js");

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

// ── HTML Page Handler ────────────────────────────────────────────────────────

pub async fn ui_page_handler() -> Html<String> {
    let rendered = UI_HTML.replace("__ALPINE_VENDORED_BUNDLE__", ALPINE_JS);
    Html(rendered)
}

// ── Auth Handlers ────────────────────────────────────────────────────────────

pub async fn setup_handler(
    State(state): State<ControlState>,
    Json(payload): Json<SetupRequest>,
) -> impl IntoResponse {
    match state.auth.setup_first_password(&payload.code, &payload.password) {
        Ok(()) => (
            StatusCode::OK,
            Json(serde_json::json!({ "success": true, "message": "Password initialized" })),
        ),
        Err(err) => (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "message": err })),
        ),
    }
}

pub async fn login_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(payload): Json<LoginRequest>,
) -> Response {
    let ip = headers
        .get("x-forwarded-for")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.split(',').next())
        .map(|s| s.trim().to_string())
        .unwrap_or_else(|| "127.0.0.1".to_string());

    match state.auth.verify_login(&ip, &payload.password) {
        Ok(token) => {
            // HttpOnly, SameSite=Strict cookie with 1h TTL, no Secure flag (device serves plain HTTP)
            let cookie_header = format!(
                "control_session={}; HttpOnly; SameSite=Strict; Path=/; Max-Age=3600",
                token
            );

            (
                StatusCode::OK,
                [(header::SET_COOKIE, cookie_header)],
                Json(LoginResponse {
                    success: true,
                    token: Some(token),
                    message: "Authentication successful".to_string(),
                }),
            )
                .into_response()
        }
        Err((code, msg)) => (
            code,
            Json(LoginResponse {
                success: false,
                token: None,
                message: msg,
            }),
        )
            .into_response(),
    }
}

pub async fn logout_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> impl IntoResponse {
    state.auth.logout(&headers);
    (
        StatusCode::OK,
        [(header::SET_COOKIE, "control_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0")],
        Json(serde_json::json!({ "success": true, "message": "Logged out" })),
    )
}

// ── Settings Handlers ────────────────────────────────────────────────────────

pub async fn get_settings_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    let mut settings = load_settings(&state.config.settings_path);
    settings.network.wifi_password = None;
    Json(settings).into_response()
}

pub async fn save_settings_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(new_settings): Json<FullSettings>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    if let Err(e) = validate_settings(&new_settings) {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "error": e }))).into_response();
    }

    if let Err(e) = save_settings_to_file(&state.config.settings_path, &new_settings) {
        warn!("Failed to save settings: {}", e);
        return (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({ "error": "Failed to persist settings" }))).into_response();
    }

    apply_time_settings(&new_settings.time);
    state.app_state.frame_hub.set_mirror(new_settings.camera.mirror_preview);

    info!(
        "Edge settings updated: language={}, mirror_preview={}",
        new_settings.general.language, new_settings.camera.mirror_preview
    );
    Json(serde_json::json!({
        "success": true,
        "message": "Settings saved",
        "mirror_preview": new_settings.camera.mirror_preview,
        "language": new_settings.general.language
    }))
    .into_response()
}

pub async fn get_camera_devices_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    let mut devices = Vec::new();
    for i in 0..10 {
        let path = format!("/dev/video{}", i);
        if Path::new(&path).exists() {
            devices.push(path);
        }
    }

    // No fabricated fallback: an empty list is honest and lets the UI say
    // "no camera found" instead of offering a device that does not exist.
    Json(serde_json::json!({
        "devices": devices,
        "count": devices.len()
    })).into_response()
}

pub async fn apply_network_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(payload): Json<NetworkSettings>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    let mode = payload.mode.trim().to_lowercase();
    if mode != "dhcp" && mode != "static" {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "error": "Mode must be 'dhcp' or 'static'" }))).into_response();
    }

    if mode == "static" {
        if payload.static_ip.trim().parse::<Ipv4Addr>().is_err() {
            return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "error": "Invalid static IP address format" }))).into_response();
        }
        if payload.subnet.trim().parse::<Ipv4Addr>().is_err() {
            return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "error": "Invalid subnet mask format" }))).into_response();
        }
    }

    let current = load_settings(&state.config.settings_path);
    let backup_dir = state.config.settings_path.parent().unwrap_or(Path::new("."));
    let backup_path = backup_dir.join("network_backup.json");
    let staged_path = backup_dir.join("network_staged.json");
    let confirmed_path = backup_dir.join("network_confirmed");

    let _ = std::fs::remove_file(&confirmed_path);
    let _ = std::fs::write(&backup_path, serde_json::to_string_pretty(&current.network).unwrap_or_default());
    let _ = std::fs::write(&staged_path, serde_json::to_string_pretty(&payload).unwrap_or_default());

    {
        let mut lock = state.network_rollback.lock().await;
        lock.active = true;
        lock.expires_at = Some(Instant::now() + std::time::Duration::from_secs(60));
        lock.backup_settings = Some(current.network.clone());
        lock.staged_settings = Some(payload.clone());
    }

    let helper_path = "/usr/local/bin/smart-absensi-syshelper";
    if Path::new(helper_path).exists() {
        let _ = std::process::Command::new(helper_path)
            .args(["apply-network", staged_path.to_str().unwrap_or("")])
            .spawn();
    }

    let state_watchdog = state.clone();
    let settings_path = state.config.settings_path.clone();
    tokio::spawn(async move {
        tokio::time::sleep(std::time::Duration::from_secs(60)).await;
        let mut lock = state_watchdog.network_rollback.lock().await;
        if lock.active {
            warn!("Network rollback timeout expired (60s) without confirmation. Reverting network settings...");
            lock.active = false;
            if let Some(ref backup) = lock.backup_settings {
                let mut cur = load_settings(&settings_path);
                cur.network = backup.clone();
                let _ = save_settings_to_file(&settings_path, &cur);

                if Path::new(helper_path).exists() {
                    let _ = std::process::Command::new(helper_path)
                        .args(["revert-network", backup_path.to_str().unwrap_or("")])
                        .spawn();
                }
            }
        }
    });

    Json(serde_json::json!({
        "success": true,
        "rollback_seconds": 60,
        "message": "Network configuration applied. Confirm within 60 seconds or changes will revert."
    })).into_response()
}

pub async fn confirm_network_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    let mut lock = state.network_rollback.lock().await;
    if !lock.active {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({
            "success": false,
            "message": "No active network change to confirm."
        }))).into_response();
    }

    lock.active = false;
    if let Some(staged) = lock.staged_settings.take() {
        let mut cur = load_settings(&state.config.settings_path);
        cur.network = staged;
        let _ = save_settings_to_file(&state.config.settings_path, &cur);
    }
    lock.backup_settings = None;

    let backup_dir = state.config.settings_path.parent().unwrap_or(Path::new("."));
    let confirmed_path = backup_dir.join("network_confirmed");
    let _ = std::fs::write(&confirmed_path, "1");

    let helper_path = "/usr/local/bin/smart-absensi-syshelper";
    if Path::new(helper_path).exists() {
        let _ = std::process::Command::new(helper_path)
            .args(["confirm-network"])
            .spawn();
    }

    Json(serde_json::json!({
        "success": true,
        "message": "Network settings confirmed permanently."
    })).into_response()
}

pub async fn cancel_network_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    let mut lock = state.network_rollback.lock().await;
    if lock.active {
        lock.active = false;
        if let Some(ref backup) = lock.backup_settings {
            let mut cur = load_settings(&state.config.settings_path);
            cur.network = backup.clone();
            let _ = save_settings_to_file(&state.config.settings_path, &cur);

            let helper_path = "/usr/local/bin/smart-absensi-syshelper";
            let backup_dir = state.config.settings_path.parent().unwrap_or(Path::new("."));
            let backup_path = backup_dir.join("network_backup.json");
            if Path::new(helper_path).exists() {
                let _ = std::process::Command::new(helper_path)
                    .args(["revert-network", backup_path.to_str().unwrap_or("")])
                    .spawn();
            }
        }
    }

    Json(serde_json::json!({
        "success": true,
        "message": "Network configuration reverted."
    })).into_response()
}

pub async fn network_rollback_status_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    let lock = state.network_rollback.lock().await;
    let remaining = if lock.active {
        if let Some(exp) = lock.expires_at {
            let now = Instant::now();
            if exp > now {
                exp.duration_since(now).as_secs()
            } else {
                0
            }
        } else {
            0
        }
    } else {
        0
    };

    Json(serde_json::json!({
        "active": lock.active,
        "remaining_seconds": remaining
    })).into_response()
}

pub async fn change_password_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(payload): Json<PasswordChangeRequest>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    if payload.new_pwd.trim().len() < 8 {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "success": false, "message": "New password must be at least 8 characters" }))).into_response();
    }

    // Verify current password first
    let ip = "127.0.0.1";
    if state.auth.verify_login(ip, &payload.current).is_err() {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "success": false, "message": "Current password incorrect" }))).into_response();
    }

    if let Err(e) = state.auth.save_password(&payload.new_pwd) {
        return (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({ "success": false, "message": e }))).into_response();
    }

    Json(serde_json::json!({ "success": true, "message": "Password changed successfully" })).into_response()
}

// ── Telemetry & Actions ──────────────────────────────────────────────────────

pub async fn info_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.is_initialized() {
        return (
            StatusCode::PRECONDITION_REQUIRED,
            Json(serde_json::json!({ "needs_setup": true, "message": "First-time setup required" })),
        )
            .into_response();
    }

    if !state.auth.check_session(&headers) {
        return (
            StatusCode::UNAUTHORIZED,
            Json(serde_json::json!({ "error": "Unauthorized" })),
        )
            .into_response();
    }

    let (templates, outbox) = query_db_counts(&state.config.db_path).await;
    let settings = load_settings(&state.config.settings_path);
    let mem = read_memory_info();
    let disk = read_disk_info();
    let load = read_load_average();
    let (proc_ram, proc_cpu) = read_process_stats();
    let net = read_network_stats(&settings.network.interface);

    let info = ControlInfoResponse {
        device_code: settings.general.device_name,
        hostname: get_hostname(),
        firmware_version: env!("CARGO_PKG_VERSION").to_string(),
        model_version: state.config.model_version.clone(),
        local_ip: get_local_ip(),
        server_url: settings.server.url,
        is_paired: env::var("SMART_ABSENSI_TOKEN").map(|t| !t.trim().is_empty()).unwrap_or(false),
        cpu_temp_c: read_soc_temp(),
        free_ram_mb: mem.free_mb,
        free_disk_mb: disk.free_mb,
        uptime_sec: state.config.start_time.elapsed().as_secs(),
        template_count: templates,
        outbox_count: outbox,
        camera_device: settings.camera.source,
        camera_resolution: settings.camera.resolution,
        is_streaming: state.app_state.frame_hub.has_viewers(),
        serial_connected: Path::new("/dev/ttyACM0").exists() || Path::new("/dev/ttyUSB0").exists(),

        cpu_usage_pct: read_cpu_usage_pct(),
        cpu_cores: std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1),
        cpu_model: read_cpu_model(),
        ram_total_mb: mem.total_mb,
        ram_used_mb: mem.used_mb,
        ram_usage_pct: mem.usage_pct,
        disk_total_mb: disk.total_mb,
        disk_used_mb: disk.used_mb,
        disk_usage_pct: disk.usage_pct,
        load_avg_1: load.0,
        load_avg_5: load.1,
        load_avg_15: load.2,
        process_ram_mb: proc_ram,
        process_cpu_pct: proc_cpu,
        os_name: read_os_name(),
        kernel_version: read_kernel_version(),
        arch: std::env::consts::ARCH.to_string(),
        uptime_system_sec: read_system_uptime_sec(),
        outbox_bytes: read_outbox_bytes(&state.config.db_path),
        db_size_mb: read_db_size_mb(&state.config.db_path),
        last_sync_at: read_last_sync_at(&state.config.db_path).await,
        network_interface: net.interface,
        network_ip: net.ip,
        network_mac: net.mac,
        network_rx_bytes: net.rx_bytes,
        network_tx_bytes: net.tx_bytes,
        wifi_ssid: read_wifi_ssid(),
        wifi_signal_dbm: read_wifi_signal_dbm(),
        throttled: read_throttled_status(),
        power_volts: read_power_volts(),
    };

    Json(info).into_response()
}

pub async fn test_server_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    let settings = load_settings(&state.config.settings_path);
    let start = Instant::now();

    let client = reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(5))
        .build();

    let url = format!("{}/api/health", settings.server.url.trim_end_matches('/'));

    match client {
        Ok(c) => match c.get(&url).send().await {
            Ok(resp) => {
                let ms = start.elapsed().as_millis();
                Json(serde_json::json!({
                    "success": true,
                    "message": format!("Server reachable! HTTP {} in {}ms", resp.status().as_u16(), ms)
                })).into_response()
            }
            Err(e) => Json(serde_json::json!({
                "success": false,
                "message": format!("Server unreachable: {}", e)
            })).into_response(),
        },
        Err(e) => Json(serde_json::json!({
            "success": false,
            "message": format!("HTTP client error: {}", e)
        })).into_response(),
    }
}

pub async fn repair_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    warn!("Device repair triggered: clearing pairing credentials.");

    // Resolve .env next to the settings file rather than relying on the
    // process CWD, which differs depending on how the service was started.
    let settings_dir = state.config.settings_path.parent().unwrap_or(Path::new("."));
    let candidates = [
        settings_dir.join(".env"),
        settings_dir.join("../.env"),
        PathBuf::from(".env"),
    ];

    let mut cleared_path: Option<PathBuf> = None;
    for env_path in candidates {
        if !env_path.exists() {
            continue;
        }
        let Ok(content) = std::fs::read_to_string(&env_path) else { continue };
        let cleared = content
            .lines()
            .filter(|l| {
                !l.starts_with("SMART_ABSENSI_TOKEN=") && !l.starts_with("SMART_ABSENSI_DEVICE_ID=")
            })
            .collect::<Vec<_>>()
            .join("\n");

        match std::fs::write(&env_path, cleared) {
            Ok(()) => {
                cleared_path = Some(env_path);
                break;
            }
            Err(e) => warn!("Failed to write {}: {}", env_path.display(), e),
        }
    }

    match cleared_path {
        Some(p) => Json(serde_json::json!({
            "success": true,
            "message": format!("Device unpaired ({}). Re-pair with a new pairing code.", p.display())
        }))
        .into_response(),
        None => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({
                "success": false,
                "message": "Could not locate a writable .env file to clear pairing credentials."
            })),
        )
            .into_response(),
    }
}

pub async fn download_logs_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, "Unauthorized").into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, "CSRF origin mismatch").into_response();
    }

    // Read recent system or local log, redacting tokens and UUIDs
    let log_content = std::fs::read_to_string("engine.log")
        .unwrap_or_else(|_| "=== Smart Absensi Engine Log ===\nNo local log file found.".to_string());

    // Redaction: tokens & bearer headers
    let redacted = log_content
        .lines()
        .map(|line| {
            if line.contains("Bearer ") || line.contains("SMART_ABSENSI_TOKEN") || line.contains("password") {
                "[REDACTED LOG LINE]"
            } else {
                line
            }
        })
        .collect::<Vec<_>>()
        .join("\n");

    (
        [
            (header::CONTENT_TYPE, "text/plain; charset=utf-8"),
            (header::CONTENT_DISPOSITION, "attachment; filename=\"edge-engine.log\""),
        ],
        redacted,
    )
        .into_response()
}

pub async fn backup_config_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, "Unauthorized").into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, "CSRF origin mismatch").into_response();
    }

    let mut settings = load_settings(&state.config.settings_path);
    settings.network.wifi_password = None; // Never leak wifi password or secrets

    let json = serde_json::to_string_pretty(&settings).unwrap_or_default();
    (
        [
            (header::CONTENT_TYPE, "application/json"),
            (header::CONTENT_DISPOSITION, "attachment; filename=\"edge_settings_backup.json\""),
        ],
        json,
    )
        .into_response()
}

pub async fn restore_config_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(restored): Json<FullSettings>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    if let Err(e) = validate_settings(&restored) {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "success": false, "message": e }))).into_response();
    }

    if let Err(e) = save_settings_to_file(&state.config.settings_path, &restored) {
        warn!("Failed to restore settings: {}", e);
        return (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({ "success": false, "message": "Failed to persist restored settings" }))).into_response();
    }

    apply_time_settings(&restored.time);

    info!("Configuration restored successfully from backup.");
    Json(serde_json::json!({
        "success": true,
        "message": "Configuration restored successfully."
    })).into_response()
}

pub async fn check_update_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    let current = env!("CARGO_PKG_VERSION");
    let settings = load_settings(&state.config.settings_path);

    // The manifest lives on the central Laravel server so a fleet can be
    // updated by publishing one file, without reflashing each device.
    let manifest_url = format!(
        "{}/api/firmware/latest",
        settings.server.url.trim_end_matches('/')
    );

    let client = match reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(6))
        .build()
    {
        Ok(c) => c,
        Err(e) => {
            return Json(serde_json::json!({
                "success": false,
                "up_to_date": false,
                "current_version": current,
                "message": format!("HTTP client error: {}", e)
            }))
            .into_response();
        }
    };

    match client.get(&manifest_url).send().await {
        Ok(resp) if resp.status().is_success() => {
            #[derive(Deserialize)]
            struct Manifest {
                version: String,
                #[serde(default)]
                download_url: Option<String>,
                #[serde(default)]
                notes: Option<String>,
            }

            match resp.json::<Manifest>().await {
                Ok(m) => {
                    let available = is_newer_version(&m.version, current);
                    Json(serde_json::json!({
                        "success": true,
                        "up_to_date": !available,
                        "current_version": current,
                        "latest_version": m.version,
                        "download_url": m.download_url,
                        "notes": m.notes,
                        "message": if available {
                            format!("Firmware v{} is available (running v{}).", m.version, current)
                        } else {
                            format!("Engine is running current firmware v{}.", current)
                        }
                    }))
                    .into_response()
                }
                Err(e) => Json(serde_json::json!({
                    "success": false,
                    "up_to_date": false,
                    "current_version": current,
                    "message": format!("Malformed update manifest: {}", e)
                }))
                .into_response(),
            }
        }
        Ok(resp) => Json(serde_json::json!({
            "success": false,
            "up_to_date": false,
            "current_version": current,
            "message": format!("Update server returned HTTP {}", resp.status().as_u16())
        }))
        .into_response(),
        Err(e) => Json(serde_json::json!({
            "success": false,
            "up_to_date": false,
            "current_version": current,
            "message": format!("Cannot reach update server: {}", e)
        }))
        .into_response(),
    }
}

/// Unauthenticated liveness probe. Deliberately exposes nothing sensitive:
/// no server URL, no device code, no counts. Lets the Laravel dashboard poll
/// per-device status without storing edge credentials.
pub async fn health_handler(State(state): State<ControlState>) -> Response {
    Json(serde_json::json!({
        "ok": true,
        "service": "smart-absensi-edge",
        "version": env!("CARGO_PKG_VERSION"),
        "uptime_sec": state.config.start_time.elapsed().as_secs(),
        "initialized": state.auth.is_initialized(),
    }))
    .into_response()
}

/// Surfaces the outbox backlog so an operator can see sync is stuck before
/// it becomes a support ticket.
pub async fn pending_attendance_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }

    let db_path = state.config.db_path.as_str();
    if !Path::new(db_path).exists() {
        return Json(serde_json::json!({
            "pending": 0,
            "oldest_age_sec": null,
            "records": [],
            "message": "Local database not found."
        }))
        .into_response();
    }

    let Ok(db) = libsql::Builder::new_local(db_path).build().await else {
        return (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({ "error": "Cannot open local database" })),
        )
            .into_response();
    };
    let Ok(conn) = db.connect() else {
        return (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({ "error": "Cannot connect to local database" })),
        )
            .into_response();
    };

    let mut records: Vec<serde_json::Value> = Vec::new();
    let mut oldest: Option<String> = None;

    if let Ok(mut rows) = conn
        .query(
            "SELECT id, captured_at, synced FROM outbox_attendance
             WHERE synced = 0 ORDER BY captured_at ASC LIMIT 50",
            (),
        )
        .await
    {
        while let Ok(Some(row)) = rows.next().await {
            let id = row.get::<String>(0).unwrap_or_default();
            let captured_at = row.get::<String>(1).unwrap_or_default();
            if oldest.is_none() {
                oldest = Some(captured_at.clone());
            }
            records.push(serde_json::json!({
                "id": id,
                "captured_at": captured_at
            }));
        }
    }

    let pending = records.len() as i64;

    Json(serde_json::json!({
        "pending": pending,
        "oldest_captured_at": oldest,
        "records": records,
        "message": if pending == 0 {
            "Outbox is clear.".to_string()
        } else {
            format!("{} record(s) waiting to sync.", pending)
        }
    }))
    .into_response()
}

pub async fn action_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(payload): Json<ActionRequest>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    match payload.action.as_str() {
        "reboot" => {
            if payload.confirm_code.as_deref() != Some("REBOOT") {
                return (
                    StatusCode::BAD_REQUEST,
                    Json(ActionResponse {
                        success: false,
                        message: "Reboot confirmation mismatch. Expected 'REBOOT'.".to_string(),
                    }),
                )
                    .into_response();
            }
            // Verify the reboot binary is reachable before promising a reboot.
            if let Err(e) = run_verified("which", &["reboot"]) {
                warn!("Reboot unavailable: {}", e);
                return Json(ActionResponse {
                    success: false,
                    message: format!("Reboot is not available on this device: {}", e),
                })
                .into_response();
            }

            warn!("ADMIN ACTION: System reboot confirmed.");
            tokio::spawn(async {
                tokio::time::sleep(tokio::time::Duration::from_millis(1500)).await;
                let _ = std::process::Command::new("reboot").spawn();
            });
            Json(ActionResponse {
                success: true,
                message: "Device reboot initiated. Gateway will restart in 5 seconds.".to_string(),
            })
            .into_response()
        }
        "restart_engine" => {
            info!("ADMIN ACTION: Restarting edge inference engine.");
            match run_verified("systemctl", &["restart", "smart-absensi-engine"]) {
                Ok(_) => Json(ActionResponse {
                    success: true,
                    message: "Edge engine restarted successfully.".to_string(),
                })
                .into_response(),
                Err(e) => {
                    warn!("Engine restart failed: {}", e);
                    Json(ActionResponse {
                        success: false,
                        message: format!("Engine restart failed: {}", e),
                    })
                    .into_response()
                }
            }
        }
        "restart_sync" => {
            info!("ADMIN ACTION: Restarting smart-absensi-sync daemon.");
            match run_verified("systemctl", &["restart", "smart-absensi-sync"]) {
                Ok(_) => Json(ActionResponse {
                    success: true,
                    message: "Sync daemon restarted successfully.".to_string(),
                })
                .into_response(),
                Err(e) => {
                    warn!("Sync restart failed: {}", e);
                    Json(ActionResponse {
                        success: false,
                        message: format!("Sync daemon restart failed: {}", e),
                    })
                    .into_response()
                }
            }
        }
        "sync_rtc" => {
            info!("ADMIN ACTION: Synchronizing hardware RTC clock.");
            match run_verified("hwclock", &["-w"]) {
                Ok(_) => Json(ActionResponse {
                    success: true,
                    message: "Hardware RTC synchronized from system time.".to_string(),
                })
                .into_response(),
                Err(e) => {
                    warn!("RTC sync failed: {}", e);
                    Json(ActionResponse {
                        success: false,
                        message: format!("RTC sync failed: {}", e),
                    })
                    .into_response()
                }
            }
        }
        _ => (
            StatusCode::BAD_REQUEST,
            Json(ActionResponse {
                success: false,
                message: format!("Unknown action '{}'", payload.action),
            }),
        )
            .into_response(),
    }
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

fn validate_settings(settings: &FullSettings) -> Result<(), String> {
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
    if tz.is_empty() || tz.len() > 64 || !tz.chars().all(|c| c.is_ascii_alphanumeric() || c == '/' || c == '_' || c == '-' || c == '+') {
        return Err("Invalid timezone format (e.g. Asia/Jakarta)".to_string());
    }

    let url = settings.server.url.trim();
    if !url.starts_with("http://") && !url.starts_with("https://") {
        return Err("Server URL must begin with http:// or https://".to_string());
    }

    Ok(())
}

fn apply_time_settings(time: &TimeSettings) {
    let tz = time.timezone.trim();
    if !tz.is_empty() && tz.chars().all(|c| c.is_ascii_alphanumeric() || c == '/' || c == '_' || c == '-' || c == '+') {
        info!("Applying system timezone: {}", tz);
        let _ = std::process::Command::new("timedatectl")
            .args(["set-timezone", tz])
            .status();
    }

    let ntp_arg = if time.ntp_enabled { "true" } else { "false" };
    info!("Setting NTP synchronization: {}", ntp_arg);
    let _ = std::process::Command::new("timedatectl")
        .args(["set-ntp", ntp_arg])
        .status();
}

// ── Telemetry Helpers ────────────────────────────────────────────────────────

fn read_soc_temp() -> f64 {
    for p in &[
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/devices/virtual/thermal/thermal_zone0/temp",
    ] {
        if let Ok(c) = std::fs::read_to_string(p) {
            if let Ok(milli) = c.trim().parse::<f64>() {
                return (milli / 1000.0 * 10.0).round() / 10.0;
            }
        }
    }
    0.0
}

/// Runs a command and reports whether it actually succeeded.
/// Replaces the previous fire-and-forget `spawn()` calls that always
/// reported success even when the binary was missing or exited non-zero.
fn run_verified(program: &str, args: &[&str]) -> Result<String, String> {
    match std::process::Command::new(program).args(args).output() {
        Ok(out) if out.status.success() => {
            let stdout = String::from_utf8_lossy(&out.stdout).trim().to_string();
            Ok(stdout)
        }
        Ok(out) => {
            let stderr = String::from_utf8_lossy(&out.stderr).trim().to_string();
            Err(format!(
                "{} exited with status {}{}",
                program,
                out.status.code().unwrap_or(-1),
                if stderr.is_empty() { String::new() } else { format!(": {}", stderr) }
            ))
        }
        Err(e) => Err(format!("Cannot execute {}: {}", program, e)),
    }
}

/// Compares two dotted version strings. Returns true when `candidate` is
/// strictly newer than `current`. Non-numeric segments compare as strings.
fn is_newer_version(candidate: &str, current: &str) -> bool {
    let parse = |v: &str| -> Vec<String> {
        v.trim()
            .trim_start_matches('v')
            .split('.')
            .map(|s| s.to_string())
            .collect()
    };
    let a = parse(candidate);
    let b = parse(current);
    let len = a.len().max(b.len());
    for i in 0..len {
        let x = a.get(i).cloned().unwrap_or_else(|| "0".to_string());
        let y = b.get(i).cloned().unwrap_or_else(|| "0".to_string());
        match (x.parse::<u64>(), y.parse::<u64>()) {
            (Ok(nx), Ok(ny)) => {
                if nx != ny {
                    return nx > ny;
                }
            }
            _ => {
                if x != y {
                    return x > y;
                }
            }
        }
    }
    false
}

// ── Extended Telemetry Helpers ───────────────────────────────────────────────

pub struct MemoryInfo {
    pub total_mb: i64,
    pub free_mb: i64,
    pub used_mb: i64,
    pub usage_pct: f64,
}

pub struct DiskInfo {
    pub total_mb: i64,
    pub free_mb: i64,
    pub used_mb: i64,
    pub usage_pct: f64,
}

pub struct NetworkStats {
    pub interface: String,
    pub ip: String,
    pub mac: String,
    pub rx_bytes: u64,
    pub tx_bytes: u64,
}

fn read_memory_info() -> MemoryInfo {
    let mut total_kb = 0i64;
    let mut avail_kb = 0i64;

    if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
        for line in c.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() < 2 {
                continue;
            }
            let val = parts[1].parse::<i64>().unwrap_or(0);
            match parts[0] {
                "MemTotal:" => total_kb = val,
                "MemAvailable:" => avail_kb = val,
                _ => {}
            }
        }
    }

    if avail_kb == 0 {
        // Fallback for kernels without MemAvailable
        if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
            for line in c.lines() {
                if line.starts_with("MemFree:") {
                    let parts: Vec<&str> = line.split_whitespace().collect();
                    if parts.len() >= 2 {
                        avail_kb = parts[1].parse::<i64>().unwrap_or(0);
                    }
                    break;
                }
            }
        }
    }

    let total_mb = total_kb / 1024;
    let free_mb = avail_kb / 1024;
    let used_mb = (total_mb - free_mb).max(0);
    let usage_pct = if total_mb > 0 {
        (used_mb as f64 / total_mb as f64 * 100.0 * 10.0).round() / 10.0
    } else {
        0.0
    };

    MemoryInfo { total_mb, free_mb, used_mb, usage_pct }
}

fn read_disk_info() -> DiskInfo {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;
        let mount = if Path::new("/data").exists() { "/data" } else { "/" };
        let path = CString::new(mount).unwrap_or_else(|_| CString::new("/").unwrap());
        let mut stat: MaybeUninit<libc::statvfs> = MaybeUninit::uninit();
        // SAFETY: statvfs writes to valid path pointer and uninit statvfs buffer.
        let ok = unsafe { libc::statvfs(path.as_ptr(), stat.as_mut_ptr()) == 0 };
        if ok {
            // SAFETY: statvfs returned 0 (success), meaning stat is fully populated.
            let s = unsafe { stat.assume_init() };
            let frsize = s.f_frsize as u64;
            let total_mb = ((s.f_blocks as u64) * frsize / (1024 * 1024)) as i64;
            let free_mb = ((s.f_bavail as u64) * frsize / (1024 * 1024)) as i64;
            let used_mb = (total_mb - free_mb).max(0);
            let usage_pct = if total_mb > 0 {
                (used_mb as f64 / total_mb as f64 * 100.0 * 10.0).round() / 10.0
            } else {
                0.0
            };
            return DiskInfo { total_mb, free_mb, used_mb, usage_pct };
        }
    }
    DiskInfo { total_mb: 0, free_mb: 0, used_mb: 0, usage_pct: 0.0 }
}

fn read_load_average() -> (f64, f64, f64) {
    if let Ok(c) = std::fs::read_to_string("/proc/loadavg") {
        let parts: Vec<&str> = c.split_whitespace().collect();
        if parts.len() >= 3 {
            return (
                parts[0].parse().unwrap_or(0.0),
                parts[1].parse().unwrap_or(0.0),
                parts[2].parse().unwrap_or(0.0),
            );
        }
    }
    (0.0, 0.0, 0.0)
}

/// CPU usage sampled over 200ms from /proc/stat deltas.
fn read_cpu_usage_pct() -> f64 {
    fn sample() -> Option<(u64, u64)> {
        let c = std::fs::read_to_string("/proc/stat").ok()?;
        let line = c.lines().next()?;
        let parts: Vec<&str> = line.split_whitespace().collect();
        if parts.len() < 5 {
            return None;
        }
        let vals: Vec<u64> = parts[1..].iter().filter_map(|v| v.parse::<u64>().ok()).collect();
        if vals.len() < 4 {
            return None;
        }
        let idle = vals[3] + vals.get(4).copied().unwrap_or(0);
        let total: u64 = vals.iter().sum();
        Some((idle, total))
    }

    let Some((idle1, total1)) = sample() else { return 0.0 };
    std::thread::sleep(std::time::Duration::from_millis(200));
    let Some((idle2, total2)) = sample() else { return 0.0 };

    let total_delta = total2.saturating_sub(total1);
    if total_delta == 0 {
        return 0.0;
    }
    let idle_delta = idle2.saturating_sub(idle1);
    let busy = total_delta.saturating_sub(idle_delta);
    (busy as f64 / total_delta as f64 * 100.0 * 10.0).round() / 10.0
}

fn read_cpu_model() -> String {
    if let Ok(c) = std::fs::read_to_string("/proc/cpuinfo") {
        for line in c.lines() {
            if line.starts_with("model name") || line.starts_with("Model") || line.starts_with("Hardware") {
                if let Some((_, v)) = line.split_once(':') {
                    let v = v.trim();
                    if !v.is_empty() {
                        return v.to_string();
                    }
                }
            }
        }
    }
    "Unknown CPU".to_string()
}

/// (RSS in MB, CPU percent) for the current process.
fn read_process_stats() -> (i64, f64) {
    let pid = std::process::id();
    let mut rss_mb = 0i64;
    let mut cpu_pct = 0.0;

    if let Ok(c) = std::fs::read_to_string(format!("/proc/{}/status", pid)) {
        for line in c.lines() {
            if line.starts_with("VmRSS:") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 2 {
                    let kb = parts[1].parse::<i64>().unwrap_or(0);
                    rss_mb = kb / 1024;
                }
            }
        }
    }

    if let Ok(c) = std::fs::read_to_string(format!("/proc/{}/stat", pid)) {
        // Fields after the comm field (which may contain spaces, so split on last ')')
        if let Some(idx) = c.rfind(')') {
            let rest: Vec<&str> = c[idx + 1..].split_whitespace().collect();
            // utime=14, stime=15 (1-indexed incl. pid+comm) => rest[11], rest[12]
            if rest.len() > 12 {
                let utime = rest[11].parse::<f64>().unwrap_or(0.0);
                let stime = rest[12].parse::<f64>().unwrap_or(0.0);
                let ticks = utime + stime;
                let hz = 100.0_f64; // USER_HZ is 100 on Linux
                let elapsed = read_system_uptime_sec().max(1) as f64;
                cpu_pct = (ticks / hz / elapsed * 100.0 * 10.0).round() / 10.0;
            }
        }
    }

    (rss_mb, cpu_pct)
}

fn read_network_stats(preferred: &str) -> NetworkStats {
    let mut iface = preferred.trim().to_string();
    if iface.is_empty() || !Path::new(&format!("/sys/class/net/{}", iface)).exists() {
        // Pick the first non-loopback interface with an IPv4 address
        if let Ok(entries) = std::fs::read_dir("/sys/class/net") {
            for e in entries.flatten() {
                let name = e.file_name().to_string_lossy().to_string();
                if name != "lo" {
                    iface = name;
                    break;
                }
            }
        }
    }

    let mut rx_bytes = 0u64;
    let mut tx_bytes = 0u64;
    if let Ok(c) = std::fs::read_to_string("/proc/net/dev") {
        for line in c.lines().skip(2) {
            if let Some((name, rest)) = line.split_once(':') {
                if name.trim() == iface {
                    let fields: Vec<&str> = rest.split_whitespace().collect();
                    if fields.len() >= 9 {
                        rx_bytes = fields[0].parse().unwrap_or(0);
                        tx_bytes = fields[8].parse().unwrap_or(0);
                    }
                    break;
                }
            }
        }
    }

    let mac = std::fs::read_to_string(format!("/sys/class/net/{}/address", iface))
        .map(|s| s.trim().to_string())
        .unwrap_or_else(|_| "—".to_string());

    let ip = read_interface_ip(&iface);

    NetworkStats { interface: iface, ip, mac, rx_bytes, tx_bytes }
}

#[cfg_attr(not(unix), allow(unused_variables))]
fn read_interface_ip(iface: &str) -> String {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        let Ok(name) = CString::new(iface) else { return "—".to_string() };
        let bytes = name.as_bytes_with_nul();
        if bytes.len() > 16 {
            return "—".to_string();
        }

        // SAFETY: Invokes socket(AF_INET, SOCK_DGRAM) and ioctl(SIOCGIFADDR) to query interface IP.
        // File descriptor is closed before returning.
        unsafe {
            let mut ifr: libc::ifreq = std::mem::zeroed();
            for (i, b) in bytes.iter().enumerate() {
                ifr.ifr_name[i] = *b as libc::c_char;
            }
            let sock = libc::socket(libc::AF_INET, libc::SOCK_DGRAM, 0);
            if sock < 0 {
                return "—".to_string();
            }
            let rc = libc::ioctl(sock, libc::SIOCGIFADDR, &mut ifr);
            libc::close(sock);
            if rc == 0 {
                let addr = ifr.ifr_ifru.ifru_addr;
                let octets = addr.sin_addr.s_addr.to_ne_bytes();
                return format!("{}.{}.{}.{}", octets[0], octets[1], octets[2], octets[3]);
            }
        }
    }
    "—".to_string()
}

fn read_os_name() -> String {
    if let Ok(c) = std::fs::read_to_string("/etc/os-release") {
        for line in c.lines() {
            if let Some(v) = line.strip_prefix("PRETTY_NAME=") {
                return v.trim_matches('"').to_string();
            }
        }
    }
    std::env::consts::OS.to_string()
}

fn read_kernel_version() -> String {
    std::fs::read_to_string("/proc/sys/kernel/osrelease")
        .map(|s| s.trim().to_string())
        .unwrap_or_else(|_| String::new())
}

fn read_system_uptime_sec() -> u64 {
    std::fs::read_to_string("/proc/uptime")
        .ok()
        .and_then(|c| c.split_whitespace().next().and_then(|v| v.parse::<f64>().ok()))
        .map(|v| v as u64)
        .unwrap_or(0)
}

fn read_wifi_ssid() -> Option<String> {
    let out = std::process::Command::new("iwgetid").arg("-r").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout).trim().to_string();
    if s.is_empty() { None } else { Some(s) }
}

fn read_wifi_signal_dbm() -> Option<i32> {
    let c = std::fs::read_to_string("/proc/net/wireless").ok()?;
    for line in c.lines().skip(2) {
        if let Some((_, rest)) = line.split_once(':') {
            let fields: Vec<&str> = rest.split_whitespace().collect();
            if fields.len() >= 3 {
                let raw = fields[2].trim_end_matches('.').parse::<f64>().ok()?;
                return Some(raw.round() as i32);
            }
        }
    }
    None
}

/// Reads the Pi firmware throttle flags. Returns None when not a Raspberry Pi.
fn read_throttled_status() -> Option<String> {
    let out = std::process::Command::new("vcgencmd").arg("get_throttled").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout);
    let hex = s.trim().strip_prefix("throttled=")?;
    let flags = u32::from_str_radix(hex.trim_start_matches("0x"), 16).ok()?;

    if flags == 0 {
        return Some("OK".to_string());
    }

    let mut issues = Vec::new();
    if flags & 0x1 != 0 { issues.push("under-voltage"); }
    if flags & 0x2 != 0 { issues.push("arm-frequency-capped"); }
    if flags & 0x4 != 0 { issues.push("throttled"); }
    if flags & 0x8 != 0 { issues.push("soft-temp-limit"); }
    if flags & 0x10000 != 0 { issues.push("under-voltage-occurred"); }
    if flags & 0x20000 != 0 { issues.push("arm-frequency-capped-occurred"); }
    if flags & 0x40000 != 0 { issues.push("throttled-occurred"); }
    if flags & 0x80000 != 0 { issues.push("soft-temp-limit-occurred"); }

    Some(issues.join(", "))
}

fn read_power_volts() -> Option<f64> {
    let out = std::process::Command::new("vcgencmd").arg("measure_volts").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout);
    let v = s.trim().strip_prefix("volt=")?.trim_end_matches('V');
    v.parse::<f64>().ok()
}

fn read_outbox_bytes(db_path: &str) -> i64 {
    let dir = Path::new(db_path).parent().unwrap_or(Path::new("."));
    let mut total = 0i64;
    for suffix in ["", "-wal", "-shm"] {
        let p = dir.join(format!(
            "{}{}",
            Path::new(db_path).file_name().map(|f| f.to_string_lossy().to_string()).unwrap_or_default(),
            suffix
        ));
        if let Ok(m) = std::fs::metadata(&p) {
            total += m.len() as i64;
        }
    }
    total
}

fn read_db_size_mb(db_path: &str) -> f64 {
    std::fs::metadata(db_path)
        .map(|m| (m.len() as f64 / (1024.0 * 1024.0) * 10.0).round() / 10.0)
        .unwrap_or(0.0)
}

async fn read_last_sync_at(db_path: &str) -> Option<String> {
    if !Path::new(db_path).exists() {
        return None;
    }
    let db = libsql::Builder::new_local(db_path).build().await.ok()?;
    let conn = db.connect().ok()?;
    let mut rows = conn
        .query(
            "SELECT MAX(synced_at) FROM outbox_attendance WHERE synced = 1",
            (),
        )
        .await
        .ok()?;
    if let Ok(Some(row)) = rows.next().await {
        if let Ok(Some(v)) = row.get::<Option<String>>(0) {
            return Some(v);
        }
    }
    None
}

fn get_local_ip() -> String {
    if let Ok(sock) = std::net::UdpSocket::bind("0.0.0.0:0") {
        if sock.connect("1.1.1.1:80").is_ok() {
            if let Ok(addr) = sock.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && ip != "127.0.0.1" {
                    return ip;
                }
            }
        }
    }
    "127.0.0.1".to_string()
}

fn get_hostname() -> String {
    if let Ok(c) = std::fs::read_to_string("/etc/hostname") {
        let trimmed = c.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }
    "smart-absensi-gate".to_string()
}

async fn query_db_counts(db_path: &str) -> (i64, i64) {
    if !Path::new(db_path).exists() {
        return (0, 0);
    }

    if let Ok(db) = libsql::Builder::new_local(db_path).build().await {
        if let Ok(conn) = db.connect() {
            let mut templates_cnt = 0i64;
            let mut outbox_cnt = 0i64;

            if let Ok(mut rows) = conn.query("SELECT COUNT(*) FROM templates", ()).await {
                if let Ok(Some(row)) = rows.next().await {
                    if let Ok(cnt) = row.get::<i64>(0) {
                        templates_cnt = cnt;
                    }
                }
            }

            if let Ok(mut rows) = conn.query("SELECT COUNT(*) FROM outbox_attendance WHERE synced = 0", ()).await {
                if let Ok(Some(row)) = rows.next().await {
                    if let Ok(cnt) = row.get::<i64>(0) {
                        outbox_cnt = cnt;
                    }
                }
            }

            return (templates_cnt, outbox_cnt);
        }
    }
    (0, 0)
}

// ── Router Registration ──────────────────────────────────────────────────────

pub fn register_control_routes(router: Router<ControlState>) -> Router<ControlState> {
    router
        .route("/", get(ui_page_handler))
        .route("/control", get(ui_page_handler))
        .route("/api/control/setup", post(setup_handler))
        .route("/api/control/auth", post(login_handler))
        .route("/api/control/logout", post(logout_handler))
        .route("/api/control/info", get(info_handler))
        .route("/api/control/settings", get(get_settings_handler).post(save_settings_handler))
        .route("/api/control/camera/devices", get(get_camera_devices_handler))
        .route("/api/control/network/apply", post(apply_network_handler))
        .route("/api/control/network/confirm", post(confirm_network_handler))
        .route("/api/control/network/cancel", post(cancel_network_handler))
        .route("/api/control/network/rollback-status", get(network_rollback_status_handler))
        .route("/api/control/security/password", post(change_password_handler))
        .route("/api/control/server/test", post(test_server_handler))
        .route("/api/control/server/repair", post(repair_handler))
        .route("/api/control/system/logs", get(download_logs_handler))
        .route("/api/control/system/backup", get(backup_config_handler))
        .route("/api/control/system/restore", post(restore_config_handler))
        .route("/api/control/system/check-update", post(check_update_handler))
        .route("/api/control/attendance/pending", get(pending_attendance_handler))
        .route("/api/control/health", get(health_handler))
        .route("/api/control/action", post(action_handler))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_validate_settings_success() {
        let s = FullSettings::default();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_settings_rejects_unknown_language() {
        let mut s = FullSettings::default();
        s.general.language = "klingon".to_string();
        assert!(validate_settings(&s).is_err());
    }

    #[test]
    fn test_validate_settings_accepts_auto_language() {
        let mut s = FullSettings::default();
        s.general.language = "auto".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_is_newer_version_semver() {
        assert!(is_newer_version("2.5.0", "2.4.0"));
        assert!(is_newer_version("2.4.1", "2.4.0"));
        assert!(is_newer_version("3.0.0", "2.9.9"));
        assert!(!is_newer_version("2.4.0", "2.4.0"));
        assert!(!is_newer_version("2.3.9", "2.4.0"));
    }

    #[test]
    fn test_is_newer_version_handles_v_prefix_and_short_forms() {
        assert!(is_newer_version("v2.5", "2.4.9"));
        assert!(!is_newer_version("v2.4", "2.4.0"));
        assert!(is_newer_version("2.4.1", "2.4"));
    }

    #[test]
    fn test_run_verified_reports_failure_for_missing_binary() {
        // A binary that cannot exist on any platform.
        let result = run_verified("smart-absensi-nonexistent-binary-xyz", &[]);
        assert!(result.is_err());
    }

    #[test]
    fn test_validate_settings_fps_and_resolution() {
        let mut s = FullSettings::default();
        s.camera.fps_cap = 2; // Below 5
        assert!(validate_settings(&s).is_err());

        s.camera.fps_cap = 15;
        s.camera.resolution = "invalid_res".to_string();
        assert!(validate_settings(&s).is_err());

        s.camera.resolution = "1280x720".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_static_ip_mode() {
        let mut s = FullSettings::default();
        s.network.mode = "static".to_string();
        s.network.static_ip = "not_an_ip".to_string();
        assert!(validate_settings(&s).is_err());

        s.network.static_ip = "192.168.1.100".to_string();
        s.network.subnet = "255.255.255.0".to_string();
        s.network.gateway = "192.168.1.1".to_string();
        s.network.dns = "1.1.1.1".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_timezone_and_server() {
        let mut s = FullSettings::default();
        s.time.timezone = "Asia/Jakarta; rm -rf /".to_string(); // Injected chars
        assert!(validate_settings(&s).is_err());

        s.time.timezone = "Asia/Jakarta".to_string();
        s.server.url = "ftp://invalid-scheme".to_string();
        assert!(validate_settings(&s).is_err());

        s.server.url = "http://192.168.1.50:8000".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_preserve_wifi_password_on_save() {
        let temp_dir = std::env::temp_dir().join(format!("edge_test_{}", uuid::Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);
        let settings_path = temp_dir.join("edge_settings.json");

        // 1. Initial save with wifi password
        let mut initial = FullSettings::default();
        initial.network.wifi_password = Some("SuperSecretWifiPass123!".to_string());
        assert!(save_settings_to_file(&settings_path, &initial).is_ok());

        // 2. Read back: wifi_password exists in file
        let loaded1 = load_settings(&settings_path);
        assert_eq!(loaded1.network.wifi_password.as_deref(), Some("SuperSecretWifiPass123!"));

        // 3. New update without wifi_password (None)
        let mut updated = loaded1.clone();
        updated.network.wifi_password = None;
        updated.general.device_name = "Renamed Gate".to_string();
        assert!(save_settings_to_file(&settings_path, &updated).is_ok());

        // 4. Verify wifi password was NOT erased
        let loaded2 = load_settings(&settings_path);
        assert_eq!(loaded2.general.device_name, "Renamed Gate");
        assert_eq!(loaded2.network.wifi_password.as_deref(), Some("SuperSecretWifiPass123!"));

        let _ = std::fs::remove_dir_all(&temp_dir);
    }
}

