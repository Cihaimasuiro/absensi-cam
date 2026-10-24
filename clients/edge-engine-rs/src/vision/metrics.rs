//! Modul Pencatatan Metrik Vision
//!
//! Menyediakan instrumentasi runtime pasif untuk kalibrasi ambang panduan wajah secara empiris:
//! - Menghitung luma rata-rata pada ROI 70% area tengah setiap frame.
//! - Melacak distribusi luma frame (min, rata-rata, maks, rasio gelap, rasio silau).
//! - Melacak dimensi kotak pembatas wajah yang terdeteksi (lebar, tinggi: min, rata-rata, maks).
//! - Mengagregasi metrik per menit lalu mengeluarkan satu baris log terstruktur `tracing::info!`.
//! - Dikendalikan variabel environment `EDGE_VISION_METRICS` (nonaktif secara default, tanpa overhead saat mati).

use std::time::{Duration, Instant};
use tracing::info;
use crate::vision::FaceDetection;

/// Konstanta baseline default untuk luma frame gelap (ambang sementara untuk pengumpulan data empiris).
pub const DEFAULT_DARK_LUMA_THRESHOLD: f32 = 40.0;

/// Konstanta baseline default untuk luma frame silau (ambang sementara untuk pengumpulan data empiris).
pub const DEFAULT_GLARE_LUMA_THRESHOLD: f32 = 220.0;

/// Faktor cakupan ROI tengah (70% area tengah = margin 15% di setiap sisi).
pub const CENTRAL_ROI_RATIO: f32 = 0.70;

/// Interval jendela agregasi default (60 detik = 1 menit).
pub const DEFAULT_METRICS_WINDOW_SECS: u64 = 60;

/// Hitung luma rata-rata pada ROI 70% area tengah dari buffer gambar RGB.
/// Mengembalikan nilai dalam rentang [0.0, 255.0].
pub fn calculate_central_roi_luma(
    image_rgb: &[u8],
    width: usize,
    height: usize,
    stride: usize,
) -> f32 {
    if width == 0 || height == 0 || image_rgb.is_empty() {
        return 0.0;
    }

    let margin_x = ((width as f32) * (1.0 - CENTRAL_ROI_RATIO) / 2.0).round() as usize;
    let margin_y = ((height as f32) * (1.0 - CENTRAL_ROI_RATIO) / 2.0).round() as usize;

    let roi_x_start = margin_x.min(width.saturating_sub(1));
    let roi_x_end = (width - margin_x).max(roi_x_start + 1).min(width);

    let roi_y_start = margin_y.min(height.saturating_sub(1));
    let roi_y_end = (height - margin_y).max(roi_y_start + 1).min(height);

    let mut luma_sum: u64 = 0;
    let mut pixel_count: u64 = 0;

    for y in roi_y_start..roi_y_end {
        let row_offset = y * stride;
        for x in roi_x_start..roi_x_end {
            let px = row_offset + x * 3;
            if px + 2 < image_rgb.len() {
                let r = image_rgb[px] as u32;
                let g = image_rgb[px + 1] as u32;
                let b = image_rgb[px + 2] as u32;
                // ITU-R BT.601 fixed-point integer: Y = (77*R + 150*G + 29*B) >> 8
                let y_val = (77 * r + 150 * g + 29 * b) >> 8;
                luma_sum += y_val as u64;
                pixel_count += 1;
            }
        }
    }

    if pixel_count == 0 {
        0.0
    } else {
        (luma_sum as f32) / (pixel_count as f32)
    }
}

/// Hitung luma rata-rata (ITU-R BT.601) dan variansi pada buffer RGB.
/// Nilai variansi mendekati nol menunjukkan gambar statis satu warna (solid color) atau shutter tertutup.
pub fn calculate_luma_and_variance(rgb: &[u8]) -> (f64, f64) {
    if rgb.len() < 3 {
        return (0.0, 0.0);
    }
    let mut sum = 0.0f64;
    let mut sum_sq = 0.0f64;
    let n = (rgb.len() / 3) as f64;

    for px in rgb.chunks_exact(3) {
        let y = 0.299 * px[0] as f64 + 0.587 * px[1] as f64 + 0.114 * px[2] as f64;
        sum += y;
        sum_sq += y * y;
    }
    let mean = sum / n;
    let variance = ((sum_sq / n) - (mean * mean)).max(0.0);
    (mean, variance)
}

