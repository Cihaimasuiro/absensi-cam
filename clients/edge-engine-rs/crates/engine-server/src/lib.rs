use axum::{
    body::Body,
    extract::State,
    http::{header, StatusCode},
    response::{
        sse::{Event, KeepAlive, Sse},
        Html, IntoResponse, Json, Response,
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

/// Central frame distribution hub backed by tokio::sync::watch.
/// Each consumer receives only the newest frame, completely preventing
/// slow HTTP clients from buffering or stalling the V4L2 capture thread.
#[derive(Clone)]
pub struct FrameHub {
    sender: Arc<watch::Sender<Option<Bytes>>>,
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
        }
    }

    /// Publish a newly captured frame to all connected viewers.
    /// Uses send_replace so that latest frame is retained for snapshot even if no active stream receivers exist.
    pub fn publish(&self, frame: Bytes) {
        let _ = self.sender.send_replace(Some(frame));
    }

    /// Subscribe to receiving live frames.
    pub fn subscribe(&self) -> watch::Receiver<Option<Bytes>> {
        self.sender.subscribe()
    }

    /// Get the single most recent frame, if available.
    pub fn latest(&self) -> Option<Bytes> {
        self.sender.borrow().clone()
    }

    /// Check if any active HTTP stream subscribers are currently connected.
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

/// Central distribution hub for face bounding boxes, labels, and attendance logs.
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
}

async fn index_handler() -> Html<&'static str> {
    Html(r##"<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Absensi — Live Stream & Vision</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f172a; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; height: 100vh; overflow: hidden; }
        .cam-wrapper { flex: 2; background: #000; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
        .cam-container { position: relative; display: inline-block; max-width: 100%; max-height: 100%; }
        #stream-img { display: block; max-width: 100%; max-height: 100vh; object-fit: contain; }
        #overlay-canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .sidebar { flex: 1; max-width: 380px; min-width: 280px; background: #1e293b; border-left: 1px solid #334155; display: flex; flex-direction: column; }
        .sidebar-header { padding: 16px; border-bottom: 1px solid #334155; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .sidebar-list { flex: 1; overflow-y: auto; padding: 12px; }
        .log-item { padding: 10px 12px; border-radius: 6px; background: #0f172a; margin-bottom: 8px; border: 1px solid #334155; }
        .log-name { font-weight: 600; font-size: 13px; color: #f1f5f9; margin-bottom: 4px; word-break: break-word; }
        .log-time { font-size: 11px; color: #64748b; }
        .status-badge { display: inline-block; padding: 2px 6px; font-size: 10px; border-radius: 4px; background: #059669; color: #fff; margin-left: 6px; }
    </style>
</head>
<body>
    <div class="cam-wrapper">
        <div class="cam-container" id="container">
            <img id="stream-img" src="/stream" alt="Live Camera" />
            <canvas id="overlay-canvas" width="640" height="480"></canvas>
        </div>
    </div>
    <div class="sidebar">
        <div class="sidebar-header">Log Terbaru</div>
        <div class="sidebar-list" id="log-items"></div>
    </div>
    <script>
        const canvas = document.getElementById('overlay-canvas');
        const ctx = canvas.getContext('2d');
        const logContainer = document.getElementById('log-items');

        let lastHistoryHash = "";

        function syncCanvasSize(w, h) {
            if (canvas.width !== w || canvas.height !== h) {
                canvas.width = w;
                canvas.height = h;
            }
        }

        function renderOverlay(data) {
            if (data.frame_width && data.frame_height) {
                syncCanvasSize(data.frame_width, data.frame_height);
            }

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // 1. Draw top status bar
            if (data.status) {
                ctx.save();
                ctx.font = "bold 13px monospace, sans-serif";
                const textWidth = ctx.measureText(data.status).width;
                ctx.fillStyle = "rgba(0, 0, 0, 0.75)";
                ctx.fillRect(8, 8, textWidth + 16, 24);
                ctx.fillStyle = "#38bdf8";
                ctx.fillText(data.status, 16, 24);
                ctx.restore();
            }

            // 2. Draw face bounding boxes & labels
            if (data.faces && data.faces.length > 0) {
                for (const face of data.faces) {
                    ctx.save();
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = face.color || "#00ff00";
                    ctx.strokeRect(face.x, face.y, face.width, face.height);

                    // Label box at bottom of face
                    const label = face.name_text || "";
                    const score = face.score_text || "";
                    ctx.font = "bold 12px sans-serif";
                    const maxW = Math.max(ctx.measureText(label).width, ctx.measureText(score).width);

                    ctx.fillStyle = "rgba(0, 0, 0, 0.8)";
                    ctx.fillRect(face.x, face.y + face.height - 34, maxW + 12, 34);

                    // Text labels
                    ctx.fillStyle = face.color || "#00ff00";
                    ctx.fillText(score, face.x + 6, face.y + face.height - 18);
                    ctx.fillText(label, face.x + 6, face.y + face.height - 5);
                    ctx.restore();
                }
            }

            // 3. Update history sidebar
            if (data.history) {
                const historyHash = JSON.stringify(data.history);
                if (historyHash !== lastHistoryHash) {
                    lastHistoryHash = historyHash;
                    logContainer.innerHTML = data.history.map(item => `
                        <div class="log-item">
                            <div class="log-name">${item.name || '-'}<span class="status-badge">${item.direction || 'in'}</span></div>
                            <div class="log-time">${item.captured_at || ''}</div>
                        </div>
                    `).join('');
                }
            }
        }

        function connectSSE() {
            const evtSource = new EventSource("/events");
            evtSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    renderOverlay(data);
                } catch(err) {}
            };
            evtSource.onerror = () => {
                evtSource.close();
                setTimeout(connectSSE, 2000);
            };
        }

        connectSSE();
    </script>
</body>
</html>"##)
}

async fn snapshot_handler(State(state): State<AppState>) -> Response {
    if let Some(frame) = state.frame_hub.latest() {
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

async fn stream_handler(State(state): State<AppState>) -> Response {
    let rx = state.frame_hub.subscribe();
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

async fn status_handler(State(state): State<AppState>) -> Json<OverlayState> {
    Json(state.overlay_hub.latest())
}

async fn history_handler(State(state): State<AppState>) -> Json<Vec<AttendanceLogItem>> {
    Json(state.overlay_hub.latest().history)
}

async fn events_handler(
    State(state): State<AppState>,
) -> Sse<impl futures_util::Stream<Item = Result<Event, std::convert::Infallible>>> {
    let rx = state.overlay_hub.subscribe();
    let stream = WatchStream::new(rx).map(|overlay| {
        let json_str = serde_json::to_string(&overlay).unwrap_or_else(|_| "{}".to_string());
        Ok(Event::default().data(json_str))
    });

    Sse::new(stream).keep_alive(KeepAlive::default())
}

pub async fn start_stream_server(
    port: u16,
    hub: FrameHub,
    overlay_hub: OverlayHub,
) -> anyhow::Result<()> {
    let state = AppState {
        frame_hub: hub,
        overlay_hub,
    };

    let app = Router::new()
        .route("/", get(index_handler))
        .route("/stream", get(stream_handler))
        .route("/video_feed", get(stream_handler))
        .route("/snapshot.jpg", get(snapshot_handler))
        .route("/status", get(status_handler))
        .route("/history", get(history_handler))
        .route("/events", get(events_handler))
        .with_state(state);

    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    info!("MJPEG Stream server aktif di http://{}", addr);

    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
}
