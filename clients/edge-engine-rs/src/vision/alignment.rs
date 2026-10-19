use crate::vision::recognizer::REFERENCE_POINTS;

/// Compute 2D similarity transformation matrix (2x3) using Umeyama algorithm.
/// Maps source landmarks to reference points.
pub fn estimate_similarity_transform(
    src: &[[f32; 2]; 5],
    dst: &[[f32; 2]; 5],
) -> Option<[f32; 6]> {
    // 1. Compute means
    let mut src_mean = [0.0f32; 2];
    let mut dst_mean = [0.0f32; 2];
    for i in 0..5 {
        src_mean[0] += src[i][0];
        src_mean[1] += src[i][1];
        dst_mean[0] += dst[i][0];
        dst_mean[1] += dst[i][1];
    }
    src_mean[0] /= 5.0;
    src_mean[1] /= 5.0;
    dst_mean[0] /= 5.0;
    dst_mean[1] /= 5.0;

    // 2. Center points & variance
    let mut src_var = 0.0f32;
    let mut s00 = 0.0f32;
    let mut s01 = 0.0f32;
    let mut s10 = 0.0f32;
    let mut s11 = 0.0f32;

    for i in 0..5 {
        let px = src[i][0] - src_mean[0];
        let py = src[i][1] - src_mean[1];
        let qx = dst[i][0] - dst_mean[0];
        let qy = dst[i][1] - dst_mean[1];

        src_var += px * px + py * py;
        // Covariance S = (1/N) * sum(q_i * p_i^T)
        s00 += qx * px;
        s01 += qx * py;
        s10 += qy * px;
        s11 += qy * py;
    }

    if src_var < 1e-6 {
        return None;
    }

    // 3. 2x2 SVD of S
    let mat = nalgebra::Matrix2::new(s00, s01, s10, s11);
    let svd = mat.svd(true, true);
    let u = svd.u?;
    let v_t = svd.v_t?;
    let singular_values = svd.singular_values;

    let det_u = u.determinant();
    let det_v_t = v_t.determinant();
    let mut d = 1.0f32;
    if det_u * det_v_t < 0.0 {
        d = -1.0;
    }

    let mut diag = nalgebra::Matrix2::identity();
    diag[(1, 1)] = d;

    // R = U * diag * V_t
    let r = u * diag * v_t;
    // scale = (sigma_0 + d * sigma_1) / src_var
    let scale = (singular_values[0] + d * singular_values[1]) / src_var;

    let a = scale * r[(0, 0)];
    let b = scale * r[(0, 1)];
    let c = scale * r[(1, 0)];
    let d_elem = scale * r[(1, 1)];

    let tx = dst_mean[0] - (a * src_mean[0] + b * src_mean[1]);
    let ty = dst_mean[1] - (c * src_mean[0] + d_elem * src_mean[1]);

    Some([a, b, tx, c, d_elem, ty])
}

