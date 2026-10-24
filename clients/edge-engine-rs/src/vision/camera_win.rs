//! Driver capture kamera native untuk Windows/macOS via nokhwa.
//! Menggantikan shim eksternal Python + OpenCV.

#![cfg(all(not(target_os = "linux"), feature = "native-camera"))]

use nokhwa::{
    pixel_format::RgbFormat,
    utils::{CameraIndex, RequestedFormat, RequestedFormatType},
    Camera,
};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::Sender;
use std::sync::Arc;
use std::time::{Duration, Instant};
use tracing::{error, info, warn};

/// Mulai thread capture kamera native menggunakan backend OS (Media Foundation / AVFoundation).
/// Mengirim frame RGB ke callback AI dan JPEG ke callback stream, mengikuti `fps_cap`.
/// Melaporkan status kesiapan melalui `ready_sender` untuk mencegah silent failure.
pub fn spawn_capture_thread_native<F, A>(
    device_index: u32,
    width: u32,
    height: u32,
    fps_cap: u32,
    running: Arc<AtomicBool>,
    ready_sender: Sender<bool>,
    mut on_stream_frame: F,
    mut on_ai_frame: Option<A>,
) -> std::thread::JoinHandle<()>
where
    F: FnMut(&[u8], u32, u32) + Send + 'static,
    A: FnMut(&[u8], u32, u32) + Send + 'static,
{
    std::thread::Builder::new()
        .name("native-camera".to_string())
        .spawn(move || {
            let interval_ms = (1000u64 / (fps_cap.clamp(5, 60) as u64)).clamp(16, 500);

            let format = RequestedFormat::new::<RgbFormat>(
                RequestedFormatType::AbsoluteHighestFrameRate,
            );

            let mut camera = match Camera::new(CameraIndex::Index(device_index), format) {
                Ok(c) => c,
                Err(e) => {
                    error!("Gagal membuka kamera native index {}: {:#}", device_index, e);
                    let _ = ready_sender.send(false);
                    return;
                }
            };

            if let Err(e) = camera.open_stream() {
                error!("Gagal memulai stream kamera native: {:#}", e);
                let _ = ready_sender.send(false);
                return;
            }

            // Laporkan bahwa kamera hardware berhasil diinisialisasi
            let _ = ready_sender.send(true);

            let active_fmt = camera.camera_format();
            info!(
                "Kamera native aktif: index {} (format {}x{} @ {} FPS, target {}x{}, cap {} FPS)",
                device_index, active_fmt.width(), active_fmt.height(), active_fmt.frame_rate(), width, height, fps_cap
            );

            if active_fmt.width() != width || active_fmt.height() != height {
                warn!(
                    "Resolusi kamera native ({}x{}) berbeda dari target setting ({}x{}). Frame akan dinormalisasi.",
                    active_fmt.width(), active_fmt.height(), width, height
                );
            }

            let mut last_emit = Instant::now()
                .checked_sub(Duration::from_millis(interval_ms))
                .unwrap_or_else(Instant::now);
            let mut jpeg_buf: Vec<u8> = Vec::with_capacity((width * height / 4) as usize);
            let mut frame_count: u64 = 0;

            while running.load(Ordering::Relaxed) {
                let frame = match camera.frame() {
                    Ok(f) => f,
                    Err(e) => {
                        error!("Frame kamera native gagal: {:#}", e);
                        std::thread::sleep(Duration::from_millis(50));
                        continue;
                    }
                };

                let now = Instant::now();
                if now.duration_since(last_emit) < Duration::from_millis(interval_ms) {
                    continue;
                }
                last_emit = now;

                let decoded = match frame.decode_image::<RgbFormat>() {
                    Ok(img) => img,
                    Err(e) => {
                        error!("Decode frame kamera native gagal: {:#}", e);
                        continue;
                    }
                };

                let (w, h, rgb_cow) = if decoded.width() != width || decoded.height() != height {
                    let resized = image::imageops::resize(&decoded, width, height, image::imageops::FilterType::Triangle);
                    (width, height, std::borrow::Cow::Owned(resized.into_raw()))
                } else {
                    (decoded.width(), decoded.height(), std::borrow::Cow::Borrowed(decoded.as_raw()))
                };
                let rgb: &[u8] = &rgb_cow;

                frame_count = frame_count.saturating_add(1);
                // Evaluasi kualitas gambar pada frame 30 (boot awal) dan tiap 150 frame (~5-10 detik)
                if frame_count == 30 || (frame_count > 30 && frame_count % 150 == 0) {
                    let (mean_luma, variance) = crate::vision::calculate_luma_and_variance(rgb);
                    if frame_count == 30 {
                        info!("Baseline kamera native index {}: luma={:.1}, variansi={:.1}", device_index, mean_luma, variance);
                    }
                    if mean_luma < 10.0 || variance < 4.0 {
                        warn!(
                            "Kamera index {} tidak menghasilkan gambar valid (luma rata-rata {:.1}, variansi {:.1}) setelah {} frame. \
                             Kemungkinan kamera IR, shutter kamera tertutup, atau frame statis. Coba index lain di Settings.",
                            device_index, mean_luma, variance, frame_count
                        );
                    }
                }

                // Callback AI menerima RGB mentah (tanpa decode JPEG ulang di AI loop)
                if let Some(ref mut ai_cb) = on_ai_frame {
                    ai_cb(rgb, w, h);
                }

                // Encode JPEG untuk HTTP MJPEG stream menggunakan crate `image`
                jpeg_buf.clear();
                let mut cursor = std::io::Cursor::new(&mut jpeg_buf);
                if image::codecs::jpeg::JpegEncoder::new_with_quality(&mut cursor, 75)
                    .encode(rgb, w, h, image::ExtendedColorType::Rgb8)
                    .is_ok()
                {
                    on_stream_frame(&jpeg_buf, w, h);
                }
            }

            let _ = camera.stop_stream();
            info!("Thread kamera native berhenti.");
        })
        .expect("Failed to spawn native camera thread")
}

/// Query daftar perangkat kamera native yang terhubung ke sistem.
pub fn query_native_camera_devices() -> Vec<String> {
    match nokhwa::query(nokhwa::utils::ApiBackend::Auto) {
        Ok(list) => list
            .into_iter()
            .enumerate()
            .map(|(i, info)| format!("{}: {}", i, info.human_name()))
            .collect(),
        Err(e) => {
            error!("Gagal mendeteksi kamera native via nokhwa: {:#}", e);
            Vec::new()
        }
    }
}
