pub mod camera;
pub mod recognizer;
pub mod alignment;
pub mod detector;
pub mod liveness;
pub mod overlay;

pub use alignment::align_face_to_tensor_nchw;
pub use detector::{FaceDetection, FaceDetector};
pub use liveness::{LivenessDetector, LivenessResult};
pub use recognizer::{init_onnx_runtime, FaceRecognizer, REFERENCE_POINTS};
pub use overlay::{draw_rect, draw_text};

/// Convert a single YUV triplet (BT.601) into RGB8
#[inline(always)]
pub fn yuv_to_rgb(y: u8, u: u8, v: u8) -> (u8, u8, u8) {
    let y = y as i32;
    let u = u as i32 - 128;
    let v = v as i32 - 128;

    // BT.601 integer fixed-point (scaled by 1024):
    // R = Y + 1.402 * V
    // G = Y - 0.344136 * U - 0.714136 * V
    // B = Y + 1.772 * U
    let r = y + ((1436 * v) >> 10);
    let g = y - ((352 * u + 731 * v) >> 10);
    let b = y + ((1815 * u) >> 10);

    (
        r.clamp(0, 255) as u8,
        g.clamp(0, 255) as u8,
        b.clamp(0, 255) as u8,
    )
}

/// Convert a single YUV triplet into BGR8
#[inline(always)]
pub fn yuv_to_bgr(y: u8, u: u8, v: u8) -> (u8, u8, u8) {
    let (r, g, b) = yuv_to_rgb(y, u, v);
    (b, g, r)
}

/// Convert contiguous YUYV (4:2:2 packed) buffer into RGB8 buffer with line stride support.
/// Returns false if input buffer is too short, avoiding any thread panic.
pub fn yuyv_to_rgb_stride(
    yuyv: &[u8],
    width: usize,
    height: usize,
    stride: usize,
    rgb_out: &mut [u8],
) -> bool {
    let row_bytes = width * 2;
    if stride < row_bytes || height == 0 || width == 0 {
        return false;
    }
    let required_len = (height - 1) * stride + row_bytes;
    if yuyv.len() < required_len || rgb_out.len() < width * height * 3 {
        return false;
    }

    for (row_idx, rgb_row) in rgb_out.chunks_exact_mut(width * 3).enumerate() {
        let row_start = row_idx * stride;
        let row_yuyv = &yuyv[row_start..row_start + row_bytes];

        for (chunk, out_chunk) in row_yuyv.chunks_exact(4).zip(rgb_row.chunks_exact_mut(6)) {
            let y0 = chunk[0];
            let u = chunk[1];
            let y1 = chunk[2];
            let v = chunk[3];

            let (r0, g0, b0) = yuv_to_rgb(y0, u, v);
            let (r1, g1, b1) = yuv_to_rgb(y1, u, v);

            out_chunk[0] = r0;
            out_chunk[1] = g0;
            out_chunk[2] = b0;
            out_chunk[3] = r1;
            out_chunk[4] = g1;
            out_chunk[5] = b1;
        }
    }

    true
}

/// Convert contiguous YUYV (4:2:2 packed) buffer into RGB8 buffer (default stride = width * 2).
pub fn yuyv_to_rgb(yuyv: &[u8], width: usize, height: usize, rgb_out: &mut [u8]) -> bool {
    yuyv_to_rgb_stride(yuyv, width, height, width * 2, rgb_out)
}

/// Convert contiguous YUYV (4:2:2 packed) buffer into BGR8 buffer with line stride support.
pub fn yuyv_to_bgr_stride(
    yuyv: &[u8],
    width: usize,
    height: usize,
    stride: usize,
    bgr_out: &mut [u8],
) -> bool {
    let row_bytes = width * 2;
    if stride < row_bytes || height == 0 || width == 0 {
        return false;
    }
    let required_len = (height - 1) * stride + row_bytes;
    if yuyv.len() < required_len || bgr_out.len() < width * height * 3 {
        return false;
    }

    for (row_idx, bgr_row) in bgr_out.chunks_exact_mut(width * 3).enumerate() {
        let row_start = row_idx * stride;
        let row_yuyv = &yuyv[row_start..row_start + row_bytes];

        for (chunk, out_chunk) in row_yuyv.chunks_exact(4).zip(bgr_row.chunks_exact_mut(6)) {
            let y0 = chunk[0];
            let u = chunk[1];
            let y1 = chunk[2];
            let v = chunk[3];

            let (b0, g0, r0) = yuv_to_bgr(y0, u, v);
            let (b1, g1, r1) = yuv_to_bgr(y1, u, v);

            out_chunk[0] = b0;
            out_chunk[1] = g0;
            out_chunk[2] = r0;
            out_chunk[3] = b1;
            out_chunk[4] = g1;
            out_chunk[5] = r1;
        }
    }

    true
}

