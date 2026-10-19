use anyhow::Result;
use ort::session::builder::GraphOptimizationLevel;
use ort::session::Session;
use ort::value::Value;
use rustfft::num_complex::Complex;
use rustfft::FftPlanner;
use std::path::Path;

pub const DEFAULT_PASS_MARGIN: f32 = 2.197;
pub const LIVENESS_IMG_SIZE: usize = 256;
pub const FFT_SIZE: usize = 16;

#[derive(Debug, Clone)]
pub struct LivenessResult {
    pub is_real: bool,
    pub logit_diff: f32,
    pub prob_real: f32,
    pub confidence: f32,
}

pub struct LivenessDetector {
    session: Session,
    pub pass_margin: f32,
}

impl LivenessDetector {
    pub fn load<P: AsRef<Path>>(model_path: P) -> Result<Self> {
        let session = Session::builder()
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_optimization_level(GraphOptimizationLevel::Level3)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_intra_threads(1)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .commit_from_file(model_path)
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        Ok(Self {
            session,
            pass_margin: DEFAULT_PASS_MARGIN,
        })
    }

    /// Evaluates liveness on a detected face within an RGB image buffer.
    pub fn evaluate(
        &mut self,
        image_rgb: &[u8],
        width: usize,
        height: usize,
        stride: usize,
        bbox: &[f32; 4],
    ) -> Result<LivenessResult> {
        let bx = bbox[0];
        let by = bbox[1];
        let bw = bbox[2];
        let bh = bbox[3];

        let max_dim = bw.max(bh);
        let cx = bx + bw / 2.0;
        let cy = by + bh / 2.0;

        // 1. Tight crop (scale 1.0) -> [3, 256, 256] RGB / 255.0
        let tight_crop = Self::crop_and_resize(
            image_rgb, width, height, stride, cx, cy, max_dim, LIVENESS_IMG_SIZE,
        );

        // 2. Context crop (scale 2.0) -> [3, 256, 256] RGB / 255.0
        let ctx_crop = Self::crop_and_resize(
            image_rgb, width, height, stride, cx, cy, max_dim * 2.0, LIVENESS_IMG_SIZE,
        );

        // 3. Compute 16x16 FFT spectrum on grayscale tight crop
        let mut gray = vec![0.0f32; LIVENESS_IMG_SIZE * LIVENESS_IMG_SIZE];
        let ch_stride = LIVENESS_IMG_SIZE * LIVENESS_IMG_SIZE;
        for i in 0..ch_stride {
            let r = tight_crop[i] * 255.0;
            let g = tight_crop[ch_stride + i] * 255.0;
            let b = tight_crop[2 * ch_stride + i] * 255.0;
            gray[i] = 0.299 * r + 0.587 * g + 0.114 * b;
        }

        let fft_map = Self::compute_fft_16x16(&gray, LIVENESS_IMG_SIZE, LIVENESS_IMG_SIZE);

        // 4. Run ONNX model
        let tight_val = Value::from_array(([1usize, 3, LIVENESS_IMG_SIZE, LIVENESS_IMG_SIZE], tight_crop))
            .map_err(|e| anyhow::anyhow!("{e}"))?;
        let fft_val = Value::from_array(([1usize, 1, FFT_SIZE, FFT_SIZE], fft_map))
            .map_err(|e| anyhow::anyhow!("{e}"))?;
        let ctx_val = Value::from_array(([1usize, 3, LIVENESS_IMG_SIZE, LIVENESS_IMG_SIZE], ctx_crop))
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let outputs = self.session.run(ort::inputs![
            "input_tight" => tight_val,
            "input_fft" => fft_val,
            "input_ctx" => ctx_val,
        ]).map_err(|e| anyhow::anyhow!("{e}"))?;

        let (_, logits_slice) = outputs[0]
            .try_extract_tensor::<f32>()
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let real_logit = logits_slice[0];
        let spoof_logit = logits_slice[1];
        let logit_diff = real_logit - spoof_logit;

        let prob_real = 1.0 / (1.0 + (-logit_diff.clamp(-20.0, 20.0)).exp());
        let is_real = logit_diff >= self.pass_margin;

        Ok(LivenessResult {
            is_real,
            logit_diff,
            prob_real,
            confidence: logit_diff.abs(),
        })
    }

