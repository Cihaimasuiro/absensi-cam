
pub mod camera;

pub struct FaceRecognizer {
    pub threshold: f32,
}

impl FaceRecognizer {
    pub fn new(threshold: f32) -> Self {
        Self { threshold }
    }

    /// Hitung Cosine Similarity antara dua vektor embedding 128-d
    pub fn cosine_similarity(v1: &[f32], v2: &[f32]) -> f32 {
        if v1.len() != v2.len() || v1.is_empty() {
            return 0.0;
        }

        let mut dot = 0.0;
        let mut norm1 = 0.0;
        let mut norm2 = 0.0;

        for (a, b) in v1.iter().zip(v2.iter()) {
            dot += a * b;
            norm1 += a * a;
            norm2 += b * b;
        }

        let denom = norm1.sqrt() * norm2.sqrt();
        if denom == 0.0 {
            0.0
        } else {
            dot / denom
        }
    }
}

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

/// Convert contiguous YUYV (4:2:2 packed) buffer into RGB8 buffer.
/// Each 4 bytes [Y0, U, Y1, V] produce 6 bytes [R0, G0, B0, R1, G1, B1].
pub fn yuyv_to_rgb(yuyv: &[u8], width: usize, height: usize, rgb_out: &mut [u8]) {
    let num_pixels = width * height;
    assert!(yuyv.len() >= num_pixels * 2, "YUYV buffer too small");
    assert!(rgb_out.len() >= num_pixels * 3, "RGB output buffer too small");

    for (chunk, out_chunk) in yuyv.chunks_exact(4).zip(rgb_out.chunks_exact_mut(6)) {
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

/// Convert contiguous YUYV (4:2:2 packed) buffer into BGR8 buffer.
pub fn yuyv_to_bgr(yuyv: &[u8], width: usize, height: usize, bgr_out: &mut [u8]) {
    let num_pixels = width * height;
    assert!(yuyv.len() >= num_pixels * 2, "YUYV buffer too small");
    assert!(bgr_out.len() >= num_pixels * 3, "BGR output buffer too small");

    for (chunk, out_chunk) in yuyv.chunks_exact(4).zip(bgr_out.chunks_exact_mut(6)) {
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

/// Encode RGB8 slice into an existing Vec<u8> buffer to eliminate per-frame allocations.
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
        yuyv_to_rgb(&yuyv, 2, 1, &mut rgb);
        assert_eq!(rgb, [128, 128, 128, 128, 128, 128]);
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
