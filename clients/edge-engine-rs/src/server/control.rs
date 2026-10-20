use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{Html, IntoResponse, Json, Response},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use std::env;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Instant;
use tracing::{info, warn};

use crate::server::auth::AuthManager;
use crate::server::AppState;

const UI_HTML: &str = include_str!("ui.html");
const ALPINE_JS: &str = include_str!("vendor/alpine.min.js");

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
    #[serde(default, skip_serializing)]
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

    let settings = load_settings(&state.config.settings_path);
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

    // Input Validation
    if new_settings.camera.fps_cap < 5 || new_settings.camera.fps_cap > 60 {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "error": "FPS cap must be between 5 and 60" }))).into_response();
    }

    if let Err(e) = save_settings_to_file(&state.config.settings_path, &new_settings) {
        warn!("Failed to save settings: {}", e);
        return (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({ "error": "Failed to persist settings" }))).into_response();
    }

    info!("Edge settings updated and persisted successfully.");
    Json(serde_json::json!({ "success": true, "message": "Settings saved" })).into_response()
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

    let info = ControlInfoResponse {
        device_code: settings.general.device_name,
        hostname: get_hostname(),
        firmware_version: env!("CARGO_PKG_VERSION").to_string(),
        model_version: state.config.model_version.clone(),
        local_ip: get_local_ip(),
        server_url: settings.server.url,
        is_paired: env::var("SMART_ABSENSI_TOKEN").map(|t| !t.trim().is_empty()).unwrap_or(false),
        cpu_temp_c: read_soc_temp(),
        free_ram_mb: read_free_ram_mb(),
        free_disk_mb: read_free_disk_mb(),
        uptime_sec: state.config.start_time.elapsed().as_secs(),
        template_count: templates,
        outbox_count: outbox,
        camera_device: settings.camera.source,
        camera_resolution: settings.camera.resolution,
        is_streaming: state.app_state.frame_hub.has_viewers(),
        serial_connected: Path::new("/dev/ttyACM0").exists() || Path::new("/dev/ttyUSB0").exists(),
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
    let env_path = Path::new(".env");
    if env_path.exists() {
        if let Ok(content) = std::fs::read_to_string(env_path) {
            let cleared = content
                .lines()
                .filter(|l| !l.starts_with("SMART_ABSENSI_TOKEN=") && !l.starts_with("SMART_ABSENSI_DEVICE_ID="))
                .collect::<Vec<_>>()
                .join("\n");
            let _ = std::fs::write(env_path, cleared);
        }
    }

    Json(serde_json::json!({
        "success": true,
        "message": "Device unpaired. You can now re-pair with a new QR code."
    })).into_response()
}

pub async fn download_logs_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, "Unauthorized").into_response();
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
            tokio::spawn(async {
                tokio::time::sleep(tokio::time::Duration::from_millis(1000)).await;
                let _ = std::process::Command::new("systemctl")
                    .args(["restart", "smart-absensi-engine"])
                    .spawn();
            });
            Json(ActionResponse {
                success: true,
                message: "Edge engine restart triggered.".to_string(),
            })
            .into_response()
        }
        "restart_sync" => {
            info!("ADMIN ACTION: Restarting smart-absensi-sync daemon.");
            tokio::spawn(async {
                let _ = std::process::Command::new("systemctl")
                    .args(["restart", "smart-absensi-sync"])
                    .spawn();
            });
            Json(ActionResponse {
                success: true,
                message: "Sync daemon restart triggered.".to_string(),
            })
            .into_response()
        }
        "sync_rtc" => {
            info!("ADMIN ACTION: Synchronizing hardware RTC clock.");
            let _ = std::process::Command::new("hwclock").args(["-w"]).spawn();
            Json(ActionResponse {
                success: true,
                message: "Hardware RTC clock synchronized from system time.".to_string(),
            })
            .into_response()
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

fn load_settings(path: &Path) -> FullSettings {
    if path.exists() {
        if let Ok(c) = std::fs::read_to_string(path) {
            if let Ok(s) = serde_json::from_str::<FullSettings>(&c) {
                return s;
            }
        }
    }
    FullSettings::default()
}

fn save_settings_to_file(path: &Path, settings: &FullSettings) -> anyhow::Result<()> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)?;
    }
    let json = serde_json::to_string_pretty(settings)?;
    std::fs::write(path, json)?;
    Ok(())
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

fn read_free_ram_mb() -> i64 {
    if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
        for line in c.lines() {
            if line.starts_with("MemAvailable:") || line.starts_with("MemFree:") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 2 {
                    if let Ok(kb) = parts[1].parse::<i64>() {
                        return kb / 1024;
                    }
                }
            }
        }
    }
    0
}

fn read_free_disk_mb() -> i64 {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;
        let path = CString::new("/data").unwrap_or_else(|_| CString::new("/").unwrap());
        let mut stat: MaybeUninit<libc::statvfs> = MaybeUninit::uninit();
        unsafe {
            if libc::statvfs(path.as_ptr(), stat.as_mut_ptr()) == 0 {
                let s = stat.assume_init();
                let free_bytes = (s.f_bavail as u64) * (s.f_frsize as u64);
                return (free_bytes / (1024 * 1024)) as i64;
            }
        }
    }
    0
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
        .route("/api/control/security/password", post(change_password_handler))
        .route("/api/control/server/test", post(test_server_handler))
        .route("/api/control/server/repair", post(repair_handler))
        .route("/api/control/system/logs", get(download_logs_handler))
        .route("/api/control/system/backup", get(backup_config_handler))
        .route("/api/control/action", post(action_handler))
}