    /// Crops square ROI centered at (cx, cy) with size `crop_size`, handles reflection padding,
    /// and resizes via bilinear interpolation to NCHW [3, target_size, target_size] normalized to [0.0, 1.0].
    #[allow(clippy::too_many_arguments)]
    fn crop_and_resize(
        image_rgb: &[u8],
        img_w: usize,
        img_h: usize,
        stride: usize,
        cx: f32,
        cy: f32,
        crop_size: f32,
        target_size: usize,
    ) -> Vec<f32> {
        let x1 = cx - crop_size / 2.0;
        let y1 = cy - crop_size / 2.0;

        let mut out = vec![0.0f32; 3 * target_size * target_size];
        let ch_size = target_size * target_size;

        let scale = crop_size / target_size as f32;

        for dy in 0..target_size {
            let sy = y1 + (dy as f32 + 0.5) * scale;
            for dx in 0..target_size {
                let sx = x1 + (dx as f32 + 0.5) * scale;

                // Bilinear sampling with reflection padding
                let p00 = Self::sample_reflect(image_rgb, img_w, img_h, stride, sx.floor() as isize, sy.floor() as isize);
                let p10 = Self::sample_reflect(image_rgb, img_w, img_h, stride, sx.floor() as isize + 1, sy.floor() as isize);
                let p01 = Self::sample_reflect(image_rgb, img_w, img_h, stride, sx.floor() as isize, sy.floor() as isize + 1);
                let p11 = Self::sample_reflect(image_rgb, img_w, img_h, stride, sx.floor() as isize + 1, sy.floor() as isize + 1);

                let fx = sx - sx.floor();
                let fy = sy - sy.floor();

                let r = (1.0 - fx) * (1.0 - fy) * p00.0 + fx * (1.0 - fy) * p10.0 + (1.0 - fx) * fy * p01.0 + fx * fy * p11.0;
                let g = (1.0 - fx) * (1.0 - fy) * p00.1 + fx * (1.0 - fy) * p10.1 + (1.0 - fx) * fy * p01.1 + fx * fy * p11.1;
                let b = (1.0 - fx) * (1.0 - fy) * p00.2 + fx * (1.0 - fy) * p10.2 + (1.0 - fx) * fy * p01.2 + fx * fy * p11.2;

                let idx = dy * target_size + dx;
                out[idx] = (r / 255.0).clamp(0.0, 1.0);
                out[ch_size + idx] = (g / 255.0).clamp(0.0, 1.0);
                out[2 * ch_size + idx] = (b / 255.0).clamp(0.0, 1.0);
            }
        }

        out
    }

    #[inline(always)]
    fn sample_reflect(
        image_rgb: &[u8],
        w: usize,
        h: usize,
        stride: usize,
        x: isize,
        y: isize,
    ) -> (f32, f32, f32) {
        let reflect_coord = |coord: isize, max: usize| -> usize {
            if max == 0 {
                return 0;
            }
            if coord < 0 {
                (-coord).min(max as isize - 1) as usize
            } else if coord >= max as isize {
                (2 * (max as isize - 1) - coord).max(0) as usize
            } else {
                coord as usize
            }
        };

        let rx = reflect_coord(x, w);
        let ry = reflect_coord(y, h);

        let offset = ry * stride + rx * 3;
        if offset + 2 < image_rgb.len() {
            (
                image_rgb[offset] as f32,
                image_rgb[offset + 1] as f32,
                image_rgb[offset + 2] as f32,
            )
        } else {
            (0.0, 0.0, 0.0)
        }
    }

    /// Computes 2D FFT magnitude spectrum and reduces to 16x16 max-pool grid
    fn compute_fft_16x16(gray: &[f32], w: usize, h: usize) -> Vec<f32> {
        let mut planner = FftPlanner::new();
        let fft_row = planner.plan_fft_forward(w);
        let fft_col = planner.plan_fft_forward(h);

        // Convert input to complex
        let mut data = vec![Complex { re: 0.0f32, im: 0.0f32 }; w * h];
        for i in 0..(w * h) {
            data[i].re = gray[i];
        }

        // Row-wise 1D FFT
        for y in 0..h {
            let row = &mut data[y * w..(y + 1) * w];
            fft_row.process(row);
        }

        // Transpose for col-wise FFT
        let mut transposed = vec![Complex { re: 0.0f32, im: 0.0f32 }; w * h];
        for y in 0..h {
            for x in 0..w {
                transposed[x * h + y] = data[y * w + x];
            }
        }

        // Col-wise 1D FFT
        for x in 0..w {
            let col = &mut transposed[x * h..(x + 1) * h];
            fft_col.process(col);
        }

        // FFTShift & Log-magnitude
        let mut magnitude = vec![0.0f32; w * h];
        let half_w = w / 2;
        let half_h = h / 2;

        let mut sum = 0.0f32;
        for y in 0..h {
            let shifted_y = (y + half_h) % h;
            for x in 0..w {
                let shifted_x = (x + half_w) % w;
                // data was in transposed[x * h + y]
                let c = transposed[x * h + y];
                let mag = (c.re * c.re + c.im * c.im).sqrt();
                let log_mag = (mag + 1.0).ln();
                magnitude[shifted_y * w + shifted_x] = log_mag;
                sum += log_mag;
            }
        }

        let mean = sum / (w * h) as f32;
        let mut var_sum = 0.0f32;
        for &m in &magnitude {
            let diff = m - mean;
            var_sum += diff * diff;
        }
        let std = (var_sum / (w * h) as f32).sqrt();

        for m in &mut magnitude {
            let norm = (*m - mean) / (std + 1e-6);
            *m = (norm.clamp(-3.0, 3.0) + 3.0) / 6.0;
        }

        // Max pooling to 16x16
        let block_w = w / FFT_SIZE;
        let block_h = h / FFT_SIZE;
        let mut out = vec![0.0f32; FFT_SIZE * FFT_SIZE];

        for by in 0..FFT_SIZE {
            for bx in 0..FFT_SIZE {
                let mut max_val = f32::NEG_INFINITY;
                for py in (by * block_h)..((by + 1) * block_h) {
                    for px in (bx * block_w)..((bx + 1) * block_w) {
                        max_val = max_val.max(magnitude[py * w + px]);
                    }
                }
                out[by * FFT_SIZE + bx] = max_val;
            }
        }

        out
    }
}