/// Agregator metrik vision runtime dengan interval rollup 1 menit.
#[derive(Debug)]
pub struct VisionMetricsAggregator {
    enabled: bool,
    window_duration: Duration,
    window_start: Instant,

    // Metrik frame
    frame_count: u32,
    dark_frames: u32,
    glare_frames: u32,
    luma_sum: f64,
    luma_min: f32,
    luma_max: f32,

    // Metrik wajah
    face_count: u32,
    face_w_sum: f64,
    face_w_min: f32,
    face_w_max: f32,
    face_h_sum: f64,
    face_h_min: f32,
    face_h_max: f32,

    // Metrik performa inferensi deteksi & motion gate
    pub detection_skipped: u32,
    pub detection_run: u32,
    pub detect_duration_ms: f64,

    // Ambang batas (hanya untuk pengumpulan data, belum final)
    dark_threshold: f32,
    glare_threshold: f32,
}

impl Default for VisionMetricsAggregator {
    fn default() -> Self {
        Self::new()
    }
}

impl VisionMetricsAggregator {
    /// Inisialisasi agregator dari variabel environment `EDGE_VISION_METRICS` (nonaktif secara default).
    pub fn new() -> Self {
        let enabled = std::env::var("EDGE_VISION_METRICS")
            .map(|val| val == "1" || val.eq_ignore_ascii_case("true"))
            .unwrap_or(false);

        Self::with_enabled(enabled)
    }

    /// Buat agregator secara eksplisit dengan flag enabled (berguna untuk pengujian atau wiring programatik).
    pub fn with_enabled(enabled: bool) -> Self {
        Self {
            enabled,
            window_duration: Duration::from_secs(DEFAULT_METRICS_WINDOW_SECS),
            window_start: Instant::now(),

            frame_count: 0,
            dark_frames: 0,
            glare_frames: 0,
            luma_sum: 0.0,
            luma_min: f32::MAX,
            luma_max: f32::MIN,

            face_count: 0,
            face_w_sum: 0.0,
            face_w_min: f32::MAX,
            face_w_max: f32::MIN,
            face_h_sum: 0.0,
            face_h_min: f32::MAX,
            face_h_max: f32::MIN,

            detection_skipped: 0,
            detection_run: 0,
            detect_duration_ms: 0.0,

            dark_threshold: DEFAULT_DARK_LUMA_THRESHOLD,
            glare_threshold: DEFAULT_GLARE_LUMA_THRESHOLD,
        }
    }

    pub fn is_enabled(&self) -> bool {
        self.enabled
    }

    pub fn set_window_duration(&mut self, duration: Duration) {
        self.window_duration = duration;
    }

    /// Catat frame yang telah diproses beserta wajah terdeteksi ke dalam akumulator.
    /// Jika durasi jendela sudah lewat, ringkasan otomatis di-flush ke `tracing::info!`.
    pub fn record_frame(
        &mut self,
        image_rgb: &[u8],
        width: usize,
        height: usize,
        stride: usize,
        faces: &[FaceDetection],
    ) {
        if !self.enabled {
            return;
        }

        // Flush jendela rollup sebelumnya jika sudah kedaluwarsa sebelum menambah data frame baru
        if self.window_start.elapsed() >= self.window_duration {
            self.flush();
        }

        let luma = calculate_central_roi_luma(image_rgb, width, height, stride);

        self.frame_count += 1;
        self.luma_sum += luma as f64;
        if luma < self.luma_min {
            self.luma_min = luma;
        }
        if luma > self.luma_max {
            self.luma_max = luma;
        }

        if luma < self.dark_threshold {
            self.dark_frames += 1;
        } else if luma > self.glare_threshold {
            self.glare_frames += 1;
        }

        for face in faces {
            let bw = face.bbox[2];
            let bh = face.bbox[3];

            self.face_count += 1;
            self.face_w_sum += bw as f64;
            self.face_h_sum += bh as f64;

            if bw < self.face_w_min {
                self.face_w_min = bw;
            }
            if bw > self.face_w_max {
                self.face_w_max = bw;
            }
            if bh < self.face_h_min {
                self.face_h_min = bh;
            }
            if bh > self.face_h_max {
                self.face_h_max = bh;
            }
        }
    }

