use axum::{
    extract::{ConnectInfo, State},
    http::{header, HeaderMap, StatusCode},
    response::{Html, IntoResponse, Json, Response},
};
use std::env;
use std::net::{Ipv4Addr, SocketAddr};
use std::path::{Path, PathBuf};
use std::time::Instant;
use tracing::{info, warn};

use super::models::*;
use super::system::*;

const UI_HTML: &str = include_str!("../ui.html");
const ALPINE_JS: &str = include_str!("../vendor/alpine.min.js");

// ── Handler Halaman HTML ─────────────────────────────────────────────────────

pub async fn ui_page_handler() -> Html<String> {
    let rendered = UI_HTML.replace("__ALPINE_VENDORED_BUNDLE__", ALPINE_JS);
    Html(rendered)
}

// ── Handler Autentikasi ──────────────────────────────────────────────────────

pub async fn setup_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    Json(payload): Json<SetupRequest>,
) -> impl IntoResponse {
    let ip = peer_addr.ip().to_string();
    match state.auth.setup_first_password(&ip, &payload.code, &payload.password) {
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
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    Json(payload): Json<LoginRequest>,
) -> Response {
    let ip = peer_addr.ip().to_string();

    match state.auth.verify_login(&ip, &payload.password) {
        Ok(token) => {
            // Cookie HttpOnly, SameSite=Strict dengan TTL 1 jam, tanpa flag Secure (perangkat melayani HTTP biasa)
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

// ── Handler Pengaturan ───────────────────────────────────────────────────────

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

    #[cfg(target_os = "linux")]
    {
        for i in 0..10 {
            let path = format!("/dev/video{}", i);
            if Path::new(&path).exists() {
                devices.push(path);
            }
        }
    }

    #[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
    {
        let native_devs = crate::vision::camera_win::query_native_camera_devices();
        if !native_devs.is_empty() {
            devices = native_devs;
        } else {
            devices.push("0: Default Native Camera".to_string());
        }
    }

    #[cfg(all(not(target_os = "linux"), not(feature = "native-camera")))]
    {
        if let Some(py_cmd) = crate::vision::resolve_python_command() {
            let backend_expr = crate::vision::cv2_backend_expr();
            let py_probe_script = format!(
                "import cv2, sys\n\
if sys.version_info < (3, 8): sys.exit(2)\n\
backend = {backend}\n\
devs = []\n\
for i in range(4):\n\
    cap = cv2.VideoCapture(i, backend)\n\
    if not cap.isOpened(): cap = cv2.VideoCapture(i)\n\
    if cap.isOpened():\n\
        devs.append(str(i))\n\
        cap.release()\n\
print(','.join(devs))",
                backend = backend_expr
            );

            let output = std::process::Command::new(&py_cmd)
                .args(["-c", &py_probe_script])
                .output();

            if let Ok(out) = output {
                let s = String::from_utf8_lossy(&out.stdout).trim().to_string();
                if !s.is_empty() {
                    for part in s.split(',') {
                        let trimmed = part.trim();
                        if !trimmed.is_empty() {
                            devices.push(trimmed.to_string());
                        }
                    }
                }
            }
        } else {
            tracing::warn!("Python >= 3.8 tidak ditemukan saat memindai perangkat kamera.");
        }
        if devices.is_empty() {
            devices.push("0".to_string());
        }
    }

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

    let helper_path = "/usr/local/bin/smart-absensi-syshelper";
    if !Path::new(helper_path).exists() {
        return (
            StatusCode::NOT_IMPLEMENTED,
            Json(serde_json::json!({
                "success": false,
                "error": format!("Network helper binary '{helper_path}' not found on this system. Cannot configure network interfaces.")
            })),
        ).into_response();
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

    let _ = std::process::Command::new(helper_path)
        .args(["apply-network", staged_path.to_str().unwrap_or("")])
        .spawn();

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
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
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

    // Verifikasi password saat ini pada bucket terisolasi (tidak membakar bucket login 127.0.0.1)
    let ip = format!("pwd_change:{}", peer_addr.ip());
    if state.auth.verify_login(&ip, &payload.current).is_err() {
        return (StatusCode::BAD_REQUEST, Json(serde_json::json!({ "success": false, "message": "Current password incorrect" }))).into_response();
    }

    if let Err(e) = state.auth.save_password(&payload.new_pwd) {
        return (StatusCode::INTERNAL_SERVER_ERROR, Json(serde_json::json!({ "success": false, "message": e }))).into_response();
    }

    Json(serde_json::json!({ "success": true, "message": "Password changed successfully" })).into_response()
}

// ── Telemetri & Aksi ─────────────────────────────────────────────────────────

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

    let (templates, outbox, quarantined) = query_db_counts(&state.config.db_path).await;
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
        quarantined_count: quarantined,
        camera_device: state.config.camera_device.clone(),
        camera_resolution: format!("{}x{}", state.config.width, state.config.height),
        camera_is_simulated: state.app_state.camera_simulated.load(std::sync::atomic::Ordering::Relaxed),
        is_streaming: state.app_state.frame_hub.has_viewers(),
        serial_connected: Path::new("/dev/ttyACM0").exists() || Path::new("/dev/ttyUSB0").exists(),
        camera_warning: state.app_state.camera_warning.read().ok().and_then(|g| g.clone()),

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

    // Cari .env di sebelah file pengaturan, bukan bergantung pada CWD proses
    // yang bisa berbeda tergantung cara service dijalankan.
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

    // Baca log sistem atau lokal terbaru, dengan token dan UUID disamarkan
    let log_content = std::fs::read_to_string("engine.log")
        .unwrap_or_else(|_| "=== Smart Absensi Engine Log ===\nNo local log file found.".to_string());

    // Penyamaran: token & header bearer
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

    // Manifest berada di server Laravel pusat sehingga seluruh armada dapat
    // diperbarui cukup dengan menerbitkan satu file, tanpa flash ulang tiap perangkat.
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
            #[derive(serde::Deserialize)]
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

/// Probe liveness tanpa autentikasi. Sengaja tidak mengekspos apa pun yang sensitif:
/// tanpa URL server, tanpa kode perangkat, tanpa hitungan. Memungkinkan dashboard Laravel
/// memantau status tiap perangkat tanpa menyimpan kredensial edge.
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

/// Menampilkan tumpukan outbox agar operator bisa melihat sinkronisasi macet
/// sebelum berubah menjadi tiket dukungan.
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
            // Pastikan binary reboot dapat dijangkau sebelum menjanjikan reboot.
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

pub async fn test_gpio_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Json(payload): Json<GpioTestRequest>,
) -> Response {
    if !state.auth.check_session(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "error": "Unauthorized" }))).into_response();
    }
    if !state.auth.verify_origin(&headers) {
        return (StatusCode::FORBIDDEN, Json(serde_json::json!({ "error": "CSRF origin mismatch" }))).into_response();
    }

    if payload.pin > 255 {
        return (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Pin number must be between 0 and 255" })),
        )
            .into_response();
    }

    let duration = std::time::Duration::from_millis(payload.duration_ms.clamp(100, 5000));

    #[cfg(target_os = "linux")]
    {
        match crate::hardware::pulse_test_pin(payload.pin, payload.active_high, duration) {
            Ok(()) => {
                info!("GPIO test pulse triggered for pin {}", payload.pin);
                (
                    StatusCode::OK,
                    Json(serde_json::json!({
                        "success": true,
                        "message": format!("Pulsed GPIO pin {} for {}ms", payload.pin, duration.as_millis()),
                        "pin": payload.pin
                    })),
                )
                    .into_response()
            }
            Err(e) => {
                warn!("GPIO test pulse failed on pin {}: {}", payload.pin, e);
                (
                    StatusCode::INTERNAL_SERVER_ERROR,
                    Json(serde_json::json!({
                        "success": false,
                        "error": format!("Failed to pulse pin {}: {}", payload.pin, e)
                    })),
                )
                    .into_response()
            }
        }
    }

    #[cfg(not(target_os = "linux"))]
    {
        let _ = (payload, duration);
        (
            StatusCode::NOT_IMPLEMENTED,
            Json(serde_json::json!({
                "success": false,
                "error": "GPIO hardware control is only available on Linux targets"
            })),
        )
            .into_response()
    }
}

