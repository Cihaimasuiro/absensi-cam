use axum::{
    extract::{Query, State},
    http::{HeaderMap, StatusCode},
    response::{Html, IntoResponse, Json, Response},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use std::collections::HashSet;
use std::env;
use std::path::Path;
use std::sync::{Arc, RwLock};
use std::time::Instant;
use tracing::{info, warn};
use uuid::Uuid;

use crate::server::AppState;

#[derive(Clone)]
pub struct ServerConfig {
    pub db_path: String,
    pub camera_device: String,
    pub width: u32,
    pub height: u32,
    pub model_version: String,
    pub start_time: Instant,
}

impl Default for ServerConfig {
    fn default() -> Self {
        Self {
            db_path: "/data/absensi.db".to_string(),
            camera_device: "/dev/video0".to_string(),
            width: 640,
            height: 480,
            model_version: "v1.0-yunet".to_string(),
            start_time: Instant::now(),
        }
    }
}

#[derive(Clone)]
pub struct ControlState {
    pub app_state: AppState,
    pub config: ServerConfig,
    pub sessions: Arc<RwLock<HashSet<String>>>,
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
pub struct AuthQuery {
    pub token: Option<String>,
}

// ── Authentication Helpers ───────────────────────────────────────────────────

fn get_admin_password() -> String {
    env::var("PANEL_ADMIN_PASSWORD")
        .or_else(|_| env::var("SMART_ABSENSI_DEVICE_ID"))
        .unwrap_or_else(|_| "admin123".to_string())
}

fn check_auth(headers: &HeaderMap, query: &AuthQuery, sessions: &Arc<RwLock<HashSet<String>>>) -> bool {
    let token = if let Some(auth_val) = headers.get("authorization").and_then(|v| v.to_str().ok()) {
        if let Some(stripped) = auth_val.strip_prefix("Bearer ") {
            stripped.trim().to_string()
        } else {
            auth_val.trim().to_string()
        }
    } else if let Some(ref q_tok) = query.token {
        q_tok.clone()
    } else {
        return false;
    };

    if let Ok(guard) = sessions.read() {
        guard.contains(&token)
    } else {
        false
    }
}

// ── Telemetry & System Helpers ───────────────────────────────────────────────

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

// ── Handlers ─────────────────────────────────────────────────────────────────

async fn login_handler(
    State(state): State<ControlState>,
    Json(payload): Json<LoginRequest>,
) -> impl IntoResponse {
    let expected = get_admin_password();
    if payload.password == expected {
        let token = Uuid::new_v4().to_string();
        if let Ok(mut guard) = state.sessions.write() {
            guard.insert(token.clone());
        }
        info!("Admin control session authenticated successfully.");
        Json(LoginResponse {
            success: true,
            token: Some(token),
            message: "Authentication successful".to_string(),
        })
    } else {
        warn!("Admin control session failed: invalid password attempt.");
        Json(LoginResponse {
            success: false,
            token: None,
            message: "Invalid password".to_string(),
        })
    }
}

async fn info_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Query(query): Query<AuthQuery>,
) -> Response {
    if !check_auth(&headers, &query, &state.sessions) {
        return (
            StatusCode::UNAUTHORIZED,
            Json(serde_json::json!({ "error": "Unauthorized. Please authenticate first." })),
        )
            .into_response();
    }

    let (templates, outbox) = query_db_counts(&state.config.db_path).await;
    let device_code = env::var("SMART_ABSENSI_DEVICE_ID").unwrap_or_else(|_| "GATE-UNPAIRED".to_string());
    let server_url = env::var("SMART_ABSENSI_URL").unwrap_or_else(|_| "Not configured".to_string());
    let is_paired = env::var("SMART_ABSENSI_TOKEN").map(|t| !t.trim().is_empty()).unwrap_or(false);

    let info = ControlInfoResponse {
        device_code,
        hostname: get_hostname(),
        firmware_version: env!("CARGO_PKG_VERSION").to_string(),
        model_version: state.config.model_version.clone(),
        local_ip: get_local_ip(),
        server_url,
        is_paired,
        cpu_temp_c: read_soc_temp(),
        free_ram_mb: read_free_ram_mb(),
        free_disk_mb: read_free_disk_mb(),
        uptime_sec: state.config.start_time.elapsed().as_secs(),
        template_count: templates,
        outbox_count: outbox,
        camera_device: state.config.camera_device.clone(),
        camera_resolution: format!("{}x{}", state.config.width, state.config.height),
        is_streaming: state.app_state.frame_hub.has_viewers(),
        serial_connected: Path::new("/dev/ttyACM0").exists() || Path::new("/dev/ttyUSB0").exists(),
    };

    Json(info).into_response()
}

async fn action_handler(
    State(state): State<ControlState>,
    headers: HeaderMap,
    Query(query): Query<AuthQuery>,
    Json(payload): Json<ActionRequest>,
) -> Response {
    if !check_auth(&headers, &query, &state.sessions) {
        return (
            StatusCode::UNAUTHORIZED,
            Json(ActionResponse {
                success: false,
                message: "Unauthorized".to_string(),
            }),
        )
            .into_response();
    }

    info!("Control action received: {}", payload.action);

    match payload.action.as_str() {
        "restart_engine" => {
            tokio::spawn(async {
                tokio::time::sleep(tokio::time::Duration::from_millis(600)).await;
                #[cfg(unix)]
                {
                    let _ = std::process::Command::new("sudo")
                        .args(["systemctl", "restart", "smart-absensi"])
                        .status();
                }
            });
            Json(ActionResponse {
                success: true,
                message: "Restarting Smart Absensi Engine in 1s... Page will reload.".to_string(),
            })
            .into_response()
        }
        "restart_sync" => {
            tokio::spawn(async {
                tokio::time::sleep(tokio::time::Duration::from_millis(300)).await;
                #[cfg(unix)]
                {
                    let _ = std::process::Command::new("sudo")
                        .args(["systemctl", "restart", "smart-absensi-sync"])
                        .status();
                }
            });
            Json(ActionResponse {
                success: true,
                message: "Sync daemon restart command dispatched.".to_string(),
            })
            .into_response()
        }
        "reboot" => {
            let confirm = payload.confirm_code.as_deref().unwrap_or("");
            let device_code = env::var("SMART_ABSENSI_DEVICE_ID").unwrap_or_else(|_| "REBOOT".to_string());
            if confirm != "REBOOT" && confirm != device_code {
                return (
                    StatusCode::BAD_REQUEST,
                    Json(ActionResponse {
                        success: false,
                        message: "Invalid confirmation code. Type 'REBOOT' to confirm board restart.".to_string(),
                    }),
                )
                    .into_response();
            }

            tokio::spawn(async {
                tokio::time::sleep(tokio::time::Duration::from_secs(1)).await;
                #[cfg(unix)]
                {
                    let _ = std::process::Command::new("sudo").arg("reboot").status();
                }
            });

            Json(ActionResponse {
                success: true,
                message: "Rebooting device. Please wait ~45 seconds before reconnecting.".to_string(),
            })
            .into_response()
        }
        "sync_now" => {
            let db_path = state.config.db_path.clone();
            tokio::spawn(async move {
                let _ = &db_path;
                #[cfg(unix)]
                {
                    let _ = std::process::Command::new("smart-absensi-sync")
                        .args(["sync", "--db", &db_path])
                        .status();
                }
            });
            Json(ActionResponse {
                success: true,
                message: "Manual sync cycle triggered.".to_string(),
            })
            .into_response()
        }
        _ => (
            StatusCode::BAD_REQUEST,
            Json(ActionResponse {
                success: false,
                message: format!("Unknown action: {}", payload.action),
            }),
        )
            .into_response(),
    }
}

// ── HTML UI ──────────────────────────────────────────────────────────────────

const CONTROL_HTML: &str = r#"<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Smart Absensi - Gate Edge Control</title>
<style>
  :root {
    --bg: #090d16;
    --surface: #111827;
    --border: #1f2937;
    --text: #f3f4f6;
    --muted: #9ca3af;
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  body { background: var(--bg); color: var(--text); font-size: 13px; line-height: 1.5; padding: 16px; }
  .container { max-width: 900px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
  .header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
  .header h1 { font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .badge { font-size: 11px; padding: 2px 8px; border-radius: 4px; background: #1e293b; color: #94a3b8; font-family: ui-monospace, monospace; }
  .badge.online { background: #064e3b; color: #6ee7b7; }
  .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
  .card-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600; }
  .stat-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.04); padding: 4px 0; }
  .stat-label { color: var(--muted); }
  .stat-val { font-family: ui-monospace, monospace; font-weight: 500; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.15s; background: var(--surface); color: var(--text); }
  .btn-primary { background: var(--primary); color: #fff; }
  .btn-primary:hover { background: var(--primary-hover); }
  .btn-secondary { border-color: var(--border); background: #1f2937; }
  .btn-secondary:hover { background: #374151; }
  .btn-danger { background: #7f1d1d; color: #fca5a5; border-color: #991b1b; }
  .btn-danger:hover { background: #991b1b; color: #fff; }
  .btn-group { display: flex; flex-wrap: wrap; gap: 8px; }
  .preview-box { width: 100%; aspect-ratio: 4/3; background: #000; border-radius: 6px; overflow: hidden; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); position: relative; }
  .preview-box img { width: 100%; height: 100%; object-fit: contain; }
  .auth-overlay { position: fixed; inset: 0; background: rgba(9,13,22,0.92); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 100; }
  .auth-box { width: 320px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 24px; display: flex; flex-direction: column; gap: 14px; }
  .input { width: 100%; padding: 8px 12px; background: #090d16; border: 1px solid var(--border); border-radius: 6px; color: #fff; font-size: 13px; outline: none; }
  .input:focus { border-color: var(--primary); }
  .toast { position: fixed; bottom: 20px; right: 20px; background: #1e293b; border: 1px solid #334155; padding: 10px 16px; border-radius: 6px; font-size: 12px; z-index: 200; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); display: none; }
</style>
</head>
<body>
<div id="authOverlay" class="auth-overlay">
  <div class="auth-box">
    <h2 style="font-size:14px;font-weight:600">Edge Device Login</h2>
    <p style="font-size:12px;color:var(--muted)">Enter gate panel administrator password</p>
    <input type="password" id="authPassword" class="input" placeholder="Admin Password" autofocus />
    <button id="loginBtn" class="btn btn-primary" style="width:100%">Authenticate</button>
    <div id="authErr" style="color:var(--danger);font-size:11px;display:none">Invalid password. Try again.</div>
  </div>
</div>

<div class="container">
  <div class="header">
    <div>
      <h1 id="deviceTitle">Gate Controller</h1>
      <span style="font-size:11px;color:var(--muted)" id="deviceSub">Loading system state...</span>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span id="ipBadge" class="badge">IP: ...</span>
      <span id="uptimeBadge" class="badge">Uptime: ...</span>
      <button class="btn btn-secondary" onclick="logout()" style="padding:4px 8px;font-size:11px">Logout</button>
    </div>
  </div>

  <div class="grid-3">
    <!-- Health & Hardware -->
    <div class="card">
      <div class="card-title">System & Hardware Health</div>
      <div class="stat-row"><span class="stat-label">CPU Temp</span><span id="cpuTemp" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Free RAM</span><span id="freeRam" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Free Disk (/data)</span><span id="freeDisk" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Firmware</span><span id="fwVer" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Model Vision</span><span id="modelVer" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Serial Link</span><span id="serialLink" class="stat-val">--</span></div>
    </div>

    <!-- Data & Cloud Sync -->
    <div class="card">
      <div class="card-title">Data & Cloud Connection</div>
      <div class="stat-row"><span class="stat-label">Server</span><span id="serverUrl" class="stat-val" style="max-width:140px;overflow:hidden;text-overflow:ellipsis">--</span></div>
      <div class="stat-row"><span class="stat-label">Pairing</span><span id="pairedStatus" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Active Faces</span><span id="templateCount" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Outbox Queue</span><span id="outboxCount" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Camera</span><span id="cameraInfo" class="stat-val">--</span></div>
      <div class="stat-row"><span class="stat-label">Live Stream</span><a href="/stream" target="_blank" class="stat-val" style="color:#60a5fa">Open Stream &nearr;</a></div>
    </div>

    <!-- Live Preview -->
    <div class="card">
      <div class="card-title" style="display:flex;justify-content:space-between">
        <span>Camera Snapshot</span>
        <span id="refreshNotice" style="color:#60a5fa;cursor:pointer" onclick="refreshSnapshot()">Refresh</span>
      </div>
      <div class="preview-box">
        <img id="snapshotImg" src="/snapshot.jpg" alt="Live Camera" onerror="this.style.display='none'" onload="this.style.display='block'" />
      </div>
    </div>
  </div>

  <!-- Actions -->
  <div class="card">
    <div class="card-title">Device Maintenance Actions</div>
    <div class="btn-group">
      <button class="btn btn-secondary" onclick="triggerAction('restart_engine', 'Restart Smart Absensi engine and camera capture?')">Restart Engine</button>
      <button class="btn btn-secondary" onclick="triggerAction('restart_sync', 'Restart cloud sync daemon?')">Restart Sync</button>
      <button class="btn btn-secondary" onclick="triggerAction('sync_now', 'Force immediate outbox synchronization?')">Sync Now</button>
      <button class="btn btn-danger" onclick="confirmReboot()">Reboot Device</button>
    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
let token = localStorage.getItem('gate_admin_token');

function showToast(msg) {
  const t = document.getElementById('toast');
  t.innerText = msg;
  t.style.display = 'block';
  setTimeout(() => { t.style.display = 'none'; }, 3500);
}

function checkLogin() {
  if (!token) {
    document.getElementById('authOverlay').style.display = 'flex';
  } else {
    document.getElementById('authOverlay').style.display = 'none';
    fetchInfo();
  }
}

document.getElementById('loginBtn').addEventListener('click', async () => {
  const pwd = document.getElementById('authPassword').value;
  try {
    const res = await fetch('/api/control/auth', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: pwd })
    });
    const data = await res.json();
    if (data.success && data.token) {
      token = data.token;
      localStorage.setItem('gate_admin_token', token);
      document.getElementById('authErr').style.display = 'none';
      checkLogin();
    } else {
      document.getElementById('authErr').style.display = 'block';
    }
  } catch (e) {
    document.getElementById('authErr').style.display = 'block';
  }
});

function logout() {
  localStorage.removeItem('gate_admin_token');
  token = null;
  checkLogin();
}

async function fetchInfo() {
  if (!token) return;
  try {
    const res = await fetch('/api/control/info', {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    if (res.status === 401) {
      logout();
      return;
    }
    const d = await res.json();
    document.getElementById('deviceTitle').innerText = d.device_code + ' (' + d.hostname + ')';
    document.getElementById('deviceSub').innerText = 'Model ' + d.model_version + ' | Engine v' + d.firmware_version;
    document.getElementById('ipBadge').innerText = 'IP: ' + d.local_ip;
    document.getElementById('uptimeBadge').innerText = 'Up ' + formatUptime(d.uptime_sec);

    document.getElementById('cpuTemp').innerText = d.cpu_temp_c + ' °C';
    document.getElementById('freeRam').innerText = d.free_ram_mb + ' MB';
    document.getElementById('freeDisk').innerText = d.free_disk_mb + ' MB';
    document.getElementById('fwVer').innerText = 'v' + d.firmware_version;
    document.getElementById('modelVer').innerText = d.model_version;
    document.getElementById('serialLink').innerText = d.serial_connected ? 'Connected' : 'Standby / None';

    document.getElementById('serverUrl').innerText = d.server_url;
    document.getElementById('pairedStatus').innerText = d.is_paired ? 'Paired (Active)' : 'Unpaired';
    document.getElementById('templateCount').innerText = d.template_count + ' students';
    document.getElementById('outboxCount').innerText = d.outbox_count + ' pending';
    document.getElementById('cameraInfo').innerText = d.camera_device + ' (' + d.camera_resolution + ')';
  } catch (e) {
    console.error(e);
  }
}

function formatUptime(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  return h + 'h ' + m + 'm';
}

function refreshSnapshot() {
  document.getElementById('snapshotImg').src = '/snapshot.jpg?t=' + Date.now();
}

async function triggerAction(action, confirmPrompt) {
  if (confirmPrompt && !confirm(confirmPrompt)) return;
  try {
    const res = await fetch('/api/control/action', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
      },
      body: JSON.stringify({ action: action })
    });
    const d = await res.json();
    showToast(d.message);
    if (action.includes('restart')) {
      setTimeout(() => { location.reload(); }, 2500);
    }
  } catch (e) {
    showToast('Failed to execute: ' + e);
  }
}

async function confirmReboot() {
  const code = prompt("Reboot entire gate device?\nType 'REBOOT' to confirm:");
  if (code !== 'REBOOT') {
    if (code !== null) showToast("Reboot cancelled (confirmation mismatch).");
    return;
  }
  try {
    const res = await fetch('/api/control/action', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
      },
      body: JSON.stringify({ action: 'reboot', confirm_code: 'REBOOT' })
    });
    const d = await res.json();
    showToast(d.message);
  } catch (e) {
    showToast('Failed to reboot: ' + e);
  }
}

setInterval(fetchInfo, 5000);
setInterval(refreshSnapshot, 3000);
checkLogin();
</script>
</body>
</html>
"#;

async fn control_page_handler() -> Html<&'static str> {
    Html(CONTROL_HTML)
}

// ── Router Registration ──────────────────────────────────────────────────────

pub fn register_control_routes(router: Router<ControlState>) -> Router<ControlState> {
    router
        .route("/control", get(control_page_handler))
        .route("/api/control/auth", post(login_handler))
        .route("/api/control/info", get(info_handler))
        .route("/api/control/action", post(action_handler))
}
