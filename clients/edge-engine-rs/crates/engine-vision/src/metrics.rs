//! Vision Metrics Logging Module
//!
//! Provides passive runtime instrumentation for empirical face guidance threshold calibration:
//! - Computes average luma across the central 70% ROI of each frame.
//! - Tracks frame luma distribution (min, avg, max, dark ratio, glare ratio).
//! - Tracks detected face bounding box dimensions (width, height: min, avg, max).
//! - Aggregates metrics per minute and emits a single structured `tracing::info!` log line.
//! - Gated by environment variable `EDGE_VISION_METRICS` (disabled by default, zero overhead when off).

use std::time::{Duration, Instant};
use tracing::info;
use crate::FaceDetection;

/// Default baseline constant for dark frame luma (provisional threshold for empirical data collection).
pub const DEFAULT_DARK_LUMA_THRESHOLD: f32 = 40.0;

/// Default baseline constant for glare frame luma (provisional threshold for empirical data collection).
pub const DEFAULT_GLARE_LUMA_THRESHOLD: f32 = 220.0;

/// Central ROI coverage factor (70% central area = 15% margins on all sides).
pub const CENTRAL_ROI_RATIO: f32 = 0.70;

/// Default aggregation window interval (60 seconds = 1 minute).
pub const DEFAULT_METRICS_WINDOW_SECS: u64 = 60;

/// Calculate average luma across the central 70% ROI of an RGB image buffer.
/// Returns a value in range [0.0, 255.0].
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
                // ITU-R BT.601 integer fixed-point: Y = (77*R + 150*G + 29*B) >> 8
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

/// Aggregator for runtime vision metrics with 1-minute rollup intervals.
#[derive(Debug)]
pub struct VisionMetricsAggregator {
    enabled: bool,
    window_duration: Duration,
    window_start: Instant,

    // Frame metrics
    frame_count: u32,
    dark_frames: u32,
    glare_frames: u32,
    luma_sum: f64,
    luma_min: f32,
    luma_max: f32,

    // Face metrics
    face_count: u32,
    face_w_sum: f64,
    face_w_min: f32,
    face_w_max: f32,
    face_h_sum: f64,
    face_h_min: f32,
    face_h_max: f32,

    // Thresholds (data-collection only, non-final)
    dark_threshold: f32,
    glare_threshold: f32,
}

impl Default for VisionMetricsAggregator {
    fn default() -> Self {
        Self::new()
    }
}

impl VisionMetricsAggregator {
    /// Initialize aggregator from environment variable `EDGE_VISION_METRICS` (disabled by default).
    pub fn new() -> Self {
        let enabled = std::env::var("EDGE_VISION_METRICS")
            .map(|val| val == "1" || val.eq_ignore_ascii_case("true"))
            .unwrap_or(false);

        Self::with_enabled(enabled)
    }

    /// Explicitly create aggregator with enabled flag (useful for testing or programmatic wiring).
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

    /// Record a processed frame and its detected faces into the accumulator.
    /// If the window duration has elapsed, automatically flushes summary to `tracing::info!`.
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

        // Flush previous rollup window if expired before adding new frame data
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

    /// Emit aggregated metrics to `tracing::info!` and reset state for the next period.
    pub fn flush(&mut self) {
        if self.frame_count == 0 {
            self.window_start = Instant::now();
            return;
        }

        let elapsed_secs = self.window_start.elapsed().as_secs();
        let avg_luma = (self.luma_sum / self.frame_count as f64) as f32;
        let dark_ratio = (self.dark_frames as f32 / self.frame_count as f32) * 100.0;
        let glare_ratio = (self.glare_frames as f32 / self.frame_count as f32) * 100.0;

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

        info!(
            target: "engine_vision::metrics",
            window_sec = elapsed_secs,
            frames = self.frame_count,
            faces = self.face_count,
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
            "[VISION_METRICS_1M] window={}s frames={} faces={} | Luma avg={:.1} min={:.1} max={:.1} (dark={:.1}%, glare={:.1}%) | FaceBox W[min={:.0} avg={:.0} max={:.0}] H[min={:.0} avg={:.0} max={:.0}] px",
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
        );

        // Reset accumulators
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
        // BT.601 integer math on 255: (77*255 + 150*255 + 29*255) >> 8 = (65280) >> 8 = 255
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
        // Set short window for testing
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

        // Sleep to trigger auto flush
        std::thread::sleep(Duration::from_millis(60));
        agg.record_frame(&dark_img, width, height, stride, &[]);

        // After flush, accumulator should reset
        assert_eq!(agg.frame_count, 1);
        assert_eq!(agg.dark_frames, 1);
        assert_eq!(agg.face_count, 0);
    }
}