/// Warps image according to 2x3 affine matrix and crops to 112x112 NCHW float tensor.
/// Preprocessing normalization: (pixel - 127.5) / 127.5.
pub fn align_face_to_tensor_nchw(
    image_rgb: &[u8],
    width: usize,
    height: usize,
    stride: usize,
    landmarks_5: &[[f32; 2]; 5],
) -> Option<Vec<f32>> {
    let m = estimate_similarity_transform(landmarks_5, &REFERENCE_POINTS)?;

    // Compute inverse transform to sample from original image:
    // [x_dst, y_dst]^T = M * [x_src, y_src, 1]^T
    // Det of 2x2 part: det = a*d - b*c
    let a = m[0];
    let b = m[1];
    let tx = m[2];
    let c = m[3];
    let d = m[4];
    let ty = m[5];

    let det = a * d - b * c;
    if det.abs() < 1e-7 {
        return None;
    }

    let inv_det = 1.0 / det;
    let inv_a = d * inv_det;
    let inv_b = -b * inv_det;
    let inv_c = -c * inv_det;
    let inv_d = a * inv_det;
    let inv_tx = (b * ty - d * tx) * inv_det;
    let inv_ty = (c * tx - a * ty) * inv_det;

    // Output buffer for NCHW [1, 3, 112, 112]
    let mut tensor = vec![0.0f32; 3 * 112 * 112];
    let channel_size = 112 * 112;

    for y_dst in 0..112 {
        for x_dst in 0..112 {
            let src_x = inv_a * (x_dst as f32) + inv_b * (y_dst as f32) + inv_tx;
            let src_y = inv_c * (x_dst as f32) + inv_d * (y_dst as f32) + inv_ty;

            // Bilinear interpolation
            let x0 = src_x.floor() as isize;
            let y0 = src_y.floor() as isize;
            let x1 = x0 + 1;
            let y1 = y0 + 1;

            let wx = src_x - x0 as f32;
            let wy = src_y - y0 as f32;

            let sample_pixel = |x: isize, y: isize| -> (f32, f32, f32) {
                let clamped_x = x.clamp(0, width as isize - 1) as usize;
                let clamped_y = y.clamp(0, height as isize - 1) as usize;
                let offset = clamped_y * stride + clamped_x * 3;
                if offset + 2 < image_rgb.len() {
                    (
                        image_rgb[offset] as f32,
                        image_rgb[offset + 1] as f32,
                        image_rgb[offset + 2] as f32,
                    )
                } else {
                    (0.0, 0.0, 0.0)
                }
            };

            let p00 = sample_pixel(x0, y0);
            let p10 = sample_pixel(x1, y0);
            let p01 = sample_pixel(x0, y1);
            let p11 = sample_pixel(x1, y1);

            let r = (1.0 - wx) * (1.0 - wy) * p00.0
                + wx * (1.0 - wy) * p10.0
                + (1.0 - wx) * wy * p01.0
                + wx * wy * p11.0;
            let g = (1.0 - wx) * (1.0 - wy) * p00.1
                + wx * (1.0 - wy) * p10.1
                + (1.0 - wx) * wy * p01.1
                + wx * wy * p11.1;
            let b = (1.0 - wx) * (1.0 - wy) * p00.2
                + wx * (1.0 - wy) * p10.2
                + (1.0 - wx) * wy * p01.2
                + wx * wy * p11.2;

            let idx = y_dst * 112 + x_dst;
            // ArcFace normalization: (pixel - 127.5) / 127.5
            tensor[idx] = (r - 127.5) / 127.5;
            tensor[channel_size + idx] = (g - 127.5) / 127.5;
            tensor[2 * channel_size + idx] = (b - 127.5) / 127.5;
        }
    }

    Some(tensor)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_similarity_transform_parity() {
        let pts: [[f32; 2]; 5] = [
            [200.0, 150.0],
            [300.0, 152.0],
            [250.0, 210.0],
            [210.0, 260.0],
            [290.0, 258.0],
        ];

        let m = estimate_similarity_transform(&pts, &REFERENCE_POINTS).expect("Failed transform");

        // OpenCV Python reference values:
        // [[ 3.68177396e-01,  2.34834360e-03, -3.65019481e+01],
        //  [-2.34834360e-03,  3.68177396e-01, -3.35667641e+00]]
        assert!((m[0] - 0.368177).abs() < 1e-4, "a: {}", m[0]);
        assert!((m[1] - 0.002348).abs() < 1e-4, "b: {}", m[1]);
        assert!((m[2] - (-36.5019)).abs() < 1e-2, "tx: {}", m[2]);
        assert!((m[3] - (-0.002348)).abs() < 1e-4, "c: {}", m[3]);
        assert!((m[4] - 0.368177).abs() < 1e-4, "d: {}", m[4]);
        assert!((m[5] - (-3.35667)).abs() < 1e-2, "ty: {}", m[5]);
    }
}
