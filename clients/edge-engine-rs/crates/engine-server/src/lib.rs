use axum::{
    body::Body,
    extract::State,
    http::{header, StatusCode},
    response::{Html, IntoResponse, Response},
    routing::get,
    Router,
};
use bytes::Bytes;
use futures_util::StreamExt;
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
}

async fn index_handler() -> Html<&'static str> {
    Html(r#"<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Smart Absensi Edge Stream (Rust)</title>
    <style>
        body { background: #111; color: #eee; font-family: sans-serif; text-align: center; margin: 0; padding: 20px; }
        .stream-box { max-width: 800px; margin: 0 auto; background: #222; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.5); }
        img { width: 100%; height: auto; display: block; }
        .meta { padding: 12px; font-size: 14px; color: #aaa; }
    </style>
</head>
<body>
    <h2>Smart Absensi Edge Camera (Rust Engine)</h2>
    <div class="stream-box">
        <img src="/stream" alt="Live Stream" />
        <div class="meta">Live MJPEG Video Feed</div>
    </div>
</body>
</html>"#)
}

async fn snapshot_handler(State(hub): State<FrameHub>) -> Response {
    if let Some(frame) = hub.latest() {
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

async fn stream_handler(State(hub): State<FrameHub>) -> Response {
    let rx = hub.subscribe();
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

pub async fn start_stream_server(port: u16, hub: FrameHub) -> anyhow::Result<()> {
    let app = Router::new()
        .route("/", get(index_handler))
        .route("/stream", get(stream_handler))
        .route("/video_feed", get(stream_handler))
        .route("/snapshot.jpg", get(snapshot_handler))
        .with_state(hub);

    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    info!("MJPEG Stream server aktif di http://{}", addr);

    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
}
