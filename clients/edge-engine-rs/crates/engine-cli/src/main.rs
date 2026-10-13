use anyhow::Result;
use bytes::Bytes;
use clap::{Parser, Subcommand};
use engine_core::{models::OutboxRecord, EdgeDb};
use engine_server::{AttendanceLogItem, FaceBoxData, FrameHub, OverlayHub, OverlayState};
use engine_sync::SyncWorker;
use engine_vision::{FaceDetector, FaceRecognizer, LivenessDetector};
use std::collections::HashMap;
use std::env;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};
use tracing::{debug, error, info, warn};



#[derive(Parser, Debug)]
#[command(
    name = "smart-absensi-sync",
    author = "Smart Absensi Team",
    version = "0.1.0",
    about = "Smart Absensi Edge Engine in Rust (Sync Daemon, MJPEG Streamer & AI Vision)"
)]
struct Cli {
    #[command(subcommand)]
    command: Option<Commands>,

    // Top-level arguments for default sync daemon behavior (backward compatibility)
    #[arg(short, long, default_value = "local_edge.db")]
    db: String,

    #[arg(long)]
    api_url: Option<String>,

    #[arg(long)]
    api_token: Option<String>,

    #[arg(long)]
    embed_key: Option<String>,

    #[arg(long)]
    model_version: Option<String>,
}

#[derive(Subcommand, Debug)]
enum Commands {
    /// Drain local SQLite outbox to Laravel API (default)
    Sync {
        #[arg(short, long, default_value = "local_edge.db")]
        db: String,

        #[arg(long)]
        api_url: Option<String>,

        #[arg(long)]
        api_token: Option<String>,

        #[arg(long)]
        embed_key: Option<String>,

        #[arg(long)]
        model_version: Option<String>,
    },

    /// Run pure libc V4L2 MJPEG Camera capture, HTTP stream server, and native AI Vision
    Stream {
        #[arg(short, long, default_value = "/dev/video0")]
        device: String,

        #[arg(short, long, default_value_t = 5000)]
        port: u16,

        #[arg(long, default_value_t = 640)]
        width: u32,

        #[arg(long, default_value_t = 480)]
        height: u32,

        #[arg(long, default_value = "local_edge.db")]
        db: String,

        #[arg(long)]
        models_dir: Option<String>,

        #[arg(long)]
        ort_path: Option<String>,

        #[arg(long, default_value_t = true)]
        vision: bool,

        #[arg(long, default_value_t = true)]
        liveness: bool,

        #[arg(long)]
        embed_key: Option<String>,

        #[arg(long)]
        model_version: Option<String>,

        #[arg(long, default_value_t = 0.60)]
        threshold: f32,
    },
}

#[tokio::main]
async fn main() -> Result<()> {
    // 1. Muat file .env jika ada (cek CWD, lalu fallback ke /opt/smart-absensi/edge-engine/.env)
    dotenvy::dotenv().ok();
    dotenvy::from_path("/opt/smart-absensi/edge-engine/.env").ok();

    // 2. Inisialisasi logging
    tracing_subscriber::fmt::init();
    let cli = Cli::parse();

    match cli.command {
        Some(Commands::Stream {
            device,
            port,
            width,
            height,
            db,
            models_dir,
            ort_path,
            vision,
            liveness,
            embed_key,
            model_version,
            threshold,
        }) => {
            run_stream(
                device,
                port,
                width,
                height,
                db,
                models_dir,
                ort_path,
                vision,
                liveness,
                embed_key,
                model_version,
                threshold,
            )
            .await?;
        }
        Some(Commands::Sync {
            db,
            api_url,
            api_token,
            embed_key,
            model_version,
        }) => {
            run_sync(db, api_url, api_token, embed_key, model_version).await?;
        }
        None => {
            // Default: run sync using top-level flags (for backward-compatible systemd service)
            run_sync(
                cli.db,
                cli.api_url,
                cli.api_token,
                cli.embed_key,
                cli.model_version,
            )
            .await?;
        }
    }

    Ok(())
}

fn find_model_path(base_dirs: &[PathBuf], candidates: &[&str]) -> Option<PathBuf> {
    for dir in base_dirs {
        for filename in candidates {
            let p = dir.join(filename);
            if p.exists() {
                return Some(p);
            }
        }
    }
    None
}