    pub fn record_detection_skipped(&mut self) {
        if !self.enabled {
            return;
        }
        self.detection_skipped += 1;
    }

    pub fn record_detection_run(&mut self, elapsed: Duration) {
        if !self.enabled {
            return;
        }
        self.detection_run += 1;
        self.detect_duration_ms += elapsed.as_secs_f64() * 1000.0;
    }

    /// Keluarkan metrik teragregasi ke `tracing::info!` lalu reset state untuk periode berikutnya.
    pub fn flush(&mut self) {
        if self.frame_count == 0 && self.detection_skipped == 0 && self.detection_run == 0 {
            self.window_start = Instant::now();
            return;
        }

        let elapsed_secs = self.window_start.elapsed().as_secs();
        let frame_divisor = self.frame_count.max(1) as f32;
        let avg_luma = if self.frame_count > 0 { (self.luma_sum / self.frame_count as f64) as f32 } else { 0.0 };
        let dark_ratio = (self.dark_frames as f32 / frame_divisor) * 100.0;
        let glare_ratio = (self.glare_frames as f32 / frame_divisor) * 100.0;

        let (avg_w, min_w, max_w) = if self.face_count > 0 {
            (
                (self.face_w_sum / self.face_count as f64) as f32,
                self.face_w_min,
                self.face_w_max,
            )
        } else {
            (0.0, 0.0, 0.0)
        };

        let (avg_h, min_h, max_h) = if self.face_count > 0 {
            (
                (self.face_h_sum / self.face_count as f64) as f32,
                self.face_h_min,
                self.face_h_max,
            )
        } else {
            (0.0, 0.0, 0.0)
        };

        let avg_detect_ms = if self.detection_run > 0 {
            self.detect_duration_ms / self.detection_run as f64
        } else {
            0.0
        };
        let total_det_ops = self.detection_run + self.detection_skipped;
        let skip_pct = if total_det_ops > 0 {
            (self.detection_skipped as f64 / total_det_ops as f64) * 100.0
        } else {
            0.0
        };

        info!(
            target: "engine_vision::metrics",
            window_sec = elapsed_secs,
            frames = self.frame_count,
            faces = self.face_count,
            detect_runs = self.detection_run,
            detect_skipped = self.detection_skipped,
            detect_skip_pct = format_args!("{:.1}", skip_pct),
            detect_avg_ms = format_args!("{:.2}", avg_detect_ms),
            luma_avg = format_args!("{:.1}", avg_luma),
            luma_min = format_args!("{:.1}", self.luma_min),
            luma_max = format_args!("{:.1}", self.luma_max),
            dark_ratio_pct = format_args!("{:.2}", dark_ratio),
            glare_ratio_pct = format_args!("{:.2}", glare_ratio),
            face_w_avg_px = format_args!("{:.1}", avg_w),
            face_w_min_px = format_args!("{:.1}", min_w),
            face_w_max_px = format_args!("{:.1}", max_w),
            face_h_avg_px = format_args!("{:.1}", avg_h),
            face_h_min_px = format_args!("{:.1}", min_h),
            face_h_max_px = format_args!("{:.1}", max_h),
            "[VISION_METRICS_1M] window={}s frames={} faces={} | Luma avg={:.1} min={:.1} max={:.1} (dark={:.1}%, glare={:.1}%) | FaceBox W[min={:.0} avg={:.0} max={:.0}] H[min={:.0} avg={:.0} max={:.0}] px | Inference[run={} skipped={} ({:.1}% saved) avg_ms={:.2}]",
            elapsed_secs,
            self.frame_count,
            self.face_count,
            avg_luma,
            self.luma_min,
            self.luma_max,
            dark_ratio,
            glare_ratio,
            min_w,
            avg_w,
            max_w,
            min_h,
            avg_h,
            max_h,
            self.detection_run,
            self.detection_skipped,
            skip_pct,
            avg_detect_ms,
        );

        // Reset akumulator
        self.frame_count = 0;
        self.dark_frames = 0;
        self.glare_frames = 0;
        self.luma_sum = 0.0;
        self.luma_min = f32::MAX;
        self.luma_max = f32::MIN;

        self.face_count = 0;
        self.face_w_sum = 0.0;
        self.face_w_min = f32::MAX;
        self.face_w_max = f32::MIN;
        self.face_h_sum = 0.0;
        self.face_h_min = f32::MAX;
        self.face_h_max = f32::MIN;

        self.detection_skipped = 0;
        self.detection_run = 0;
        self.detect_duration_ms = 0.0;

        self.window_start = Instant::now();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_calculate_central_roi_luma_solid_white() {
        let width = 100;
        let height = 100;
        let stride = width * 3;
        let image = vec![255u8; stride * height];

        let luma = calculate_central_roi_luma(&image, width, height, stride);
        // Aritmetika integer BT.601 pada 255: (77*255 + 150*255 + 29*255) >> 8 = (65280) >> 8 = 255
        assert!((luma - 255.0).abs() < 1.0);
    }

    #[test]
    fn test_calculate_central_roi_luma_solid_black() {
        let width = 100;
        let height = 100;
        let stride = width * 3;
        let image = vec![0u8; stride * height];

        let luma = calculate_central_roi_luma(&image, width, height, stride);
        assert_eq!(luma, 0.0);
    }

    #[test]
    fn test_metrics_disabled_does_nothing() {
        let mut agg = VisionMetricsAggregator::with_enabled(false);
        let dummy = vec![128u8; 300 * 300 * 3];
        agg.record_frame(&dummy, 300, 300, 900, &[]);
        assert_eq!(agg.frame_count, 0);
    }

    #[test]
    fn test_metrics_aggregation_counts() {
        let mut agg = VisionMetricsAggregator::with_enabled(true);
        // Set jendela pendek untuk pengujian
        agg.set_window_duration(Duration::from_millis(50));

        let width = 100;
        let height = 100;
        let stride = width * 3;
        let dark_img = vec![20u8; stride * height];
        let bright_img = vec![240u8; stride * height];

        let dummy_faces = vec![
            FaceDetection {
                bbox: [10.0, 10.0, 85.0, 110.0],
                score: 0.95,
                landmarks: [[0.0; 2]; 5],
            },
            FaceDetection {
                bbox: [50.0, 50.0, 120.0, 140.0],
                score: 0.90,
                landmarks: [[0.0; 2]; 5],
            },
        ];

        agg.record_frame(&dark_img, width, height, stride, &dummy_faces);
        agg.record_frame(&bright_img, width, height, stride, &[]);

        assert_eq!(agg.frame_count, 2);
        assert_eq!(agg.dark_frames, 1);
        assert_eq!(agg.glare_frames, 1);
        assert_eq!(agg.face_count, 2);
        assert_eq!(agg.face_w_min, 85.0);
        assert_eq!(agg.face_w_max, 120.0);
        assert_eq!(agg.face_h_min, 110.0);
        assert_eq!(agg.face_h_max, 140.0);

        // Tidur sejenak untuk memicu auto flush
        std::thread::sleep(Duration::from_millis(60));
        agg.record_frame(&dark_img, width, height, stride, &[]);

        // Setelah flush, akumulator seharusnya ter-reset
        assert_eq!(agg.frame_count, 1);
        assert_eq!(agg.dark_frames, 1);
        assert_eq!(agg.face_count, 0);
    }

    #[test]
    fn test_calculate_luma_and_variance() {
        // Gambar hitam total (shutter tertutup)
        let black = vec![0u8; 300];
        let (mean_black, var_black) = calculate_luma_and_variance(&black);
        assert_eq!(mean_black, 0.0);
        assert_eq!(var_black, 0.0);

        // Gambar satu warna (misal merah solid) -> variansi 0.0
        let red = vec![255, 0, 0].repeat(100);
        let (mean_red, var_red) = calculate_luma_and_variance(&red);
        assert!(mean_red > 70.0);
        assert!(var_red < 0.001); // variansi 0 karena satu warna solid

        // Gambar bervariasi (real scene) -> variansi > 0
        let mut varied = Vec::new();
        for i in 0..100 {
            varied.extend_from_slice(&[i as u8, (i * 2) as u8, (255 - i) as u8]);
        }
        let (mean_var, var_var) = calculate_luma_and_variance(&varied);
        assert!(mean_var > 10.0);
        assert!(var_var > 10.0);
    }
}
