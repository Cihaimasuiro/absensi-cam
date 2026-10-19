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
    <title>Smart Absensi — Live Gate Monitor</title>
    <style>
        :root {
            --bg-base: #0b0f19;
            --bg-surface: #111827;
            --bg-elevated: #1f2937;
            --border: #374151;
            --border-light: #4b5563;
            --text-primary: #f9fafb;
            --text-muted: #9ca3af;
            --text-dim: #6b7280;
            --accent: #38bdf8;
            --accent-hover: #0284c7;
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.15);
            --warning: #f59e0b;
            --danger: #ef4444;
            --badge-in: #059669;
            --badge-out: #2563eb;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg-base);
            color: var(--text-primary);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            user-select: none;
        }

        /* ── Top Header Bar ── */
        .header {
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
            padding: 8px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 52px;
            flex-shrink: 0;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .brand-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            background: var(--bg-elevated);
            color: var(--text-muted);
            border: 1px solid var(--border);
            text-transform: uppercase;
        }
        .status-dot-wrap {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--warning);
            transition: background 0.3s ease;
        }
        .status-dot.live {
            background: var(--success);
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
            animation: pulse-dot 2s infinite;
        }
        .status-dot.error {
            background: var(--danger);
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }

        .header-metrics {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 11px;
            color: var(--text-muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .metric-item strong {
            color: var(--accent);
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn {
            background: var(--bg-elevated);
            color: var(--text-primary);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .btn:hover {
            background: var(--border);
            border-color: var(--border-light);
        }
        .btn:active {
            transform: scale(0.98);
        }
        .btn.active {
            background: rgba(56, 189, 248, 0.2);
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── Main Layout ── */
        .main-content {
            display: flex;
            flex: 1;
            height: calc(100vh - 52px);
            overflow: hidden;
        }

        /* ── Camera View Area ── */
        .cam-viewport {
            flex: 2;
            background: #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            border-right: 1px solid var(--border);
        }
        .cam-stage {
            position: relative;
            max-width: 100%;
            max-height: calc(100vh - 110px);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #stream-img, #frozen-canvas {
            display: block;
            max-width: 100%;
            max-height: calc(100vh - 110px);
            object-fit: contain;
            border-radius: 4px;
        }
        #overlay-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            cursor: crosshair;
        }
        .flash-overlay {
            position: absolute;
            inset: 0;
            border: 3px solid var(--success);
            box-shadow: inset 0 0 20px rgba(16, 185, 129, 0.4);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.4s ease-out;
            border-radius: 4px;
        }

        /* ── Floating Controls Toolbar ── */
        .cam-toolbar {
            position: absolute;
            bottom: 12px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(55, 65, 81, 0.8);
            border-radius: 8px;
            padding: 4px 8px;
            display: flex;
            gap: 6px;
            z-index: 10;
        }

        /* ── Pop-up Tooltip on Face Click ── */
        .face-popup {
            position: absolute;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent);
            border-radius: 6px;
            padding: 8px 12px;
            color: #fff;
            font-size: 11px;
            pointer-events: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
            z-index: 20;
            display: none;
            transform: translate(-50%, -100%);
            margin-top: -8px;
            white-space: nowrap;
        }
        .face-popup::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: var(--accent);
        }

        /* ── Attendance Log Sidebar ── */
        .sidebar {
            flex: 1;
            max-width: 360px;
            min-width: 280px;
            background: var(--bg-surface);
            display: flex;
            flex-direction: column;
            border-left: 1px solid var(--border);
        }
        .sidebar-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }
        .sidebar-badge {
            font-size: 10px;
            background: var(--bg-elevated);
            color: var(--accent);
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
            border: 1px solid var(--border);
        }
        .sidebar-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .log-card {
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            transition: border-color 0.15s ease;
            animation: slide-in 0.25s ease-out;
        }
        .log-card:hover {
            border-color: var(--border-light);
        }
        @keyframes slide-in {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .log-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .log-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .direction-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 1px 5px;
            border-radius: 3px;
            color: #fff;
            flex-shrink: 0;
        }
        .direction-badge.in { background: var(--badge-in); }
        .direction-badge.out { background: var(--badge-out); }
        .log-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            color: var(--text-dim);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .empty-logs {
            text-align: center;
            padding: 40px 16px;
            color: var(--text-dim);
            font-size: 12px;
            line-height: 1.5;
        }

        /* ── Mobile Responsive ── */
        @media (max-width: 860px) {
            .main-content {
                flex-direction: column;
                height: auto;
                overflow-y: auto;
            }
            .cam-viewport {
                flex: none;
                height: 55vh;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
            .sidebar {
                max-width: 100%;
                min-width: 100%;
                height: 45vh;
            }
            .header-metrics {
                display: none;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-brand">
            <div class="brand-title">
                Smart Absensi
                <span class="brand-badge">Gate Monitor</span>
            </div>
            <div class="status-dot-wrap" id="status-pill">
                <span class="status-dot live" id="status-dot"></span>
                <span id="status-text">TERHUBUNG</span>
            </div>
        </div>

        <div class="header-metrics">
            <span class="metric-item">CAM: <strong id="cam-fps-val">--</strong> FPS</span>
            <span class="metric-item">AI: <strong id="ai-fps-val">--</strong> FPS</span>
            <span class="metric-item">WAJAH: <strong id="face-count-val">0</strong></span>
            <span class="metric-item">UPTIME: <strong id="uptime-val">--</strong></span>
        </div>

        <div class="header-actions">
            <button class="btn" id="btn-refresh" title="Muat ulang aliran kamera">Refresh</button>
            <button class="btn" id="btn-snapshot" title="Unduh snapshot frame saat ini">Snapshot</button>
        </div>
    </header>

    <main class="main-content">
        <section class="cam-viewport" id="viewport">
            <div class="cam-stage" id="stage">
                <img id="stream-img" src="/stream" alt="Live Camera Stream" />
                <canvas id="frozen-canvas" style="display: none;"></canvas>
                <canvas id="overlay-canvas" width="640" height="480"></canvas>
                <div class="flash-overlay" id="flash-effect"></div>
                <div class="face-popup" id="face-tooltip"></div>
            </div>

            <nav class="cam-toolbar">
                <button class="btn active" id="btn-toggle-overlay" title="Tampilkan atau sembunyikan kotak AI">Overlay</button>
                <button class="btn" id="btn-pause-stream" title="Jeda atau lanjutkan video"><span id="pause-text">Pause</span></button>
                <button class="btn" id="btn-fullscreen" title="Layar penuh">Fullscreen</button>
            </nav>
        </section>

        <aside class="sidebar">
            <div class="sidebar-header">
                <span class="sidebar-title">Presensi Terbaru</span>
                <span class="sidebar-badge" id="log-count">0</span>
            </div>
            <div class="sidebar-list" id="log-items">
                <div class="empty-logs" id="empty-state">
                    Belum ada presensi siswa terdeteksi.<br>
                    Silakan posisikan wajah di depan kamera.
                </div>
            </div>
        </aside>
    </main>

    <script>
        // DOM Elements
        const streamImg = document.getElementById('stream-img');
        const frozenCanvas = document.getElementById('frozen-canvas');
        const frozenCtx = frozenCanvas.getContext('2d');
        const canvas = document.getElementById('overlay-canvas');
        const ctx = canvas.getContext('2d');
        const logContainer = document.getElementById('log-items');
        const logCountBadge = document.getElementById('log-count');
        const emptyState = document.getElementById('empty-state');
        const statusDot = document.getElementById('status-dot');
        const statusText = document.getElementById('status-text');
        const camFpsVal = document.getElementById('cam-fps-val');
        const aiFpsVal = document.getElementById('ai-fps-val');
        const faceCountVal = document.getElementById('face-count-val');
        const uptimeVal = document.getElementById('uptime-val');
        const flashEffect = document.getElementById('flash-effect');
        const faceTooltip = document.getElementById('face-tooltip');

        // Buttons
        const btnRefresh = document.getElementById('btn-refresh');
        const btnSnapshot = document.getElementById('btn-snapshot');
        const btnToggleOverlay = document.getElementById('btn-toggle-overlay');
        const btnPause = document.getElementById('btn-pause-stream');
        const btnFullscreen = document.getElementById('btn-fullscreen');
        const pauseText = document.getElementById('pause-text');

        // State
        let isOverlayEnabled = true;
        let isPaused = false;
        let currentFaces = [];
        let lastHistoryHash = "";
        let sseReconnectTimer = null;
        let currentScaleX = 1;
        let currentScaleY = 1;

        // XSS Prevention: Safe HTML escaping
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function syncCanvasSize(w, h) {
            if (canvas.width !== w || canvas.height !== h) {
                canvas.width = w;
                canvas.height = h;
                frozenCanvas.width = w;
                frozenCanvas.height = h;
            }
        }

        // Draw bounding boxes, labels, and status
        function renderOverlay(data) {
            if (data.frame_width && data.frame_height) {
                syncCanvasSize(data.frame_width, data.frame_height);
            }

            // Update Header Telemetry
            if (data.status) {
                // Parse "Aktif: HH:MM:SS | Camera: X.X FPS | AI: Y.Y FPS"
                const parts = data.status.split('|');
                if (parts[0]) uptimeVal.textContent = parts[0].replace('Aktif:', '').trim();
                if (parts[1]) camFpsVal.textContent = parts[1].replace('Camera:', '').replace('FPS', '').trim();
                if (parts[2]) aiFpsVal.textContent = parts[2].replace('AI:', '').replace('FPS', '').trim();
            }

            currentFaces = data.faces || [];
            faceCountVal.textContent = currentFaces.length;

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (!isOverlayEnabled || isPaused) return;

            // Draw Face Bounding Boxes
            for (const face of currentFaces) {
                ctx.save();
                ctx.lineWidth = 2;
                ctx.strokeStyle = face.color || "#00ff00";
                ctx.strokeRect(face.x, face.y, face.width, face.height);

                // Corner accents for polished look
                const cornerLen = Math.min(10, Math.floor(face.width / 4));
                ctx.lineWidth = 3;
                // Top-left
                ctx.beginPath();
                ctx.moveTo(face.x, face.y + cornerLen);
                ctx.lineTo(face.x, face.y);
                ctx.lineTo(face.x + cornerLen, face.y);
                ctx.stroke();

                // Bottom badge
                const label = face.name_text || "";
                const score = face.score_text || "";
                ctx.font = "bold 12px sans-serif";
                const maxW = Math.max(ctx.measureText(label).width, ctx.measureText(score).width);

                ctx.fillStyle = "rgba(15, 23, 42, 0.85)";
                ctx.fillRect(face.x, face.y + face.height - 32, maxW + 12, 32);

                ctx.fillStyle = face.color || "#38bdf8";
                ctx.fillText(score, face.x + 6, face.y + face.height - 18);
                ctx.fillStyle = "#ffffff";
                ctx.fillText(label, face.x + 6, face.y + face.height - 5);
                ctx.restore();
            }

            // Update History List (Safe escaping)
            if (data.history) {
                const historyHash = JSON.stringify(data.history);
                if (historyHash !== lastHistoryHash) {
                    // Flash screen green on new attendance record
                    if (lastHistoryHash !== "" && data.history.length > 0) {
                        triggerSuccessFlash();
                    }
                    lastHistoryHash = historyHash;
                    logCountBadge.textContent = data.history.length;

                    if (data.history.length === 0) {
                        if (emptyState) emptyState.style.display = 'block';
                    } else {
                        if (emptyState) emptyState.style.display = 'none';
                        logContainer.innerHTML = data.history.map(item => {
                            const direction = (item.direction || 'in').toLowerCase();
                            const isOut = direction === 'out';
                            const dirClass = isOut ? 'out' : 'in';
                            const dirText = isOut ? 'Keluar' : 'Masuk';
                            const safeName = escapeHtml(item.name || 'Siswa');
                            const safeTime = escapeHtml(item.captured_at || '');
                            const safeId = escapeHtml(item.student_id || '');

                            return `
                                <div class="log-card">
                                    <div class="log-top">
                                        <div class="log-name" title="${safeName}">${safeName}</div>
                                        <span class="direction-badge ${dirClass}">${dirText}</span>
                                    </div>
                                    <div class="log-bottom">
                                        <span>ID: ${safeId || '-'}</span>
                                        <span>${safeTime}</span>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }
                }
            }
        }

        function triggerSuccessFlash() {
            flashEffect.style.opacity = '1';
            setTimeout(() => {
                flashEffect.style.opacity = '0';
            }, 350);
        }

        // Connect SSE with auto-recovery
        function connectSSE() {
            if (sseReconnectTimer) clearTimeout(sseReconnectTimer);

            statusDot.className = 'status-dot';
            statusText.textContent = 'MENYAMBUNG...';

            const evtSource = new EventSource('/events');

            evtSource.onopen = () => {
                statusDot.className = 'status-dot live';
                statusText.textContent = 'TERHUBUNG';
            };

            evtSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    renderOverlay(data);
                } catch(err) {}
            };

            evtSource.onerror = () => {
                statusDot.className = 'status-dot error';
                statusText.textContent = 'TERPUTUS';
                evtSource.close();
                sseReconnectTimer = setTimeout(connectSSE, 2000);
            };
        }

        // Tooltip on Face Click / Tap
        canvas.addEventListener('click', (e) => {
            const rect = canvas.getBoundingClientRect();
            const clickX = (e.clientX - rect.left) * (canvas.width / rect.width);
            const clickY = (e.clientY - rect.top) * (canvas.height / rect.height);

            let hit = null;
            for (const face of currentFaces) {
                if (
                    clickX >= face.x &&
                    clickX <= face.x + face.width &&
                    clickY >= face.y &&
                    clickY <= face.y + face.height
                ) {
                    hit = face;
                    break;
                }
            }

            if (hit) {
                const screenX = (hit.x + hit.width / 2) * (rect.width / canvas.width);
                const screenY = hit.y * (rect.height / canvas.height);
                faceTooltip.innerHTML = `<strong>${escapeHtml(hit.name_text || 'Tidak Dikenal')}</strong><br>Akurasi: ${escapeHtml(hit.score_text || '-')}`;
                faceTooltip.style.left = `${screenX}px`;
                faceTooltip.style.top = `${screenY}px`;
                faceTooltip.style.display = 'block';

                setTimeout(() => {
                    faceTooltip.style.display = 'none';
                }, 3000);
            } else {
                faceTooltip.style.display = 'none';
            }
        });

        // ── Button Handlers ──

        // Refresh Stream
        btnRefresh.addEventListener('click', () => {
            const ts = Date.now();
            streamImg.src = `/stream?_t=${ts}`;
            connectSSE();
        });

        // Toggle Bounding Boxes
        btnToggleOverlay.addEventListener('click', () => {
            isOverlayEnabled = !isOverlayEnabled;
            btnToggleOverlay.classList.toggle('active', isOverlayEnabled);
            if (!isOverlayEnabled) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }
        });

        // Pause / Resume (Freeze frame)
        btnPause.addEventListener('click', () => {
            isPaused = !isPaused;
            if (isPaused) {
                // Copy current frame onto frozen canvas
                frozenCtx.drawImage(streamImg, 0, 0, frozenCanvas.width, frozenCanvas.height);
                streamImg.style.display = 'none';
                frozenCanvas.style.display = 'block';
                pauseText.textContent = 'Resume';
                btnPause.classList.add('active');
            } else {
                frozenCanvas.style.display = 'none';
                streamImg.style.display = 'block';
                pauseText.textContent = 'Pause';
                btnPause.classList.remove('active');
                // Reconnect image stream
                streamImg.src = `/stream?_t=${Date.now()}`;
            }
        });

        // Snapshot download
        btnSnapshot.addEventListener('click', () => {
            const a = document.createElement('a');
            a.href = `/snapshot.jpg?_t=${Date.now()}`;
            a.download = `smart-absensi-snapshot-${new Date().toISOString().replace(/[:.]/g, '-')}.jpg`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });

        // Fullscreen toggle
        btnFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        });

        // Initialize
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
                (header::ACCESS_CONTROL_ALLOW_ORIGIN, "*"),
            ],
            frame,
        )
            .into_response()
    } else {
        (
            StatusCode::SERVICE_UNAVAILABLE,
            [(header::ACCESS_CONTROL_ALLOW_ORIGIN, "*")],
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
            (header::ACCESS_CONTROL_ALLOW_ORIGIN, "*"),
        ],
        body,
    )
        .into_response()
}

async fn status_handler(State(state): State<AppState>) -> impl IntoResponse {
    (
        [(header::ACCESS_CONTROL_ALLOW_ORIGIN, "*")],
        Json(state.overlay_hub.latest()),
    )
}

async fn history_handler(State(state): State<AppState>) -> impl IntoResponse {
    (
        [(header::ACCESS_CONTROL_ALLOW_ORIGIN, "*")],
        Json(state.overlay_hub.latest().history),
    )
}

async fn events_handler(State(state): State<AppState>) -> impl IntoResponse {
    let rx = state.overlay_hub.subscribe();
    let stream = WatchStream::new(rx).map(|overlay| -> Result<Event, std::convert::Infallible> {
        let json_str = serde_json::to_string(&overlay).unwrap_or_else(|_| "{}".to_string());
        Ok(Event::default().data(json_str))
    });

    (
        [(header::ACCESS_CONTROL_ALLOW_ORIGIN, "*")],
        Sse::new(stream).keep_alive(KeepAlive::default()),
    )
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