async fn run_stream(
    device: String,
    port: u16,
    width: u32,
    height: u32,
    db_path: String,
    models_dir_opt: Option<String>,
    ort_path_opt: Option<String>,
    vision_enabled: bool,
    liveness_enabled: bool,
    embed_key_opt: Option<String>,
    model_version_opt: Option<String>,
    similarity_threshold: f32,
) -> Result<()> {
    println!("--------------------------------------------------");
    println!(" Smart Absensi MJPEG Stream & AI Engine (Rust)");
    println!(" Device    : {}", device);
    println!(" Resolusi  : {}x{}", width, height);
    println!(" Server    : http://0.0.0.0:{}", port);
    println!(" Stream    : http://<BOARD_IP>:{}/stream", port);
    println!(" Snapshot  : http://<BOARD_IP>:{}/snapshot.jpg", port);
    println!(" AI Vision : {}", if vision_enabled { "AKTIF" } else { "NONAKTIF" });
    println!(" Liveness  : {}", if liveness_enabled { "AKTIF" } else { "NONAKTIF" });
    println!("--------------------------------------------------");

    let hub = FrameHub::new();
    let overlay_hub = OverlayHub::new();
    let overlay_hub_ai = overlay_hub.clone();
    let running = Arc::new(AtomicBool::new(true));
    let running_clone = running.clone();

    let recent_history = Arc::new(std::sync::Mutex::new(Vec::<AttendanceLogItem>::new()));
    let recent_history_ai = recent_history.clone();

    let cam_fps_shared = Arc::new(AtomicU32::new(300)); // 30.0 * 10
    let cam_fps_shared_ai = cam_fps_shared.clone();

    // 1. Inisialisasi Database SQLite
    let embed_key_bytes = embed_key_opt
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok())
        .and_then(|k| {
            use base64::Engine;
            base64::engine::general_purpose::STANDARD.decode(k).ok()
        });

    let model_version = model_version_opt
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "3d9f1f77896fb3d1".to_string());

    let db = Arc::new(EdgeDb::init(&db_path).await?);
    let templates = Arc::new(
        db.load_templates(embed_key_bytes.as_deref(), &model_version)
            .await
            .unwrap_or_default(),
    );
    println!("[Database] Memuat {} template wajah aktif.", templates.len());
    if templates.is_empty() && embed_key_bytes.is_none() {
        println!("[Database] Catatan: ENROLLMENT_EMBED_KEY tidak terdeteksi. Template wajah terenkripsi tidak dapat dimuat.");
    }

    // 2. Setup Decoupled AI Pipeline Channel (kapasitas 1, non-blocking)
    #[allow(dead_code)]
    enum AiFrame {
        Rgb { data: Bytes, width: u32, height: u32 },
        Jpeg { data: Bytes, width: u32, height: u32 },
    }

    let (tx_frame, rx_frame) = std::sync::mpsc::sync_channel::<AiFrame>(1);

    if vision_enabled {
        let base_dirs = vec![
            models_dir_opt.map(PathBuf::from).unwrap_or_else(|| PathBuf::from("models")),
            PathBuf::from("packages/models"),
            PathBuf::from("../../packages/models"),
            PathBuf::from("../packages/models"),
            PathBuf::from("clients/edge-engine/models"),
            PathBuf::from("/opt/smart-absensi/edge-engine/models"),
            PathBuf::from("."),
        ];

        let detector_path = find_model_path(&base_dirs, &["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"]);
        let recognizer_path = find_model_path(&base_dirs, &["recognizer.onnx", "sface.onnx", "arcface.onnx"]);
        let liveness_path = find_model_path(&base_dirs, &["liveness.onnx", "minifasnet.onnx"]);

        if let (Some(det_p), Some(rec_p)) = (detector_path, recognizer_path) {
            info!("Memuat model AI: Detector: {:?}, Recognizer: {:?}", det_p, rec_p);
            match engine_vision::init_onnx_runtime(ort_path_opt.as_deref().map(Path::new)) {
                Err(e) => {
                    eprintln!("--------------------------------------------------");
                    eprintln!("[AI Vision] ONNX Runtime dylib tidak dapat dimuat: {:#}", e);
                    eprintln!("[AI Vision] Harap tentukan lokasi libonnxruntime.so dengan:");
                    eprintln!("            --ort-path <PATH_TO_LIBONNXRUNTIME.SO>");
                    eprintln!("            atau pasang environment variable ORT_DYLIB_PATH");
                    eprintln!("            Streaming kamera tetap berjalan normal.");
                    eprintln!("--------------------------------------------------");
                }
                Ok(()) => {
                    let detector_res = FaceDetector::load(&det_p);
                    let recognizer_res = FaceRecognizer::load(&rec_p);

                    let liveness_res = if liveness_enabled {
                        if let Some(liv_p) = liveness_path {
                            info!("Memuat model Anti-Spoofing: {:?}", liv_p);
                            LivenessDetector::load(&liv_p).ok()
                        } else {
                            warn!("Model liveness.onnx tidak ditemukan. Berjalan tanpa liveness.");
                            None
                        }
                    } else {
                        None
                    };

                    match (detector_res, recognizer_res) {
                        (Ok(mut detector), Ok(mut recognizer)) => {
                            let running_ai = running.clone();
                            let templates_ai = templates.clone();
                            let db_ai = db.clone();
                            let mut liveness_ai = liveness_res;
                            let tokio_handle = tokio::runtime::Handle::current();
                            let engine_start = Instant::now();

                            std::thread::Builder::new()
                                .name("ai-vision-worker".to_string())
                                .spawn(move || {
                                    info!("AI Vision Worker thread aktif (decoupled).");
                                    let mut cooldown_map: HashMap<String, Instant> = HashMap::new();

                                    while running_ai.load(Ordering::Relaxed) {
                                        let (frame_rgb, w, h) = match rx_frame.recv_timeout(Duration::from_millis(100)) {
                                            Ok(AiFrame::Rgb { data, width, height }) => {
                                                // Zero-copy / zero-decode path: raw RGB dari YUYV langsung digunakan!
                                                (data, width, height)
                                            }
                                            Ok(AiFrame::Jpeg { data, width, height }) => {
                                                // Decode JPEG HANYA jika frame berasal dari kamera hardware MJPEG
                                                match image::load_from_memory_with_format(&data, image::ImageFormat::Jpeg) {
                                                    Ok(dyn_img) => (Bytes::from(dyn_img.to_rgb8().into_raw()), width, height),
                                                    Err(e) => {
                                                        debug!("AI worker lewati frame tak terbaca: {:#}", e);
                                                        continue;
                                                    }
                                                }
                                            }
                                            Err(_) => continue,
                                        };

                                        let start_ai = Instant::now();

                                        let faces = match detector.detect(&frame_rgb, w as usize, h as usize, (w * 3) as usize) {
                                            Ok(f) => f,
                                            Err(e) => {
                                                warn!("Face detector error: {:#}", e);
                                                continue;
                                            }
                                        };

                                        let mut current_boxes = Vec::new();

                                        for face in &faces {
                                            // Evaluasi Liveness
                                            let mut is_real = true;
                                            let mut liveness_prob = 1.0f32;
                                            if let Some(ref mut liveness) = liveness_ai {
                                                match liveness.evaluate(&frame_rgb, w as usize, h as usize, (w * 3) as usize, &face.bbox) {
                                                    Ok(res) => {
                                                        liveness_prob = res.prob_real;
                                                        is_real = res.is_real;
                                                        if !res.is_real {
                                                            debug!("Wajah ditolak: Liveness Spoof (logit_diff={:.2})", res.logit_diff);
                                                        }
                                                    }
                                                    Err(e) => {
                                                        debug!("Gagal evaluasi liveness: {:#}", e);
                                                    }
                                                }
                                            }

                                            if !is_real {
                                                current_boxes.push(FaceBoxData {
                                                    x: face.bbox[0] as i32,
                                                    y: face.bbox[1] as i32,
                                                    width: face.bbox[2] as i32,
                                                    height: face.bbox[3] as i32,
                                                    color: "#eab308".to_string(), // Yellow: Spoof
                                                    score_text: format!("score:{:.2} liv:{:.2}", face.score, liveness_prob),
                                                    name_text: "id:SPOOF".to_string(),
                                                });
                                                continue;
                                            }

                                            // Align wajah 112x112 NCHW
                                            let aligned_tensor = match engine_vision::align_face_to_tensor_nchw(
                                                &frame_rgb,
                                                w as usize,
                                                h as usize,
                                                (w * 3) as usize,
                                                &face.landmarks,
                                            ) {
                                                Some(t) => t,
                                                None => continue,
                                            };

                                            // Ekstraksi 512-d ArcFace embedding
                                            let embedding = match recognizer.extract_embedding(&aligned_tensor) {
                                                Ok(e) => e,
                                                Err(e) => {
                                                    warn!("Feature extractor error: {:#}", e);
                                                    continue;
                                                }
                                            };

                                            // Pencocokan Cosine Similarity
                                            let mut best_student: Option<(&str, &str, f32)> = None;
                                            for t in templates_ai.iter() {
                                                let sim = FaceRecognizer::cosine_similarity(&embedding, &t.embedding);
                                                if sim >= similarity_threshold {
                                                    if let Some((_, _, best_sim)) = best_student {
                                                        if sim > best_sim {
                                                            best_student = Some((&t.student_id, &t.name, sim));
                                                        }
                                                    } else {
                                                        best_student = Some((&t.student_id, &t.name, sim));
                                                    }
                                                }
                                            }

                                            let (box_color, name_label) = if let Some((_, name, _)) = best_student {
                                                ("#22c55e".to_string(), format!("id:{}", name)) // Green
                                            } else {
                                                ("#f97316".to_string(), "id:Unknown".to_string()) // Orange
                                            };

                                            current_boxes.push(FaceBoxData {
                                                x: face.bbox[0] as i32,
                                                y: face.bbox[1] as i32,
                                                width: face.bbox[2] as i32,
                                                height: face.bbox[3] as i32,
                                                color: box_color,
                                                score_text: format!("score:{:.2} liv:{:.2}", face.score, liveness_prob),
                                                name_text: name_label,
                                            });

                                            if let Some((student_id, name, score)) = best_student {
                                                let now = Instant::now();
                                                let on_cooldown = if let Some(&last_time) = cooldown_map.get(student_id) {
                                                    now.duration_since(last_time).as_secs() < 10
                                                } else {
                                                    false
                                                };

                                                if !on_cooldown {
                                                    cooldown_map.insert(student_id.to_string(), now);
                                                    let now_dt = chrono::Local::now();
                                                    let captured_at = now_dt.format("%Y-%m-%d %H:%M:%S").to_string();
                                                    let att_id = format!("att-{}", now_dt.timestamp_millis());

                                                    println!(
                                                        "🎯 [ABSENSI] Teridentifikasi: {} (ID: {}) | Kemiripan: {:.1}% | Liveness: {:.2}",
                                                        name, student_id, score * 100.0, liveness_prob
                                                    );

                                                    let log_item = AttendanceLogItem {
                                                        student_id: student_id.to_string(),
                                                        name: name.to_string(),
                                                        captured_at: captured_at.clone(),
                                                        direction: "in".to_string(),
                                                    };
                                                    if let Ok(mut hist) = recent_history_ai.lock() {
                                                        hist.insert(0, log_item);
                                                        if hist.len() > 20 {
                                                            hist.truncate(20);
                                                        }
                                                    }

                                                    let record = OutboxRecord {
                                                        id: att_id,
                                                        student_id: student_id.to_string(),
                                                        captured_at,
                                                        direction: "in".to_string(),
                                                        score: score as f64,
                                                        liveness_score: liveness_prob as f64,
                                                        time_source: "rtc".to_string(),
                                                        synced: 0,
                                                    };

                                                    let db_insert = db_ai.clone();
                                                    tokio_handle.spawn(async move {
                                                        if let Err(e) = db_insert.insert_attendance(&record).await {
                                                            error!("Gagal menyimpan absensi ke outbox: {:#}", e);
                                                        }
                                                    });
                                                }
                                            }
                                        }

                                        let elapsed_ai = start_ai.elapsed().as_secs_f32().max(0.001);
                                        let ai_fps = 1.0 / elapsed_ai;
                                        let uptime_sec = engine_start.elapsed().as_secs();
                                        let hrs = uptime_sec / 3600;
                                        let mins = (uptime_sec % 3600) / 60;
                                        let secs = uptime_sec % 60;
                                        let cam_fps_val = cam_fps_shared_ai.load(Ordering::Relaxed) as f32 / 10.0;
                                        let status = format!("Aktif: {:02}:{:02}:{:02} | Camera: {:.1} FPS | AI: {:.1} FPS", hrs, mins, secs, cam_fps_val, ai_fps);

                                        let history_snapshot = recent_history_ai.lock().map(|h| h.clone()).unwrap_or_default();
                                        overlay_hub_ai.publish(OverlayState {
                                            status,
                                            frame_width: w,
                                            frame_height: h,
                                            faces: current_boxes,
                                            history: history_snapshot,
                                        });

                                        // Thermal pacing: allow CPU cores to cool down between neural network inferences
                                        if faces.is_empty() {
                                            // Idle scanning: 3 FPS is optimal. Gives CPU 300ms cool-down period per cycle
                                            std::thread::sleep(Duration::from_millis(300));
                                        } else {
                                            // Active face tracking: fast 20 FPS recognition
                                            std::thread::sleep(Duration::from_millis(50));
                                        }
                                    }
                                })
                                .expect("Failed to spawn AI vision worker");
                        }
                        (Err(e), _) => {
                            warn!("Gagal memuat FaceDetector: {:#}. Melanjutkan streaming tanpa deteksi wajah.", e);
                        }
                        (_, Err(e)) => {
                            warn!("Gagal memuat FaceRecognizer: {:#}. Melanjutkan streaming tanpa pengenalan wajah.", e);
                        }
                    }
                }
            }
        } else {
            warn!("Model ONNX tidak lengkap. Jalankan tanpa AI Vision.");
        }
    }

    #[cfg(target_os = "linux")]
    {
        let tx_frame_clone = tx_frame.clone();
        let hub_clone = hub.clone();
        let hub_viewers = hub.clone();
        let cam_fps_clone = cam_fps_shared.clone();
        let mut cam_frame_count: u32 = 0;
        let mut last_fps_time = Instant::now();

        engine_vision::camera::linux::spawn_capture_thread_decoupled(
            device,
            width,
            height,
            running_clone,
            move || hub_viewers.has_viewers(),
            move |jpeg_bytes, _format, _w, _h| {
                // 1. Publikasikan frame JPEG hasil encode ke HTTP streamer (FrameHub)
                hub_clone.publish(Bytes::copy_from_slice(jpeg_bytes));
            },
            Some(move |frame_bytes: &[u8], format: engine_vision::camera::PixelFormat, w: u32, h: u32| {
                // Hitung true hardware capture FPS dari kamera
                cam_frame_count += 1;
                let elapsed = last_fps_time.elapsed();
                if elapsed.as_secs_f32() >= 1.0 {
                    let fps = cam_frame_count as f32 / elapsed.as_secs_f32();
                    cam_fps_clone.store((fps * 10.0) as u32, Ordering::Relaxed);
                    cam_frame_count = 0;
                    last_fps_time = Instant::now();
                }

                // 2. Berikan ke AI worker HANYA jika channel kosong (non-blocking)
                let ai_frame = match format {
                    engine_vision::camera::PixelFormat::Yuyv => AiFrame::Rgb {
                        data: Bytes::copy_from_slice(frame_bytes),
                        width: w,
                        height: h,
                    },
                    engine_vision::camera::PixelFormat::Mjpeg => AiFrame::Jpeg {
                        data: Bytes::copy_from_slice(frame_bytes),
                        width: w,
                        height: h,
                    },
                };
                tx_frame_clone.try_send(ai_frame).ok();
            }),
        );
    }

    #[cfg(not(target_os = "linux"))]
    {
        info!("Platform non-Linux: Mengaktifkan simulated camera feeder untuk pengujian lokal.");
        let tx_frame_clone = tx_frame.clone();
        let hub_mock = hub_clone.clone();
        let running_mock = running_clone.clone();
        let cam_fps_mock = cam_fps_shared.clone();

        let test_img_candidates = [
            PathBuf::from("test_face.png"),
            PathBuf::from("crates/engine-vision/test_face.png"),
            PathBuf::from("../crates/engine-vision/test_face.png"),
            PathBuf::from("clients/edge-engine-rs/crates/engine-vision/test_face.png"),
            PathBuf::from("packages/models/test_face.png"),
        ];

        let mut base_rgb = vec![128u8; (width * height * 3) as usize];
        for cand in &test_img_candidates {
            if cand.exists() {
                if let Ok(dyn_img) = image::open(cand) {
                    info!("Memuat test face image untuk simulasi kamera: {:?}", cand);
                    let rgb_img = dyn_img.to_rgb8();
                    let resized = image::imageops::resize(
                        &rgb_img,
                        width,
                        height,
                        image::imageops::FilterType::Triangle,
                    );
                    base_rgb = resized.into_raw();
                    break;
                }
            }
        }

        std::thread::Builder::new()
            .name("mock-camera-feeder".to_string())
            .spawn(move || {
                info!("Simulated camera feeder thread aktif (30 FPS).");
                let mut jpeg_buf = Vec::with_capacity((width * height / 4) as usize);
                let mut frame_count: u32 = 0;
                let mut last_fps_time = Instant::now();

                while running_mock.load(Ordering::Relaxed) {
                    if engine_vision::encode_rgb_to_jpeg_into(&base_rgb, width, height, 80, &mut jpeg_buf).is_ok() {
                        let b = Bytes::copy_from_slice(&jpeg_buf);
                        hub_mock.publish(b);
                        tx_frame_clone.try_send(AiFrame::Rgb {
                            data: Bytes::copy_from_slice(&base_rgb),
                            width,
                            height,
                        }).ok();
                    }

                    frame_count += 1;
                    let elapsed = last_fps_time.elapsed();
                    if elapsed.as_secs_f32() >= 1.0 {
                        let fps = frame_count as f32 / elapsed.as_secs_f32();
                        cam_fps_mock.store((fps * 10.0) as u32, Ordering::Relaxed);
                        frame_count = 0;
                        last_fps_time = Instant::now();
                    }

                    std::thread::sleep(Duration::from_millis(33)); // ~30 FPS
                }
            })
            .expect("Failed to spawn mock camera feeder thread");
    }

    // Jalankan Axum HTTP stream server
    tokio::select! {
        res = engine_server::start_stream_server(port, hub, overlay_hub) => {
            if let Err(e) = res {
                error!("Stream server error: {:#}", e);
            }
        }
        _ = tokio::signal::ctrl_c() => {
            info!("Menerima Ctrl+C, menghentikan stream server...");
            running.store(false, Ordering::Relaxed);
        }
    }

    Ok(())
}

