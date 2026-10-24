use anyhow::Result;
use ort::session::builder::GraphOptimizationLevel;
use ort::session::Session;
use ort::value::Value;
use std::path::Path;

#[derive(Debug, Clone)]
pub struct FaceDetection {
    /// Kotak pembatas: [x, y, width, height]
    pub bbox: [f32; 4],
    /// Skor keyakinan deteksi dalam rentang [0.0, 1.0]
    pub score: f32,
    /// 5 landmark wajah: [mata_kanan, mata_kiri, hidung, mulut_kanan, mulut_kiri]
    pub landmarks: [[f32; 2]; 5],
}

pub struct FaceDetector {
    session: Session,
    input_tensor: ort::value::Tensor<f32>,
    pub conf_threshold: f32,
    pub nms_threshold: f32,
    pub top_k: usize,
}

impl FaceDetector {
    pub fn load<P: AsRef<Path>>(model_path: P) -> Result<Self> {
        let session = Session::builder()
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_optimization_level(GraphOptimizationLevel::Level3)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_intra_threads(1)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .commit_from_file(model_path)
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let input_tensor = Value::from_array(([1usize, 3, 640, 640], vec![0.0f32; 3 * 640 * 640]))
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        Ok(Self {
            session,
            input_tensor,
            conf_threshold: 0.6,
            nms_threshold: 0.3,
            top_k: 5000,
        })
    }

    /// Deteksi wajah dari buffer gambar RGB (stride dalam byte per baris).
    /// Otomatis mengonversi RGB ke layout tensor BGR [1, 3, 640, 640] yang diharapkan YuNet.
    pub fn detect(
        &mut self,
        image_rgb: &[u8],
        width: usize,
        height: usize,
        stride: usize,
    ) -> Result<Vec<FaceDetection>> {
        const NET_W: usize = 640;
        const NET_H: usize = 640;

        if width == 0 || height == 0 {
            return Ok(Vec::new());
        }

        let (_, tensor_slice) = self
            .input_tensor
            .try_extract_tensor_mut::<f32>()
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        tensor_slice.fill(0.0f32);
        let channel_stride = NET_W * NET_H;

        // Skala aspect-ratio preserving letterbox ke tensor 640x640
        let scale = (NET_W as f32 / width as f32).min(NET_H as f32 / height as f32);
        let target_w = ((width as f32 * scale).round() as usize).clamp(1, NET_W);
        let target_h = ((height as f32 * scale).round() as usize).clamp(1, NET_H);

        if (scale - 1.0).abs() < 1e-4 {
            // Jalur cepat langsung tanpa interpolasi jika dimensi masukan 640x480 / 640x640
            for y in 0..target_h {
                let row_offset = y * stride;
                let tensor_row_offset = y * NET_W;

                for x in 0..target_w {
                    let px_offset = row_offset + x * 3;
                    if px_offset + 2 < image_rgb.len() {
                        let r = image_rgb[px_offset] as f32;
                        let g = image_rgb[px_offset + 1] as f32;
                        let b = image_rgb[px_offset + 2] as f32;

                        let idx = tensor_row_offset + x;
                        tensor_slice[idx] = b; // Channel 0: Blue
                        tensor_slice[channel_stride + idx] = g; // Channel 1: Green
                        tensor_slice[2 * channel_stride + idx] = r; // Channel 2: Red
                    }
                }
            }
        } else {
            // Letterbox downsampling untuk resolusi lebih tinggi (720p, 1080p) agar seluruh frame tercover
            let inv_scale = 1.0 / scale;
            for y in 0..target_h {
                let src_y = ((y as f32 * inv_scale) as usize).min(height - 1);
                let row_offset = src_y * stride;
                let tensor_row_offset = y * NET_W;

                for x in 0..target_w {
                    let src_x = ((x as f32 * inv_scale) as usize).min(width - 1);
                    let px_offset = row_offset + src_x * 3;
                    if px_offset + 2 < image_rgb.len() {
                        let r = image_rgb[px_offset] as f32;
                        let g = image_rgb[px_offset + 1] as f32;
                        let b = image_rgb[px_offset + 2] as f32;

                        let idx = tensor_row_offset + x;
                        tensor_slice[idx] = b; // Channel 0: Blue
                        tensor_slice[channel_stride + idx] = g; // Channel 1: Green
                        tensor_slice[2 * channel_stride + idx] = r; // Channel 2: Red
                    }
                }
            }
        }

        let outputs = self
            .session
            .run(ort::inputs![&self.input_tensor])
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let strides = [8usize, 16, 32];
        let mut candidates = Vec::new();
        let thresh_sq = self.conf_threshold * self.conf_threshold;

        for (i, &s) in strides.iter().enumerate() {
            let net_cols = NET_W / s;
            let net_rows = NET_H / s;
            let cols = (target_w / s + 1).min(net_cols);
            let rows = (target_h / s + 1).min(net_rows);

            let (_, cls_slice) = outputs[i]
                .try_extract_tensor::<f32>()
                .map_err(|e| anyhow::anyhow!("{e}"))?;
            let (_, obj_slice) = outputs[i + 3]
                .try_extract_tensor::<f32>()
                .map_err(|e| anyhow::anyhow!("{e}"))?;
            let (_, bbox_slice) = outputs[i + 6]
                .try_extract_tensor::<f32>()
                .map_err(|e| anyhow::anyhow!("{e}"))?;
            let (_, kps_slice) = outputs[i + 9]
                .try_extract_tensor::<f32>()
                .map_err(|e| anyhow::anyhow!("{e}"))?;

            for r in 0..rows {
                for c in 0..cols {
                    let idx = r * net_cols + c;

                    let cls_score = cls_slice[idx].clamp(0.0, 1.0);
                    let obj_score = obj_slice[idx].clamp(0.0, 1.0);
                    let prod = cls_score * obj_score;

                    if prod < thresh_sq {
                        continue;
                    }
                    let score = prod.sqrt();

                    let cx = ((c as f32 + bbox_slice[idx * 4]) * s as f32) / scale;
                    let cy = ((r as f32 + bbox_slice[idx * 4 + 1]) * s as f32) / scale;
                    let w = (bbox_slice[idx * 4 + 2].exp() * s as f32) / scale;
                    let h = (bbox_slice[idx * 4 + 3].exp() * s as f32) / scale;

                    let x1 = cx - w / 2.0;
                    let y1 = cy - h / 2.0;

                    let mut landmarks = [[0.0f32; 2]; 5];
                    for n in 0..5 {
                        let lx = ((kps_slice[idx * 10 + 2 * n] + c as f32) * s as f32) / scale;
                        let ly = ((kps_slice[idx * 10 + 2 * n + 1] + r as f32) * s as f32) / scale;
                        landmarks[n] = [lx, ly];
                    }

                    // Periksa batas terhadap dimensi gambar asli
                    if x1 + w > 0.0 && y1 + h > 0.0 && x1 < width as f32 && y1 < height as f32 {
                        candidates.push(FaceDetection {
                            bbox: [x1, y1, w, h],
                            score,
                            landmarks,
                        });
                    }
                }
            }
        }

        drop(outputs);

        // Terapkan Non-Maximum Suppression (NMS)
        let nms_results = Self::non_max_suppression(candidates, self.nms_threshold, self.top_k);
        Ok(nms_results)
    }

