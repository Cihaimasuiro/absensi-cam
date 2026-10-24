use axum::{
    body::Body,
    extract::{ConnectInfo, Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{
        sse::{Event, KeepAlive, Sse},
        IntoResponse, Json, Response,
    },
    routing::get,
    Router,
};
use bytes::Bytes;
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use std::net::SocketAddr;
use std::sync::Arc;
use tokio::sync::watch;
use tokio_stream::wrappers::WatchStream;
use tracing::info;

pub mod auth;
pub mod control;
pub use control::{register_control_routes, ControlState, ServerConfig};

/// Pusat distribusi frame yang didukung oleh tokio::sync::watch.
#[derive(Clone)]
pub struct FrameHub {
    sender: Arc<watch::Sender<Option<Bytes>>>,
    mirror: Arc<std::sync::atomic::AtomicBool>,
}

impl Default for FrameHub {
    fn default() -> Self {
        Self::new()
    }
}

impl FrameHub {
    pub fn new() -> Self {
        let (sender, _) = watch::channel(None);
        Self {
            sender: Arc::new(sender),
            mirror: Arc::new(std::sync::atomic::AtomicBool::new(false)),
        }
    }

    pub fn publish(&self, frame: Bytes) {
        let _ = self.sender.send_replace(Some(frame));
    }

    /// Membalik preview secara horizontal. Diterapkan oleh encoder MJPEG saat
    /// `camera.mirror_preview` diaktifkan pada pengaturan edge.
    pub fn set_mirror(&self, enabled: bool) {
        self.mirror.store(enabled, std::sync::atomic::Ordering::Relaxed);
    }

    pub fn is_mirrored(&self) -> bool {
        self.mirror.load(std::sync::atomic::Ordering::Relaxed)
    }

    pub fn subscribe(&self) -> watch::Receiver<Option<Bytes>> {
        self.sender.subscribe()
    }

    pub fn latest(&self) -> Option<Bytes> {
        self.sender.borrow().clone()
    }

    pub fn has_viewers(&self) -> bool {
        self.sender.receiver_count() > 0
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FaceBoxData {
    pub x: i32,
    pub y: i32,
    pub width: i32,
    pub height: i32,
    pub color: String,
    pub score_text: String,
    pub name_text: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AttendanceLogItem {
    pub student_id: String,
    pub name: String,
    pub captured_at: String,
    pub direction: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OverlayState {
    pub status: String,
    pub frame_width: u32,
    pub frame_height: u32,
    pub faces: Vec<FaceBoxData>,
    pub history: Vec<AttendanceLogItem>,
}

impl Default for OverlayState {
    fn default() -> Self {
        Self {
            status: "Memulai...".to_string(),
            frame_width: 640,
            frame_height: 480,
            faces: Vec::new(),
            history: Vec::new(),
        }
    }
}

#[derive(Clone)]
pub struct OverlayHub {
    sender: Arc<watch::Sender<OverlayState>>,
}

impl Default for OverlayHub {
    fn default() -> Self {
        Self::new()
    }
}

impl OverlayHub {
    pub fn new() -> Self {
        let (sender, _) = watch::channel(OverlayState::default());
        Self {
            sender: Arc::new(sender),
        }
    }

    pub fn publish(&self, state: OverlayState) {
        let _ = self.sender.send_replace(state);
    }

    pub fn latest(&self) -> OverlayState {
        self.sender.borrow().clone()
    }

    pub fn subscribe(&self) -> watch::Receiver<OverlayState> {
        self.sender.subscribe()
    }
}

#[derive(Clone)]
pub struct AppState {
    pub frame_hub: FrameHub,
    pub overlay_hub: OverlayHub,
    pub camera_simulated: Arc<std::sync::atomic::AtomicBool>,
    pub camera_warning: Arc<std::sync::RwLock<Option<String>>>,
}

#[derive(Debug, Deserialize)]
pub struct StreamAuthQuery {
    pub token: Option<String>,
    pub expires: Option<String>,
}

fn is_stream_authorized(
    auth: &auth::AuthManager,
    headers: &HeaderMap,
    query: &StreamAuthQuery,
    device_code: &str,
    peer_addr: Option<&SocketAddr>,
) -> bool {
    let is_paired = std::env::var("SMART_ABSENSI_TOKEN")
        .map(|t| !t.trim().is_empty())
        .unwrap_or(false);

    // Jika perangkat belum dipasangkan, izinkan stream untuk setup awal dan pengujian mandiri
    if !is_paired {
        return true;
    }

    // Hanya izinkan akses lokal tanpa token jika terhubung langsung dari socket loopback kernel
    if let Some(addr) = peer_addr {
        if addr.ip().is_loopback() {
            return true;
        }
    }

    // Cookie sesi atau token stream bertanda tangan HMAC yang valid
    auth.check_session(headers)
        || auth.verify_stream_token(query.token.as_deref(), query.expires.as_deref(), device_code)
}

async fn snapshot_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Query(query): Query<StreamAuthQuery>,
) -> Response {
    if !is_stream_authorized(&state.auth, &headers, &query, &state.config.device_code, Some(&peer_addr)) {
        return (
            StatusCode::UNAUTHORIZED,
            [(header::CONTENT_TYPE, "text/plain")],
            "Unauthorized: Stream token or active session required",
        )
            .into_response();
    }

    if let Some(frame) = state.app_state.frame_hub.latest() {
        (
            [
                (header::CONTENT_TYPE, "image/jpeg"),
                (header::CACHE_CONTROL, "no-cache, no-store, must-revalidate"),
            ],
            frame,
        )
            .into_response()
    } else {
        (
            StatusCode::SERVICE_UNAVAILABLE,
            "No camera frame available yet",
        )
            .into_response()
    }
}

async fn stream_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Query(query): Query<StreamAuthQuery>,
) -> Response {
    if !is_stream_authorized(&state.auth, &headers, &query, &state.config.device_code, Some(&peer_addr)) {
        return (
            StatusCode::UNAUTHORIZED,
            [(header::CONTENT_TYPE, "text/plain")],
            "Unauthorized: Stream token or active session required",
        )
            .into_response();
    }

    let rx = state.app_state.frame_hub.subscribe();
    let stream = WatchStream::new(rx)
        .filter_map(|opt_frame| async move { opt_frame })
        .map(|frame| -> Result<Bytes, std::convert::Infallible> {
            let header = format!(
                "--frame\r\nContent-Type: image/jpeg\r\nContent-Length: {}\r\n\r\n",
                frame.len()
            );
            let mut chunk = Vec::with_capacity(header.len() + frame.len() + 2);
            chunk.extend_from_slice(header.as_bytes());
            chunk.extend_from_slice(&frame);
            chunk.extend_from_slice(b"\r\n");
            Ok(Bytes::from(chunk))
        });

    let body = Body::from_stream(stream);
    (
        [
            (
                header::CONTENT_TYPE,
                "multipart/x-mixed-replace; boundary=frame",
            ),
            (header::CACHE_CONTROL, "no-cache, private"),
        ],
        body,
    )
        .into_response()
}

async fn status_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Query(query): Query<StreamAuthQuery>,
) -> Response {
    if !is_stream_authorized(&state.auth, &headers, &query, &state.config.device_code, Some(&peer_addr)) {
        return (
            StatusCode::UNAUTHORIZED,
            [(header::CONTENT_TYPE, "text/plain")],
            "Unauthorized: Stream token or active session required",
        )
            .into_response();
    }
    Json(state.app_state.overlay_hub.latest()).into_response()
}

async fn history_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Query(query): Query<StreamAuthQuery>,
) -> Response {
    if !is_stream_authorized(&state.auth, &headers, &query, &state.config.device_code, Some(&peer_addr)) {
        return (
            StatusCode::UNAUTHORIZED,
            [(header::CONTENT_TYPE, "text/plain")],
            "Unauthorized: Stream token or active session required",
        )
            .into_response();
    }
    Json(state.app_state.overlay_hub.latest().history).into_response()
}

async fn events_handler(
    State(state): State<ControlState>,
    ConnectInfo(peer_addr): ConnectInfo<SocketAddr>,
    headers: HeaderMap,
    Query(query): Query<StreamAuthQuery>,
) -> Response {
    if !is_stream_authorized(&state.auth, &headers, &query, &state.config.device_code, Some(&peer_addr)) {
        return (
            StatusCode::UNAUTHORIZED,
            [(header::CONTENT_TYPE, "text/plain")],
            "Unauthorized: Stream token or active session required",
        )
            .into_response();
    }

    let rx = state.app_state.overlay_hub.subscribe();
    let stream = WatchStream::new(rx).map(|overlay| -> Result<Event, std::convert::Infallible> {
        let json_str = serde_json::to_string(&overlay).unwrap_or_else(|_| "{}".to_string());
        Ok(Event::default().data(json_str))
    });

    Sse::new(stream).keep_alive(KeepAlive::default()).into_response()
}

pub async fn start_stream_server(
    port: u16,
    hub: FrameHub,
    overlay_hub: OverlayHub,
    config: ServerConfig,
    camera_simulated: Arc<std::sync::atomic::AtomicBool>,
    camera_warning: Arc<std::sync::RwLock<Option<String>>>,
) -> anyhow::Result<()> {
    let state = AppState {
        frame_hub: hub,
        overlay_hub,
        camera_simulated,
        camera_warning,
    };

    let stream_secret = std::env::var("SMART_ABSENSI_TOKEN")
        .or_else(|_| std::env::var("STREAM_SECRET"))
        .unwrap_or_else(|_| {
            format!("{}{}", uuid::Uuid::new_v4().simple(), uuid::Uuid::new_v4().simple())
        });
    let db_dir = std::path::Path::new(&config.db_path)
        .parent()
        .unwrap_or(std::path::Path::new("."));
    let auth = Arc::new(auth::AuthManager::new(db_dir, stream_secret));

    let control_state = ControlState {
        app_state: state,
        config,
        auth,
        network_rollback: Arc::new(tokio::sync::Mutex::new(control::NetworkRollbackState::default())),
    };

    let app = Router::new()
        .route("/stream", get(stream_handler))
        .route("/snapshot.jpg", get(snapshot_handler))
        .route("/status", get(status_handler))
        .route("/history", get(history_handler))
        .route("/events", get(events_handler))
        .merge(register_control_routes(Router::new()))
        .with_state(control_state);

    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    info!("MJPEG Stream & Control server aktif di http://{}", addr);

    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app.into_make_service_with_connect_info::<SocketAddr>()).await?;
    Ok(())
}