/// Convert contiguous YUYV (4:2:2 packed) buffer into BGR8 buffer (default stride = width * 2).
pub fn yuyv_to_bgr(yuyv: &[u8], width: usize, height: usize, bgr_out: &mut [u8]) -> bool {
    yuyv_to_bgr_stride(yuyv, width, height, width * 2, bgr_out)
}

/// Encode RGB8 slice into an existing Vec<u8> buffer.
pub fn encode_rgb_to_jpeg_into(
    rgb: &[u8],
    width: u32,
    height: u32,
    quality: u8,
    out_buf: &mut Vec<u8>,
) -> anyhow::Result<()> {
    use image::ImageEncoder;
    out_buf.clear();
    let encoder = image::codecs::jpeg::JpegEncoder::new_with_quality(&mut *out_buf, quality);
    encoder.write_image(rgb, width, height, image::ExtendedColorType::Rgb8)?;
    Ok(())
}

/// Encode RGB8 slice into newly allocated JPEG Vec<u8>
pub fn encode_rgb_to_jpeg(
    rgb: &[u8],
    width: u32,
    height: u32,
    quality: u8,
) -> anyhow::Result<Vec<u8>> {
    let mut out = Vec::with_capacity((width * height / 4) as usize);
    encode_rgb_to_jpeg_into(rgb, width, height, quality, &mut out)?;
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_yuyv_to_rgb_neutral_gray() {
        // Y0=128, U=128, Y1=128, V=128 -> should be R=128, G=128, B=128
        let yuyv = [128u8, 128, 128, 128];
        let mut rgb = [0u8; 6];
        let ok = yuyv_to_rgb(&yuyv, 2, 1, &mut rgb);
        assert!(ok);
        assert_eq!(rgb, [128, 128, 128, 128, 128, 128]);
    }

    #[test]
    fn test_yuyv_truncated_frame_does_not_panic() {
        let short_yuyv = [128u8; 3];
        let mut rgb = [0u8; 6];
        let ok = yuyv_to_rgb(&short_yuyv, 2, 1, &mut rgb);
        assert!(!ok, "Should safely return false on truncated frame without panicking");
    }

    #[test]
    fn test_yuyv_stride_padding() {
        // 2 pixels width (4 bytes of YUYV), but row stride is 8 bytes (4 bytes padding per row)
        // 2 rows
        let yuyv = [
            128u8, 128, 128, 128, 0, 0, 0, 0, // row 0: 4 bytes data + 4 bytes padding
            128, 128, 128, 128, 0, 0, 0, 0,   // row 1: 4 bytes data + 4 bytes padding
        ];
        let mut rgb = [0u8; 12];
        let ok = yuyv_to_rgb_stride(&yuyv, 2, 2, 8, &mut rgb);
        assert!(ok);
        assert_eq!(rgb, [128u8; 12]);
    }

    #[test]
    fn test_jpeg_encoding() {
        let rgb = [128u8; 16 * 16 * 3];
        let jpeg = encode_rgb_to_jpeg(&rgb, 16, 16, 75).unwrap();
        // Valid JPEG starts with SOI 0xFF, 0xD8 and ends with EOI 0xFF, 0xD9
        assert!(jpeg.len() > 4);
        assert_eq!(&jpeg[..2], &[0xFF, 0xD8]);
        assert_eq!(&jpeg[jpeg.len() - 2..], &[0xFF, 0xD9]);
    }
}