    fn non_max_suppression(
        mut boxes: Vec<FaceDetection>,
        nms_threshold: f32,
        top_k: usize,
    ) -> Vec<FaceDetection> {
        if boxes.is_empty() {
            return Vec::new();
        }

        boxes.sort_by(|a, b| b.score.partial_cmp(&a.score).unwrap_or(std::cmp::Ordering::Equal));

        let mut keep = Vec::new();
        let mut suppressed = vec![false; boxes.len()];

        for i in 0..boxes.len() {
            if suppressed[i] {
                continue;
            }

            keep.push(boxes[i].clone());
            if keep.len() >= top_k {
                break;
            }

            let b1 = &boxes[i].bbox;
            let b1_x2 = b1[0] + b1[2];
            let b1_y2 = b1[1] + b1[3];
            let b1_area = b1[2] * b1[3];

            for j in (i + 1)..boxes.len() {
                if suppressed[j] {
                    continue;
                }

                let b2 = &boxes[j].bbox;
                let b2_x2 = b2[0] + b2[2];
                let b2_y2 = b2[1] + b2[3];
                let b2_area = b2[2] * b2[3];

                let inter_x1 = b1[0].max(b2[0]);
                let inter_y1 = b1[1].max(b2[1]);
                let inter_x2 = b1_x2.min(b2_x2);
                let inter_y2 = b1_y2.min(b2_y2);

                let inter_w = (inter_x2 - inter_x1).max(0.0);
                let inter_h = (inter_y2 - inter_y1).max(0.0);
                let inter_area = inter_w * inter_h;

                let union_area = b1_area + b2_area - inter_area;
                let iou = if union_area > 0.0 {
                    inter_area / union_area
                } else {
                    0.0
                };

                if iou > nms_threshold {
                    suppressed[j] = true;
                }
            }
        }

        keep
    }
}
