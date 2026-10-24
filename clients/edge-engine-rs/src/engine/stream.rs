use anyhow::Result;
use bytes::Bytes;
use std::collections::HashMap;
use std::env;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};
use tracing::{debug, error, info, warn};

use crate::{server, vision};
use crate::core::{models::OutboxRecord, EdgeDb};
use crate::hardware::{
    detect_rfid_device, ArduinoBridge, FramebufferDisplay, LinuxGpio, RfidTapEvent,
    SerialRfidReader, TftRleStreamer,
};
use crate::server::{AttendanceLogItem, FaceBoxData, FrameHub, OverlayHub, OverlayState};
use crate::vision::{FaceDetector, FaceRecognizer, LivenessDetector, VisionMetricsAggregator};

use crate::vision::TrackedStudent;
use super::utils::{find_model_path, model_search_dirs, wait_for_shutdown_signal};

#[allow(clippy::too_many_arguments)]
pub async fn run_stream(
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
    cooldown_opt: Option<u64>,
    liveness_margin_opt: Option<f32>,
    confirm_frames_opt: Option<u32>,
    min_face_size_opt: Option<f32>,
    arduino_port_opt: Option<String>,
    arduino_baud_opt: Option<u32>,
    arduino_stream_opt: bool,
    framebuffer_opt: Option<String>,
    rfid_port_opt: Option<String>,
    gpio_green_opt: Option<u32>,
    gpio_red_opt: Option<u32>,
    gpio_buzzer_opt: Option<u32>,
) -> Result<()> {
    // 0. Muat konfigurasi edge yang tersimpan di disk (edge_settings.json) atau lakukan first-boot self-config
    let settings_path = server::control::resolve_settings_path(&db_path);
    let persisted_settings = server::control::ensure_first_boot_config(&settings_path)
        .unwrap_or_else(|_| server::control::load_settings(&settings_path));
    tracing::info!("Konfigurasi edge dimuat dari: {}", settings_path.display());

    // Terapkan override dari edge_settings.json jika CLI memakai nilai bawaan / auto
    let device = if (device == "/dev/video0" || device == "0" || device == "auto") && !persisted_settings.camera.source.trim().is_empty() {
        persisted_settings.camera.source.clone()
    } else if device == "auto" {
        crate::vision::auto_select_camera().unwrap_or_else(|| {
            #[cfg(target_os = "linux")]
            { "/dev/video0".to_string() }
            #[cfg(not(target_os = "linux"))]
            { "0".to_string() }
        })
    } else {
        device
    };

    let (width, height) = if width == 640 && height == 480 {
        if let Some((w_s, h_s)) = persisted_settings.camera.resolution.split_once('x') {
            if let (Ok(w), Ok(h)) = (w_s.trim().parse::<u32>(), h_s.trim().parse::<u32>()) {
                (w, h)
            } else {
                (width, height)
            }
        } else {
            (width, height)
        }
    } else {
        (width, height)
    };

    let fps_cap = persisted_settings.camera.fps_cap.clamp(5, 60);

    let cooldown_secs = cooldown_opt
        .or_else(|| {
            env::var("RECOGNITION_COOLDOWN_SEC")
                .ok()
                .and_then(|v| v.parse::<u64>().ok())
        })
        .unwrap_or(300);

    let liveness_margin = liveness_margin_opt
        .or_else(|| {
            env::var("LIVENESS_PASS_MARGIN")
                .ok()
                .and_then(|v| v.parse::<f32>().ok())
        })
        .unwrap_or(vision::DEFAULT_PASS_MARGIN);

    let confirm_frames = confirm_frames_opt
        .or_else(|| {
            env::var("CONFIRM_FRAMES")
                .ok()
                .and_then(|v| v.parse::<u32>().ok())
        })
        .unwrap_or(3);

    let min_face_size = min_face_size_opt
        .or_else(|| {
            env::var("MIN_FACE_SIZE")
                .ok()
                .and_then(|v| v.parse::<f32>().ok())
        })
        .unwrap_or(80.0);

    let resolved_arduino_port = arduino_port_opt
        .or_else(|| env::var("ARDUINO_PORT").ok())
        .or_else(|| env::var("SERIAL_BRIDGE_PORT").ok());

    let resolved_arduino_baud = arduino_baud_opt
        .or_else(|| {
            env::var("ARDUINO_BAUD")
                .ok()
                .and_then(|v| v.parse::<u32>().ok())
        })
        .unwrap_or(115200);

    let resolved_arduino_stream = arduino_stream_opt
        || env::var("ARDUINO_STREAM")
            .map(|v| v == "1" || v == "true")
            .unwrap_or(false);

    let resolved_framebuffer = framebuffer_opt.or_else(|| env::var("FRAMEBUFFER_DEV").ok());

    let resolved_rfid_port = rfid_port_opt
        .or_else(|| env::var("RFID_PORT").ok())
        .filter(|p| !p.trim().is_empty() && p != "none" && p != "off")
        .or_else(|| {
            detect_rfid_device().filter(|dev| {
                if let Some(ref ard) = resolved_arduino_port {
                    dev != ard
                } else {
                    true
                }
            })
        });

    let env_green = env::var("GPIO_GREEN_PIN").ok().and_then(|v| v.parse::<u32>().ok());
    let env_red = env::var("GPIO_RED_PIN").ok().and_then(|v| v.parse::<u32>().ok());
    let env_buzzer = env::var("GPIO_BUZZER_PIN").ok().and_then(|v| v.parse::<u32>().ok());
    let env_active_high = env::var("GPIO_ACTIVE_HIGH").ok().map(|v| v == "1" || v == "true");

    let (resolved_gpio_green, resolved_gpio_red, resolved_gpio_buzzer, resolved_gpio_active_high) =
        server::control::resolve_gpio_pins(
            gpio_green_opt,
            gpio_red_opt,
            gpio_buzzer_opt,
            env_green,
            env_red,
            env_buzzer,
            env_active_high,
            &persisted_settings.gpio,
        );

    println!("--------------------------------------------------");
    println!(" Smart Absensi MJPEG Stream & AI Engine (Rust)");
    println!(" Device         : {}", device);
    println!(" Resolusi       : {}x{}", width, height);
    println!(" Server         : http://0.0.0.0:{}", port);
    println!(" Stream         : http://<BOARD_IP>:{}/stream", port);
    println!(" Snapshot       : http://<BOARD_IP>:{}/snapshot.jpg", port);
    println!(" AI Vision      : {}", if vision_enabled { "AKTIF" } else { "NONAKTIF" });
    println!(" Liveness       : {} (Margin: {:.3})", if liveness_enabled { "AKTIF" } else { "NONAKTIF" }, liveness_margin);
    println!(" Konfirmasi     : {} frame berturut-turut (dengan Spoof Veto)", confirm_frames);
    println!(" Min Face Size  : {:.0}px", min_face_size);
    println!(" Cooldown       : {} detik", cooldown_secs);
    if let Some(ref fb) = resolved_framebuffer {
        println!(" Framebuffer    : {}", fb);
    }
    if let Some(ref ard) = resolved_arduino_port {
        println!(" Arduino TFT    : {} ({} baud, Stream: {})", ard, resolved_arduino_baud, resolved_arduino_stream);
    }
    if let Some(ref rfid) = resolved_rfid_port {
        println!(" RFID Reader    : {}", rfid);
    }
    if resolved_gpio_green.is_some() || resolved_gpio_red.is_some() || resolved_gpio_buzzer.is_some() {
        println!(
            " GPIO Sysfs     : Green={:?}, Red={:?}, Buzzer={:?} (Active-{})",
            resolved_gpio_green,
            resolved_gpio_red,
            resolved_gpio_buzzer,
            if resolved_gpio_active_high { "HIGH" } else { "LOW" }
        );
    }
    println!("--------------------------------------------------");

    let gpio = Arc::new(LinuxGpio::new(
        resolved_gpio_green,
        resolved_gpio_red,
        resolved_gpio_buzzer,
        resolved_gpio_active_high,
    ));

    let arduino_bridge: Option<Arc<ArduinoBridge>> = if let Some(ref port) = resolved_arduino_port {
        let bridge = ArduinoBridge::new(port, resolved_arduino_baud);
        if bridge.connect() {
            let _ = bridge.standby(Some("SILAKAN HADAP KAMERA"));
            Some(Arc::new(bridge))
        } else {
            None
        }
    } else {
        None
    };

    let fb_display: Arc<std::sync::Mutex<Option<FramebufferDisplay>>> = Arc::new(std::sync::Mutex::new(
        if let Some(ref fb_dev) = resolved_framebuffer {
            match FramebufferDisplay::open(fb_dev, 320, 240) {
                Ok(fb) => Some(fb),
                Err(e) => {
                    warn!("Gagal membuka Framebuffer {}: {:#}", fb_dev, e);
                    None
                }
            }
        } else {
            None
        }
    ));

    let tft_streamer: Option<Arc<std::sync::Mutex<TftRleStreamer>>> = if resolved_arduino_stream {
        if let Some(ref port) = resolved_arduino_port {
            let mut streamer = TftRleStreamer::new(port, resolved_arduino_baud);
            match streamer.connect() {
                Ok(()) => Some(Arc::new(std::sync::Mutex::new(streamer))),
                Err(e) => {
                    warn!("Gagal menghubungkan TFT RLE Streamer: {:#}", e);
                    None
                }
            }
        } else {
            None
        }
    } else {
        None
    };

    let hub = FrameHub::new();
    hub.set_mirror(persisted_settings.camera.mirror_preview);
    let overlay_hub = OverlayHub::new();
    let overlay_hub_ai = overlay_hub.clone();
    let running = Arc::new(AtomicBool::new(true));
    let running_clone = running.clone();

    let recent_history = Arc::new(std::sync::Mutex::new(Vec::<AttendanceLogItem>::new()));
    let recent_history_ai = recent_history.clone();

    let cam_fps_shared = Arc::new(AtomicU32::new(300)); // 30.0 * 10
    let cam_fps_shared_ai = cam_fps_shared.clone();

    let camera_warning = Arc::new(std::sync::RwLock::new(None::<String>));
    let camera_warning_ai = camera_warning.clone();

    // 1. Inisialisasi Database SQLite
    let embed_key_bytes = embed_key_opt
        .or_else(|| env::var("ENROLLMENT_EMBED_KEY").ok())
        .and_then(|k| {
            use base64::Engine;
            base64::engine::general_purpose::STANDARD.decode(k).ok()
        });

    let model_version = model_version_opt
        .clone()
        .or_else(|| env::var("MODEL_VERSION").ok())
        .unwrap_or_else(|| "3d9f1f77896fb3d1".to_string());

    let db = Arc::new(EdgeDb::init(&db_path).await?);
    let initial_templates = match db.load_templates(embed_key_bytes.as_deref(), &model_version).await {
        Ok(t) => t,
        Err(e) => {
            eprintln!("[FATAL ERROR] Gagal memuat template wajah dari database ({:?}): {:#}", db_path, e);
            eprintln!("[FATAL ERROR] Engine dihentikan untuk mencegah kegagalan pengenalan massal pada pintu gerbang.");
            return Err(e);
        }
    };
    println!("[Database] Memuat {} template wajah aktif.", initial_templates.len());
    if initial_templates.is_empty() {
        if embed_key_bytes.is_none() {
            println!("[Database] Catatan: ENROLLMENT_EMBED_KEY tidak terdeteksi. Template wajah terenkripsi tidak dapat dimuat.");
        } else {
            println!("[Database] Informasi: Belum ada template wajah terdaftar di database lokal.");
        }
    }
    let templates = Arc::new(std::sync::RwLock::new(Arc::new(initial_templates)));

    // Inisialisasi riwayat cooldown dari SQLite agar tidak ter-reset saat engine restart
    let mut initial_cooldowns: HashMap<String, Instant> = HashMap::new();
    if let Ok(recent_attendances) = db.get_recent_student_attendances().await {
        let now_utc = chrono::Utc::now();
        for (student_id, captured_at_str) in recent_attendances {
            let captured_utc_opt = if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(&captured_at_str) {
                Some(dt.with_timezone(&chrono::Utc))
            } else if let Ok(naive) = chrono::NaiveDateTime::parse_from_str(&captured_at_str, "%Y-%m-%d %H:%M:%S") {
                let now_naive = chrono::Local::now().naive_local();
                let elapsed = (now_naive - naive).num_seconds();
                if elapsed >= 0 && (elapsed as u64) < cooldown_secs {
                    let elapsed_u64 = elapsed as u64;
                    if let Some(past_instant) = Instant::now().checked_sub(Duration::from_secs(elapsed_u64)) {
                        initial_cooldowns.insert(student_id.clone(), past_instant);
                    }
                }
                None
            } else {
                None
            };

            if let Some(captured_utc) = captured_utc_opt {
                let elapsed = (now_utc - captured_utc).num_seconds();
                if elapsed >= 0 && (elapsed as u64) < cooldown_secs {
                    let elapsed_u64 = elapsed as u64;
                    if let Some(past_instant) = Instant::now().checked_sub(Duration::from_secs(elapsed_u64)) {
                        initial_cooldowns.insert(student_id.clone(), past_instant);
                    }
                }
            }
        }
        if !initial_cooldowns.is_empty() {
            println!(
                "[Cooldown] Memulihkan {} siswa dalam masa cooldown aktif dari SQLite (mencegah duplikat pasca-restart).",
                initial_cooldowns.len()
            );
        }
    }

    // Inisialisasi Reader RFID Fisik jika terpasang (USB / UART Serial)
    if let Some(ref rfid_p) = resolved_rfid_port {
        let rfid_reader = SerialRfidReader::new(rfid_p, 9600);
        let (tx_rfid, mut rx_rfid) = tokio::sync::mpsc::channel::<RfidTapEvent>(16);
        if let Err(e) = rfid_reader.start_reading(tx_rfid) {
            warn!("Gagal mengaktifkan Serial RFID Reader di [{}]: {:#}", rfid_p, e);
        } else {
            let db_rfid = db.clone();
            let templates_rfid = templates.clone();
            let gpio_rfid = gpio.clone();
            let arduino_rfid = arduino_bridge.clone();
            let recent_history_rfid = recent_history.clone();
            let cooldown_secs_rfid = cooldown_secs;

            tokio::spawn(async move {
                let mut rfid_cooldowns: HashMap<String, Instant> = HashMap::new();
                info!("Worker Listener Kartu RFID aktif di background.");

                while let Some(evt) = rx_rfid.recv().await {
                    let uid = evt.card_uid;
                    let now = Instant::now();
                    if let Some(&last) = rfid_cooldowns.get(&uid) {
                        if now.duration_since(last).as_secs() < cooldown_secs_rfid {
                            continue;
                        }
                    }
                    rfid_cooldowns.insert(uid.clone(), now);

                    // Cocokkan UID kartu dengan student_id atau nama anggota
                    let current_templates = templates_rfid.read().map(|l| l.clone()).unwrap_or_default();
                    let matched = current_templates.iter().find(|t| t.student_id == uid || t.name.contains(&uid));
                    let (sid, name) = if let Some(t) = matched {
                        (t.student_id.clone(), t.name.clone())
                    } else {
                        (uid.clone(), format!("Kartu {}", uid))
                    };

                    let now_dt = chrono::Local::now();
                    let display_time = now_dt.format("%Y-%m-%d %H:%M:%S").to_string();
                    let utc_captured_at = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ").to_string();
                    let att_uuid = uuid::Uuid::new_v4().to_string();

                    info!("[PRESENSI RFID DITERIMA] {} (ID: {})", name, sid);

                    // Beri feedback audio/visual ke hardware
                    gpio_rfid.trigger_success();
                    if let Some(ref ard) = arduino_rfid {
                        let _ = ard.recognized(&name, &display_time);
                    }

                    if let Ok(mut hist) = recent_history_rfid.lock() {
                        hist.insert(0, AttendanceLogItem {
                            student_id: sid.clone(),
                            name: name.clone(),
                            captured_at: display_time,
                            direction: "in".to_string(),
                        });
                        if hist.len() > 20 {
                            hist.truncate(20);
                        }
                    }

                    let record = OutboxRecord {
                        id: att_uuid,
                        student_id: sid,
                        captured_at: utc_captured_at,
                        direction: "in".to_string(),
                        score: 1.0,
                        liveness_score: 1.0,
                        time_source: "rfid".to_string(),
                        synced: 0,
                    };
                    if let Err(e) = db_rfid.insert_attendance(&record).await {
                        error!("Gagal menyimpan absensi kartu RFID ke outbox: {:#}", e);
                    }
                }
            });
        }
    }

    // 2. Siapkan Kanal Pipeline AI Terpisah (kapasitas 1, non-blocking)
    #[allow(dead_code)]
    enum AiFrame {
        Rgb { data: Bytes, width: u32, height: u32 },
        Jpeg { data: Bytes, width: u32, height: u32 },
    }

    let (tx_frame, rx_frame) = std::sync::mpsc::sync_channel::<AiFrame>(1);

    if vision_enabled {
        let base_dirs = model_search_dirs(models_dir_opt.as_deref());

        let detector_path = find_model_path(&base_dirs, &["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"]);
        let recognizer_path = find_model_path(&base_dirs, &["recognizer.onnx", "sface.onnx", "arcface.onnx"]);
        let liveness_path = find_model_path(&base_dirs, &["liveness.onnx", "minifasnet.onnx"]);

        if let (Some(det_p), Some(rec_p)) = (detector_path, recognizer_path) {
            info!("Memuat model AI: Detector: {:?}, Recognizer: {:?}", det_p, rec_p);
            match vision::init_onnx_runtime(ort_path_opt.as_deref().map(Path::new)) {
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
                            match LivenessDetector::load(&liv_p) {
                                Ok(mut ld) => {
                                    ld.pass_margin = liveness_margin;
                                    Some(ld)
                                }
                                Err(e) => {
                                    warn!("Gagal memuat liveness.onnx: {:#}", e);
                                    None
                                }
                            }
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
                            let templates_ai_holder = templates.clone();
                            let db_ai = db.clone();
                            let embed_key_bytes_ai = embed_key_bytes.clone();
                            let model_version_ai = model_version.clone();
                            let gpio_ai = gpio.clone();
                            let arduino_ai = arduino_bridge.clone();
                            let mut liveness_ai = liveness_res;
                            let tokio_handle = tokio::runtime::Handle::current();
                            let engine_start = Instant::now();
                            let initial_cooldowns_ai = initial_cooldowns;
                            let confirm_frames_ai = confirm_frames;
                            let min_face_size_ai = min_face_size;
                            let cooldown_secs_ai = cooldown_secs;
                            let fps_cap_ai = fps_cap;
                            let ambiguity_margin_ai = env::var("AMBIGUITY_MARGIN")
                                .ok()
                                .and_then(|v| v.parse::<f32>().ok())
                                .unwrap_or(0.05);
                            let camera_warning_ai = camera_warning_ai.clone();
                            let dark_luma_thresh = persisted_settings.camera.dark_luma_threshold;
                            let static_var_thresh = persisted_settings.camera.static_variance_threshold;

                            std::thread::Builder::new()
                                .name("ai-vision-worker".to_string())
                                .spawn(move || {
                                    info!("AI Vision Worker thread aktif (decoupled).");
                                    let mut cooldown_map: HashMap<String, Instant> = initial_cooldowns_ai;
                                    let mut tracking_map: HashMap<String, TrackedStudent> = HashMap::new();
                                    let mut vision_metrics = VisionMetricsAggregator::new();
                                    if vision_metrics.is_enabled() {
                                        info!("Vision metrics logging aktif (EDGE_VISION_METRICS=1), agregasi per 60 detik.");
                                    }

                                    let mut last_sig_check = Instant::now();
                                    let initial_sig = tokio_handle.block_on(async { db_ai.get_templates_signature().await.unwrap_or((0, 0)) });
                                    let sig_tracker = Arc::new(std::sync::Mutex::new(initial_sig));

                                    // Gate deteksi gerakan berbasis frame-difference (32x24 grid = 768 titik sampel luma)
                                    // Mengurangi latensi deteksi dari 300ms ke ~33ms sekaligus menghemat beban inferensi CPU saat idle
                                    const MOTION_GRID_W: usize = 32;
                                    const MOTION_GRID_H: usize = 24;
                                    const MOTION_POINTS: usize = MOTION_GRID_W * MOTION_GRID_H;
                                    let mut prev_luma = [0u8; MOTION_POINTS];
                                    let mut has_prev_luma = false;
                                    let mut empty_streak: u32 = 0;
                                    let mut last_detect_time = Instant::now();
                                    let mut ai_frame_count: u64 = 0;
                                    let mut is_dark_or_static = false;

                                    while running_ai.load(Ordering::Relaxed) {
                                        // Polling penanda versi templates di SQLite setiap 3 detik untuk hot-reload tanpa restart proses
                                        if last_sig_check.elapsed() >= Duration::from_secs(3) {
                                            last_sig_check = Instant::now();
                                            let db_reload = db_ai.clone();
                                            let key_reload = embed_key_bytes_ai.clone();
                                            let model_v_reload = model_version_ai.clone();
                                            let holder_reload = templates_ai_holder.clone();
                                            let tracker_reload = sig_tracker.clone();

                                            tokio_handle.spawn(async move {
                                                if let Ok(new_sig) = db_reload.get_templates_signature().await {
                                                    let is_changed = {
                                                        let current_sig = tracker_reload.lock().unwrap();
                                                        new_sig != *current_sig
                                                    };
                                                    if is_changed {
                                                        match db_reload.load_templates(key_reload.as_deref(), &model_v_reload).await {
                                                            Ok(fresh) => {
                                                                let count = fresh.len();
                                                                if let Ok(mut lock) = holder_reload.write() {
                                                                    *lock = Arc::new(fresh);
                                                                    *tracker_reload.lock().unwrap() = new_sig;
                                                                    info!("[TEMPLATES HOT-RELOAD] Database wajah di RAM berhasil diperbarui dari SQLite: {} template aktif (signature: count={}, max_version={}).", count, new_sig.0, new_sig.1);
                                                                }
                                                            }
                                                            Err(e) => {
                                                                warn!("Gagal memuat ulang templates dari SQLite: {:#}", e);
                                                            }
                                                        }
                                                    }
                                                }
                                            });
                                        }

                                        let templates_ai = templates_ai_holder.read().map(|l| l.clone()).unwrap_or_else(|_| Arc::new(Vec::new()));
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

                                        ai_frame_count = ai_frame_count.saturating_add(1);
                                        if ai_frame_count == 30 || (ai_frame_count > 30 && ai_frame_count % 150 == 0) {
                                            let (mean_luma, variance) = crate::vision::calculate_luma_and_variance(&frame_rgb);
                                            if ai_frame_count == 30 {
                                                info!("Baseline kualitas kamera (Frame 30): luma={:.1}, variansi={:.1}", mean_luma, variance);
                                            }
                                            if mean_luma < dark_luma_thresh || variance < static_var_thresh {
                                                let msg = format!(
                                                    "Kamera gelap / shutter tertutup (luma: {:.1} < {:.1}, variansi: {:.1} < {:.1})",
                                                    mean_luma, dark_luma_thresh, variance, static_var_thresh
                                                );
                                                if !is_dark_or_static {
                                                    warn!(
                                                        "Kamera tidak menghasilkan gambar valid (luma rata-rata {:.1}, variansi {:.1}) setelah {} frame AI. \
                                                         Kemungkinan kamera IR, shutter kamera tertutup, atau frame statis.",
                                                        mean_luma, variance, ai_frame_count
                                                    );
                                                }
                                                is_dark_or_static = true;
                                                if let Ok(mut lock) = camera_warning_ai.write() {
                                                    *lock = Some(msg);
                                                }
                                            } else {
                                                if is_dark_or_static {
                                                    info!("Kamera kembali menghasilkan gambar normal (luma rata-rata {:.1}, variansi {:.1}).", mean_luma, variance);
                                                    if let Ok(mut lock) = camera_warning_ai.write() {
                                                        *lock = None;
                                                    }
                                                }
                                                is_dark_or_static = false;
                                            }
                                        }

                                        // Evaluasi gerakan ringan (frame-diff) sebelum menjalankan inferensi neural network
                                        let mut curr_luma = [0u8; MOTION_POINTS];
                                        let step_x = (w as usize / MOTION_GRID_W).max(1);
                                        let step_y = (h as usize / MOTION_GRID_H).max(1);
                                        let mut p = 0;
                                        for gy in 0..MOTION_GRID_H {
                                            let y = (gy * step_y).min(h as usize - 1);
                                            let row_offset = y * (w as usize) * 3;
                                            for gx in 0..MOTION_GRID_W {
                                                let x = (gx * step_x).min(w as usize - 1);
                                                let idx = row_offset + x * 3;
                                                if idx + 2 < frame_rgb.len() {
                                                    curr_luma[p] = ((frame_rgb[idx] as u16 + (frame_rgb[idx + 1] as u16 * 2) + frame_rgb[idx + 2] as u16) >> 2) as u8;
                                                }
                                                p += 1;
                                            }
                                        }

                                        let mut motion_ratio = 1.0f32;
                                        if has_prev_luma {
                                            let mut changed_points = 0;
                                            for i in 0..MOTION_POINTS {
                                                if (curr_luma[i] as i16 - prev_luma[i] as i16).abs() > 12 {
                                                    changed_points += 1;
                                                }
                                            }
                                            motion_ratio = changed_points as f32 / MOTION_POINTS as f32;
                                        }
                                        prev_luma = curr_luma;
                                        has_prev_luma = true;

                                        let motion_detected = motion_ratio > 0.02;
                                        // Syarat inferensi deteksi wajah:
                                        // 1. Ada pergerakan signifikan (motion_detected), ATAU
                                        // 2. Wajah baru saja terdeteksi sebelumnya / masa pelacakan (empty_streak < 5), ATAU
                                        // 3. Heartbeat berkala (350ms) untuk mengeliminasi edge-case orang muncul tanpa gerakan signifikan
                                        let heartbeat_ms = if empty_streak > 15 { 350 } else { 400 };
                                        let should_detect = motion_detected || empty_streak < 5 || last_detect_time.elapsed() >= Duration::from_millis(heartbeat_ms);

                                        if !should_detect {
                                            vision_metrics.record_detection_skipped();
                                            // Pemandangan statis tanpa objek wajah: lewati inferensi YuNet (~25-60ms compute)
                                            // Tidur singkat 33ms (~30 FPS) agar tetap responsif seketika terjadi pergerakan
                                            std::thread::sleep(Duration::from_millis(33));
                                            continue;
                                        }

                                        last_detect_time = Instant::now();
                                        let start_ai = Instant::now();

                                        let faces = match detector.detect(&frame_rgb, w as usize, h as usize, (w * 3) as usize) {
                                             Ok(f) => f,
                                             Err(e) => {
                                                 warn!("Face detector error: {:#}", e);
                                                 continue;
                                             }
                                         };

                                        vision_metrics.record_detection_run(start_ai.elapsed());
                                        vision_metrics.record_frame(&frame_rgb, w as usize, h as usize, (w * 3) as usize, &faces);

                                         let mut current_boxes = Vec::new();

                                         // Supresi spasial: Jika detektor mengeluarkan proposal ganda untuk wajah fisik yang sama,
                                         // pilih proposal dengan deteksi tertinggi dan buang proposal yang bertumpukan.
                                         let mut sorted_faces: Vec<&vision::FaceDetection> = faces.iter().collect();
                                         sorted_faces.sort_by(|a, b| b.score.partial_cmp(&a.score).unwrap_or(std::cmp::Ordering::Equal));

                                         let mut filtered_faces: Vec<&vision::FaceDetection> = Vec::new();
                                         for f in sorted_faces {
                                             let fcx = f.bbox[0] + f.bbox[2] / 2.0;
                                             let fcy = f.bbox[1] + f.bbox[3] / 2.0;
                                             let mut is_overlap = false;
                                             for existing in &filtered_faces {
                                                 let ecx = existing.bbox[0] + existing.bbox[2] / 2.0;
                                                 let ecy = existing.bbox[1] + existing.bbox[3] / 2.0;
                                                 let dist = ((fcx - ecx).powi(2) + (fcy - ecy).powi(2)).sqrt();
                                                 let min_dim = f.bbox[2].min(f.bbox[3]).min(existing.bbox[2]).min(existing.bbox[3]);
                                                 if dist < (min_dim * 0.45) {
                                                     is_overlap = true;
                                                     break;
                                                 }
                                             }
                                             if !is_overlap {
                                                 filtered_faces.push(f);
                                             }
                                         }

                                         for face in filtered_faces {
                                             let bx = face.bbox[0];
                                             let by = face.bbox[1];
                                             let bw = face.bbox[2];
                                             let bh = face.bbox[3];
                                             let cx = bx + bw / 2.0;
                                             let cy = by + bh / 2.0;

                                             // 1. Filter ukuran wajah minimum (mengabaikan wajah/layar HP yang terlalu kecil)
                                             if bw < min_face_size_ai || bh < min_face_size_ai {
                                                 current_boxes.push(FaceBoxData {
                                                     x: bx as i32,
                                                     y: by as i32,
                                                     width: bw as i32,
                                                     height: bh as i32,
                                                     color: "#6b7280".to_string(), // Gray: Too Small
                                                     score_text: format!("size:{:.0}px", bw.min(bh)),
                                                     name_text: "id:TooSmall".to_string(),
                                                 });
                                                 continue;
                                             }

                                             // 2. Evaluasi Liveness
                                             let mut is_real = true;
                                             let mut liveness_prob = 1.0f32;
                                             let mut logit_margin = 0.0f32;
                                             if let Some(ref mut liveness) = liveness_ai {
                                                 match liveness.evaluate(&frame_rgb, w as usize, h as usize, (w * 3) as usize, &face.bbox) {
                                                     Ok(res) => {
                                                         liveness_prob = res.prob_real;
                                                         logit_margin = res.logit_diff;
                                                         is_real = res.is_real;
                                                         if !res.is_real {
                                                             println!(
                                                                 "[SPOOF DITOLAK] Wajah ditolak liveness: margin={:+.2} (prob={:.2}, ambang={:.2})",
                                                                 res.logit_diff, res.prob_real, liveness.pass_margin
                                                             );
                                                         }
                                                     }
                                                     Err(e) => {
                                                         debug!("Gagal evaluasi liveness: {:#}", e);
                                                     }
                                                 }
                                             }

                                             if !is_real {
                                                 // Feedback hardware: tolak spoofing
                                                 gpio_ai.trigger_rejected();
                                                 if let Some(ref ard) = arduino_ai {
                                                     let _ = ard.spoof();
                                                 }

                                                 // VETO SPOOF: Jika terdeteksi spoof, batalkan/reset konfirmasi siswa mana pun di dekat koordinat ini
                                                 for (sid, track) in tracking_map.iter_mut() {
                                                     let dist = ((cx - track.last_center.0).powi(2) + (cy - track.last_center.1).powi(2)).sqrt();
                                                     if dist < 150.0 && track.consecutive_frames > 0 {
                                                         println!(
                                                             "[VETO SPOOF] Spoof terdeteksi di ({:.0}, {:.0})! Reset konfirmasi siswa {} (sebelumnya {}/{} frame)",
                                                             cx, cy, sid, track.consecutive_frames, confirm_frames_ai
                                                         );
                                                         track.consecutive_frames = 0;
                                                     }
                                                 }

                                                 current_boxes.push(FaceBoxData {
                                                     x: bx as i32,
                                                     y: by as i32,
                                                     width: bw as i32,
                                                     height: bh as i32,
                                                     color: "#eab308".to_string(), // Yellow: Spoof
                                                     score_text: format!("sc:{:.2} m:{:+.1}", face.score, logit_margin),
                                                     name_text: "id:SPOOF".to_string(),
                                                 });
                                                 continue;
                                             }

                                             // 3. Align wajah 112x112 NCHW
                                             let aligned_tensor = match vision::align_face_to_tensor_nchw(
                                                 &frame_rgb,
                                                 w as usize,
                                                 h as usize,
                                                 (w * 3) as usize,
                                                 &face.landmarks,
                                             ) {
                                                 Some(t) => t,
                                                 None => continue,
                                             };

                                             // 4. Ekstraksi 512-d ArcFace embedding
                                             let embedding = match recognizer.extract_embedding(&aligned_tensor) {
                                                 Ok(e) => e,
                                                 Err(e) => {
                                                     warn!("Feature extractor error: {:#}", e);
                                                     continue;
                                                 }
                                             };

                                             // 5. Pencocokan Cosine Similarity (Argmax + Ambiguity Margin Check)
                                             let mut top_1: Option<(&str, &str, f32)> = None;
                                             let mut top_2: Option<(&str, &str, f32)> = None;

                                             for t in templates_ai.iter() {
                                                 let sim = FaceRecognizer::cosine_similarity(&embedding, &t.embedding);
                                                 if sim >= similarity_threshold {
                                                     if let Some((top_id, _, top_sim)) = top_1 {
                                                         if sim > top_sim {
                                                             if top_id != t.student_id.as_str() {
                                                                 top_2 = top_1;
                                                             }
                                                             top_1 = Some((&t.student_id, &t.name, sim));
                                                         } else if t.student_id.as_str() != top_id {
                                                             if let Some((_, _, second_sim)) = top_2 {
                                                                 if sim > second_sim {
                                                                     top_2 = Some((&t.student_id, &t.name, sim));
                                                                 }
                                                             } else {
                                                                 top_2 = Some((&t.student_id, &t.name, sim));
                                                             }
                                                         }
                                                     } else {
                                                         top_1 = Some((&t.student_id, &t.name, sim));
                                                     }
                                                 }
                                             }

                                             // Cek ambiguitas jika dua siswa berbeda memiliki skor di atas threshold dengan selisih sangat tipis
                                             let is_ambiguous = match (top_1, top_2) {
                                                 (Some((id1, _, sim1)), Some((id2, _, sim2))) if id1 != id2 => {
                                                     (sim1 - sim2) < ambiguity_margin_ai
                                                 }
                                                 _ => false,
                                             };

                                             let best_student = if is_ambiguous {
                                                 if let (Some((id1, name1, sim1)), Some((id2, name2, sim2))) = (top_1, top_2) {
                                                     println!(
                                                         "[IDENTITAS AMBIGU] Wajah cocok dengan 2 identitas: {} ({}) sim={:.2} vs {} ({}) sim={:.2} | Selisih {:.3} < margin {:.3}. Menolak absensi.",
                                                         name1, id1, sim1, name2, id2, sim2, sim1 - sim2, ambiguity_margin_ai
                                                     );
                                                 }
                                                 None
                                             } else {
                                                 top_1
                                             };

                                             if let Some((student_id, name, score)) = best_student {
                                                 let now = Instant::now();

                                                 // 6. Update tracking konfirmasi multi-frame
                                                 let track = tracking_map.entry(student_id.to_string()).or_insert_with(|| TrackedStudent {
                                                     consecutive_frames: 0,
                                                     last_center: (cx, cy),
                                                     last_seen: now,
                                                 });

                                                 let dt = now.duration_since(track.last_seen);
                                                 let dist = ((cx - track.last_center.0).powi(2) + (cy - track.last_center.1).powi(2)).sqrt();
                                                 if dt.as_millis() <= 2000 && dist <= 150.0 {
                                                     track.consecutive_frames += 1;
                                                 } else {
                                                     track.consecutive_frames = 1;
                                                 }
                                                 track.last_center = (cx, cy);
                                                 track.last_seen = now;

                                                 let count = track.consecutive_frames;
                                                 let is_confirmed = count >= confirm_frames_ai;

                                                 let (box_color, name_label) = if is_confirmed {
                                                     ("#22c55e".to_string(), format!("id:{}", name)) // Green: Confirmed
                                                 } else {
                                                     ("#38bdf8".to_string(), format!("id:{} [{}/{}]", name, count, confirm_frames_ai)) // Sky blue: Verifying
                                                 };

                                                 current_boxes.push(FaceBoxData {
                                                     x: bx as i32,
                                                     y: by as i32,
                                                     width: bw as i32,
                                                     height: bh as i32,
                                                     color: box_color,
                                                     score_text: format!("sc:{:.2} m:{:+.1}", score, logit_margin),
                                                     name_text: name_label,
                                                 });

                                                 if !is_confirmed {
                                                     println!(
                                                         "⏳ [VERIFIKASI] {} ({}/{} frame) | Kemiripan: {:.1}% | Margin: {:+.2} (prob: {:.2})",
                                                         name, count, confirm_frames_ai, score * 100.0, logit_margin, liveness_prob
                                                     );
                                                 } else {
                                                     let on_cooldown = if let Some(&last_time) = cooldown_map.get(student_id) {
                                                         now.duration_since(last_time).as_secs() < cooldown_secs_ai
                                                     } else {
                                                         false
                                                     };

                                                     if !on_cooldown {
                                                         cooldown_map.insert(student_id.to_string(), now);
                                                         let now_dt = chrono::Local::now();
                                                         let display_time = now_dt.format("%Y-%m-%d %H:%M:%S").to_string();
                                                         let utc_captured_at = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ").to_string();
                                                         let att_uuid = uuid::Uuid::new_v4().to_string();

                                                         println!(
                                                             "[ABSENSI TERCATAT] {} (ID: {}) | Kemiripan: {:.1}% | Liveness Margin: {:+.2} (prob: {:.2}) | Terkonfirmasi {} frame",
                                                             name, student_id, score * 100.0, logit_margin, liveness_prob, confirm_frames_ai
                                                         );

                                                         // Beri feedback audio/visual ke hardware (2 bip pendek + LED hijau)
                                                         gpio_ai.trigger_success();
                                                         if let Some(ref ard) = arduino_ai {
                                                             let _ = ard.recognized(name, &display_time);
                                                         }

                                                         let log_item = AttendanceLogItem {
                                                             student_id: student_id.to_string(),
                                                             name: name.to_string(),
                                                             captured_at: display_time,
                                                             direction: "in".to_string(),
                                                         };
                                                         if let Ok(mut hist) = recent_history_ai.lock() {
                                                             hist.insert(0, log_item);
                                                             if hist.len() > 20 {
                                                                 hist.truncate(20);
                                                             }
                                                         }

                                                         let record = OutboxRecord {
                                                             id: att_uuid,
                                                             student_id: student_id.to_string(),
                                                             captured_at: utc_captured_at,
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
                                             } else {
                                                 let (score_text, name_text) = if is_ambiguous {
                                                     (
                                                         format!("ambigu m:{:+.1}", logit_margin),
                                                         "id:Unknown (Ambigu)".to_string(),
                                                     )
                                                 } else {
                                                     (
                                                         format!("sc:{:.2} m:{:+.1}", face.score, logit_margin),
                                                         "id:Unknown".to_string(),
                                                     )
                                                 };
                                                 current_boxes.push(FaceBoxData {
                                                     x: bx as i32,
                                                     y: by as i32,
                                                     width: bw as i32,
                                                     height: bh as i32,
                                                     color: "#f97316".to_string(), // Orange: Unknown
                                                     score_text,
                                                     name_text,
                                                 });
                                             }
                                         }

                                        let elapsed_ai = start_ai.elapsed().as_secs_f32().max(0.001);
                                        let ai_fps = 1.0 / elapsed_ai;
                                        let uptime_sec = engine_start.elapsed().as_secs();
                                        let hrs = uptime_sec / 3600;
                                        let mins = (uptime_sec % 3600) / 60;
                                        let secs = uptime_sec % 60;
                                        let cam_fps_val = cam_fps_shared_ai.load(Ordering::Relaxed) as f32 / 10.0;
                                        let status = if is_dark_or_static {
                                            format!("Aktif: {:02}:{:02}:{:02} | Camera: {:.1} FPS | AI: {:.1} FPS | [PERINGATAN: Kamera Gelap / Shutter Tertutup]", hrs, mins, secs, cam_fps_val, ai_fps)
                                        } else {
                                            format!("Aktif: {:02}:{:02}:{:02} | Camera: {:.1} FPS | AI: {:.1} FPS", hrs, mins, secs, cam_fps_val, ai_fps)
                                        };

                                        let history_snapshot = recent_history_ai.lock().map(|h| h.clone()).unwrap_or_default();
                                        overlay_hub_ai.publish(OverlayState {
                                            status,
                                            frame_width: w,
                                            frame_height: h,
                                            faces: current_boxes,
                                            history: history_snapshot,
                                        });

                                        // Pacing termal & adaptif: hindari panas berlebih pada SBC tanpa mengorbankan latensi
                                        if faces.is_empty() {
                                            empty_streak = empty_streak.saturating_add(1);
                                            // Pacing responsif 33ms (bukan lagi sleep kaku 300ms) agar frame berikutnya segera diuji
                                            std::thread::sleep(Duration::from_millis(33));
                                        } else {
                                            empty_streak = 0;
                                            // Pelacakan wajah aktif: dikontrol oleh fps_cap yang tersimpan
                                            let target_frame_ms: u64 = 1000u64 / (fps_cap_ai as u64);
                                            let elapsed_ms = start_ai.elapsed().as_millis() as u64;
                                            let sleep_ms = target_frame_ms.saturating_sub(elapsed_ms);
                                            if sleep_ms > 0 {
                                                std::thread::sleep(Duration::from_millis(sleep_ms));
                                            }
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

    let camera_simulated = Arc::new(std::sync::atomic::AtomicBool::new(false));

    #[cfg(target_os = "linux")]
    {
        let tx_frame_clone = tx_frame.clone();
        let hub_clone = hub.clone();
        let hub_viewers = hub.clone();
        let cam_fps_clone = cam_fps_shared.clone();
        let mut cam_frame_count: u32 = 0;
        let mut last_fps_time = Instant::now();

        let overlay_hub_draw = overlay_hub.clone();
        let fb_display_draw = fb_display.clone();
        let tft_streamer_draw = tft_streamer.clone();
        let has_physical_display = resolved_framebuffer.is_some() || resolved_arduino_stream;
        let mut last_tft_frame = Instant::now();

        vision::camera::linux::spawn_capture_thread_decoupled(
            device.clone(),
            width,
            height,
            fps_cap,
            running_clone,
            move || hub_viewers.has_viewers() || has_physical_display,
            move |jpeg_bytes, _format, _w, _h| {
                // 1. Publikasikan frame JPEG hasil encode ke HTTP streamer (FrameHub)
                hub_clone.publish(Bytes::copy_from_slice(jpeg_bytes));
            },
            Some(move |frame_bytes: &[u8], format: vision::camera::PixelFormat, w: u32, h: u32| {
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
                    vision::camera::PixelFormat::Yuyv => AiFrame::Rgb {
                        data: Bytes::copy_from_slice(frame_bytes),
                        width: w,
                        height: h,
                    },
                    vision::camera::PixelFormat::Mjpeg => AiFrame::Jpeg {
                        data: Bytes::copy_from_slice(frame_bytes),
                        width: w,
                        height: h,
                    },
                };
                tx_frame_clone.try_send(ai_frame).ok();
            }),
            Some(move |rgb: &mut [u8], w: u32, h: u32| {
                let state = overlay_hub_draw.latest();

                // 1. Gambar status banner di pojok kiri atas (Uptime, Camera FPS, AI FPS)
                if !state.status.is_empty() {
                    let status_color = if state.status.contains("PERINGATAN") {
                        (248, 113, 113) // Light red / alert (#f87171)
                    } else {
                        (56, 189, 248) // Sky blue (#38bdf8)
                    };
                    vision::draw_text(
                        rgb,
                        w as usize,
                        h as usize,
                        10,
                        10,
                        &state.status,
                        status_color,
                        Some((0, 0, 0)),
                    );
                }

                for face in &state.faces {
                    let bbox = [face.x, face.y, face.width, face.height];
                    let color = vision::parse_hex_color(&face.color);
                    vision::draw_rect(rgb, w as usize, h as usize, &bbox, color, 2);

                    let score_y = (face.y + face.height - 24).max(0);
                    vision::draw_text(
                        rgb,
                        w as usize,
                        h as usize,
                        face.x + 4,
                        score_y,
                        &face.score_text,
                        color,
                        Some((0, 0, 0)),
                    );

                    let name_y = (face.y + face.height - 10).max(0);
                    vision::draw_text(
                        rgb,
                        w as usize,
                        h as usize,
                        face.x + 4,
                        name_y,
                        &face.name_text,
                        color,
                        Some((0, 0, 0)),
                    );
                }

                // 3. Render ke Framebuffer SBC (/dev/fb0, /dev/fb1) jika aktif
                if let Ok(mut fb_guard) = fb_display_draw.lock() {
                    if let Some(ref mut fb) = *fb_guard {
                        let _ = fb.render_frame(rgb, w, h);
                    }
                }

                // 4. Stream PiP RLE ke Arduino TFT jika aktif (dipace ~10 FPS)
                if let Some(ref tft_st) = tft_streamer_draw {
                    let now = Instant::now();
                    if now.duration_since(last_tft_frame) >= Duration::from_millis(100) {
                        last_tft_frame = now;
                        let rle = TftRleStreamer::encode_frame_rle(rgb, w, h, 80, 60, 160, 120, true);
                        if let Ok(mut st_guard) = tft_st.lock() {
                            let _ = st_guard.send_frame(&rle);
                        }
                    }
                }
            }),
        );
    }

    #[cfg(not(target_os = "linux"))]
    {
        #[cfg(not(feature = "native-camera"))]
        use std::io::Read;

        let tx_frame_clone = tx_frame.clone();
        let hub_mock = hub.clone();
        let running_mock = running_clone.clone();
        let cam_fps_mock = cam_fps_shared.clone();
        let overlay_hub_draw = overlay_hub.clone();
        let camera_simulated_tracker = camera_simulated.clone();

        let cam_idx: u32 = if device.starts_with("/dev/video") {
            device.trim_start_matches("/dev/video").parse().unwrap_or(0)
        } else {
            device.parse().unwrap_or(0)
        };

        #[cfg(not(feature = "native-camera"))]
        let backend_expr = crate::vision::cv2_backend_expr();
        #[cfg(not(feature = "native-camera"))]
        let py_script = format!(
            "import cv2, sys, time\n\
if sys.version_info < (3, 8): sys.exit(2)\n\
backend = {backend}\n\
cap = cv2.VideoCapture({0}, backend)\n\
if not cap.isOpened(): cap = cv2.VideoCapture({0})\n\
if not cap.isOpened(): sys.exit(1)\n\
cap.set(cv2.CAP_PROP_FRAME_WIDTH, {1})\n\
cap.set(cv2.CAP_PROP_FRAME_HEIGHT, {2})\n\
cap.set(cv2.CAP_PROP_FPS, {fps_cap})\n\
ready = False\n\
for _ in range(10):\n\
\x20   ret, frame = cap.read()\n\
\x20   if ret and frame is not None:\n\
\x20       ready = True\n\
\x20       if frame.shape[1] != {1} or frame.shape[0] != {2}:\n\
\x20           frame = cv2.resize(frame, ({1}, {2}))\n\
\x20       rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)\n\
\x20       sys.stdout.buffer.write(rgb.tobytes())\n\
\x20       sys.stdout.buffer.flush()\n\
\x20       break\n\
\x20   time.sleep(0.05)\n\
if not ready:\n\
\x20   cap.release()\n\
\x20   sys.exit(1)\n\
while True:\n\
\x20   ret, frame = cap.read()\n\
\x20   if not ret or frame is None: break\n\
\x20   if frame.shape[1] != {1} or frame.shape[0] != {2}:\n\
\x20       frame = cv2.resize(frame, ({1}, {2}))\n\
\x20   rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)\n\
\x20   sys.stdout.buffer.write(rgb.tobytes())\n\
\x20   sys.stdout.buffer.flush()\n\
cap.release()",
            cam_idx, width, height,
            fps_cap = fps_cap,
            backend = backend_expr
        );

        #[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
        let webcam_active = {
            let (tx_ready, rx_ready) = std::sync::mpsc::channel::<bool>();
            let tx_cam = tx_frame_clone.clone();
            let hub_cam = hub_mock.clone();
            let fb_display_cam = fb_display.clone();
            let tft_streamer_cam = tft_streamer.clone();
            let running_cam = running_mock.clone();
            let cam_fps_cam = cam_fps_mock.clone();

            let _handle = crate::vision::camera_win::spawn_capture_thread_native(
                cam_idx,
                width,
                height,
                fps_cap,
                running_cam,
                tx_ready,
                move |jpeg_bytes, _w, _h| {
                    hub_cam.publish(Bytes::copy_from_slice(jpeg_bytes));
                    cam_fps_cam.fetch_add(1, Ordering::Relaxed);
                },
                Some(move |rgb_bytes: &[u8], w, h| {
                    if let Ok(mut fb_guard) = fb_display_cam.lock() {
                        if let Some(ref mut fb) = *fb_guard {
                            let _ = fb.render_frame(rgb_bytes, w, h);
                        }
                    }
                    if let Some(ref tft_st) = tft_streamer_cam {
                        let rle = crate::hardware::tft_streamer::TftRleStreamer::encode_frame_rle(
                            rgb_bytes, w, h, 0, 0, 160, 120, true,
                        );
                        if let Ok(mut streamer) = tft_st.lock() {
                            let _ = streamer.send_frame(&rle);
                        }
                    }
                    tx_cam.try_send(AiFrame::Rgb {
                        data: Bytes::copy_from_slice(rgb_bytes),
                        width: w,
                        height: h,
                    }).ok();
                }),
            );

            // Wait up to 3 seconds for hardware camera initialization
            let ready = rx_ready.recv_timeout(Duration::from_secs(3)).unwrap_or(false);
            if ready {
                info!("Webcam fisik (index {}) aktif terhubung via driver native nokhwa.", cam_idx);
            } else {
                warn!("Webcam fisik (index {}) gagal diinisialisasi secara native. Beralih ke simulated camera.", cam_idx);
            }
            ready
        };

        #[cfg(all(not(target_os = "linux"), not(feature = "native-camera")))]
        let mut webcam_active = false;

        #[cfg(all(not(target_os = "linux"), not(feature = "native-camera")))]
        let py_cmd_opt = crate::vision::resolve_python_command();

        #[cfg(all(not(target_os = "linux"), not(feature = "native-camera")))]
        if let Some(ref py_cmd) = py_cmd_opt {
            let spawned_cam = std::process::Command::new(py_cmd)
                .args(["-c", &py_script])
                .stdout(std::process::Stdio::piped())
                .stderr(std::process::Stdio::piped())
                .spawn();

            if let Ok(mut child) = spawned_cam {
                if let Some(mut stdout) = child.stdout.take() {
                    let frame_len = (width * height * 3) as usize;
                    let mut initial_frame = vec![0u8; frame_len];

                    if stdout.read_exact(&mut initial_frame).is_ok() {
                        info!("Webcam fisik (index {}) aktif terhubung via Python ({}) capture.", cam_idx, py_cmd);
                        webcam_active = true;

                    let tx_cam = tx_frame_clone.clone();
                    let hub_cam = hub_mock.clone();
                    let running_cam = running_mock.clone();
                    let cam_fps_cam = cam_fps_mock.clone();
                    let overlay_cam = overlay_hub_draw.clone();
                    let fb_display_cam = fb_display.clone();
                    let tft_streamer_cam = tft_streamer.clone();

                    std::thread::Builder::new()
                        .name("windows-webcam-feeder".to_string())
                        .spawn(move || {
                            let mut raw_buf = initial_frame;
                            let mut draw_buf = vec![0u8; frame_len];
                            let mut jpeg_buf = Vec::with_capacity(frame_len / 4);
                            let mut frame_count: u32 = 0;
                            let mut last_fps_time = Instant::now();
                            let mut last_tft_frame = Instant::now();

                            loop {
                                if !running_cam.load(Ordering::Relaxed) {
                                    let _ = child.kill();
                                    break;
                                }

                                tx_cam.try_send(AiFrame::Rgb {
                                    data: Bytes::copy_from_slice(&raw_buf),
                                    width,
                                    height,
                                }).ok();

                                draw_buf.copy_from_slice(&raw_buf);
                                let state = overlay_cam.latest();

                                if !state.status.is_empty() {
                                    vision::draw_text(
                                        &mut draw_buf,
                                        width as usize,
                                        height as usize,
                                        10,
                                        10,
                                        &state.status,
                                        (56, 189, 248),
                                        Some((0, 0, 0)),
                                    );
                                }

                                for face in &state.faces {
                                    let bbox = [face.x, face.y, face.width, face.height];
                                    let color = vision::parse_hex_color(&face.color);
                                    vision::draw_rect(&mut draw_buf, width as usize, height as usize, &bbox, color, 2);

                                    let score_y = (face.y + face.height - 24).max(0);
                                    vision::draw_text(
                                        &mut draw_buf,
                                        width as usize,
                                        height as usize,
                                        face.x + 4,
                                        score_y,
                                        &face.score_text,
                                        color,
                                        Some((0, 0, 0)),
                                    );

                                    let name_y = (face.y + face.height - 10).max(0);
                                    vision::draw_text(
                                        &mut draw_buf,
                                        width as usize,
                                        height as usize,
                                        face.x + 4,
                                        name_y,
                                        &face.name_text,
                                        color,
                                        Some((0, 0, 0)),
                                    );
                                }

                                if vision::encode_rgb_to_jpeg_into(&draw_buf, width, height, 75, &mut jpeg_buf).is_ok() {
                                    hub_cam.publish(Bytes::copy_from_slice(&jpeg_buf));
                                }

                                if let Ok(mut fb_guard) = fb_display_cam.lock() {
                                    if let Some(ref mut fb) = *fb_guard {
                                        let _ = fb.render_frame(&draw_buf, width, height);
                                    }
                                }

                                if let Some(ref tft_st) = tft_streamer_cam {
                                    let now = Instant::now();
                                    if now.duration_since(last_tft_frame) >= Duration::from_millis(100) {
                                        last_tft_frame = now;
                                        let rle = TftRleStreamer::encode_frame_rle(&draw_buf, width, height, 80, 60, 160, 120, true);
                                        if let Ok(mut st_guard) = tft_st.lock() {
                                            let _ = st_guard.send_frame(&rle);
                                        }
                                    }
                                }

                                frame_count += 1;
                                let elapsed = last_fps_time.elapsed();
                                if elapsed.as_secs_f32() >= 1.0 {
                                    let fps = frame_count as f32 / elapsed.as_secs_f32();
                                    cam_fps_cam.store((fps * 10.0) as u32, Ordering::Relaxed);
                                    frame_count = 0;
                                    last_fps_time = Instant::now();
                                }

                                if stdout.read_exact(&mut raw_buf).is_err() {
                                    warn!("Stream webcam fisik terputus.");
                                    let _ = child.kill();
                                    break;
                                }
                            }
                        })
                        .expect("Failed to spawn windows webcam feeder thread");
                } else {
                    if let Some(mut stderr) = child.stderr.take() {
                        let mut err_msg = String::new();
                        let _ = stderr.read_to_string(&mut err_msg);
                        if !err_msg.trim().is_empty() {
                            warn!("Webcam script stderr: {}", err_msg.trim());
                        }
                    }
                    warn!("Webcam fisik tidak merespons frame pertama, beralih ke simulated camera feeder.");
                    let _ = child.kill();
                }
            }
        }
    } else {
        warn!("Python >= 3.8 tidak ditemukan pada PATH atau PYTHON_BIN. Webcam capture fisik tidak dapat dijalankan.");
    }

        camera_simulated_tracker.store(!webcam_active, Ordering::Relaxed);
        if !webcam_active {
            info!("Platform non-Linux: Mengaktifkan simulated camera feeder untuk pengujian lokal.");
            let test_img_candidates = [
                PathBuf::from("test_face.png"),
                PathBuf::from("assets/test_face.png"),
                PathBuf::from("clients/edge-engine-rs/assets/test_face.png"),
                PathBuf::from("clients/edge-engine-rs/test_face.png"),
                PathBuf::from("models/test_face.png"),
            ];

            let mut base_rgb = vec![30u8; (width * height * 3) as usize];
            let mut img_loaded = false;
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
                        img_loaded = true;
                        break;
                    }
                }
            }

            if !img_loaded {
                vision::draw_text(
                    &mut base_rgb,
                    width as usize,
                    height as usize,
                    20,
                    20,
                    "[SIMULATED CAMERA - NO PHYSICAL WEBCAM DETECTED]",
                    (245, 158, 11),
                    Some((0, 0, 0)),
                );
                vision::draw_text(
                    &mut base_rgb,
                    width as usize,
                    height as usize,
                    20,
                    50,
                    "Pasang webcam USB",
                    (148, 163, 184),
                    Some((0, 0, 0)),
                );
            }

            let overlay_mock_feeder = overlay_hub_draw.clone();
            let feeder_interval_ms = (1000u64 / (fps_cap as u64)).clamp(16, 200);
            std::thread::Builder::new()
                .name("mock-camera-feeder".to_string())
                .spawn(move || {
                    info!("Simulated camera feeder thread aktif ({} FPS, interval {}ms).", fps_cap, feeder_interval_ms);
                    let mut draw_buf = vec![0u8; (width * height * 3) as usize];
                    let mut jpeg_buf = Vec::with_capacity((width * height / 4) as usize);
                    let mut frame_count: u32 = 0;
                    let mut last_fps_time = Instant::now();

                    while running_mock.load(Ordering::Relaxed) {
                        draw_buf.copy_from_slice(&base_rgb);
                        let state = overlay_mock_feeder.latest();
                        if !state.status.is_empty() {
                            vision::draw_text(
                                &mut draw_buf,
                                width as usize,
                                height as usize,
                                20,
                                height as i32 - 30,
                                &state.status,
                                (56, 189, 248),
                                Some((0, 0, 0)),
                            );
                        }

                        if vision::encode_rgb_to_jpeg_into(&draw_buf, width, height, 80, &mut jpeg_buf).is_ok() {
                            let b = Bytes::copy_from_slice(&jpeg_buf);
                            hub_mock.publish(b);
                            tx_frame_clone.try_send(AiFrame::Rgb {
                                data: Bytes::copy_from_slice(&draw_buf),
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

                        std::thread::sleep(Duration::from_millis(feeder_interval_ms));
                    }
                })
                .expect("Failed to spawn mock camera feeder thread");
        }
    }

    // Jalankan Axum HTTP stream & control server
    let server_config = server::ServerConfig {
        db_path: db_path.clone(),
        camera_device: device.clone(),
        width,
        height,
        model_version: model_version.clone(),
        start_time: Instant::now(),
        device_code: env::var("SMART_ABSENSI_DEVICE_ID").unwrap_or_else(|_| "GATE-01".to_string()),
        settings_path,
    };

    tokio::select! {
        res = server::start_stream_server(port, hub, overlay_hub, server_config, camera_simulated, camera_warning) => {
            if let Err(e) = res {
                error!("Stream server error (port {}): {:#}", port, e);
                running.store(false, Ordering::Relaxed);
                gpio.reset_all();
                return Err(e);
            }
        }
        _ = wait_for_shutdown_signal() => {
            info!("Menghentikan camera capture dan AI background worker...");
            running.store(false, Ordering::Relaxed);
            gpio.reset_all();
        }
    }

    Ok(())
}