async fn run_sync(
    db_path: String,
    api_url_opt: Option<String>,
    api_token_opt: Option<String>,
    embed_key_opt: Option<String>,
    model_version_opt: Option<String>,
) -> Result<()> {
    info!("Memulai Smart Absensi Sync Daemon (Rust)...");

    let api_url = api_url_opt
        .or_else(|| env::var("SMART_ABSENSI_URL").ok())
        .unwrap_or_else(|| "http://127.0.0.1:8000".to_string());

    let api_token = api_token_opt
        .or_else(|| env::var("SMART_ABSENSI_TOKEN").ok())
        .unwrap_or_default();

    let embed_key_b64 = embed_key_opt
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok());

    let embed_key_bytes = embed_key_b64.and_then(|k| {
        use base64::Engine;
        base64::engine::general_purpose::STANDARD.decode(k).ok()
    });

    let model_version = model_version_opt
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "v1.0-yunet".to_string());

    info!("Membuka database SQLite pada: {}", db_path);
    let db = EdgeDb::init(&db_path).await?;

    let worker = SyncWorker::new(db, api_url, api_token, embed_key_bytes, model_version)?;

    // Beritahu systemd bahwa daemon telah siap (Type=notify)
    engine_sync::notify_systemd("READY=1\n");

    let worker_handle = tokio::spawn(async move {
        worker.run_loop().await;
    });

    info!("Daemon aktif. Menjalankan jadwal PRD (Outbox: 15s, Templates: 60s, Heartbeat: 60s). Tekan Ctrl+C untuk berhenti.");

    tokio::select! {
        _ = worker_handle => {
            warn!("Worker task berhenti.");
        }
        _ = tokio::signal::ctrl_c() => {
            info!("Menerima Ctrl+C, mematikan daemon secara tertib...");
        }
    }

    Ok(())
}
