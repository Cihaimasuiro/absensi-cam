#![allow(unknown_lints)]
#![allow(clippy::chunks_exact_to_as_chunks)]

#[cfg(not(any(
    target_arch = "x86_64",
    target_arch = "aarch64",
    target_arch = "arm",
    target_arch = "x86",
)))]
compile_error!(
    "Arsitektur ini belum didukung. ONNX Runtime dan vision pipeline hanya tersedia untuk \
     x86_64, aarch64, arm (armv7/armhf), dan x86 (i686). Lihat docs/SUPPORT.md."
);

pub mod camera;
#[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
pub mod camera_win;
pub mod recognizer;
pub mod alignment;
pub mod detector;
pub mod liveness;
pub mod overlay;
pub mod metrics;
pub mod tracker;

pub use alignment::align_face_to_tensor_nchw;
pub use detector::{FaceDetection, FaceDetector};
pub use liveness::{LivenessDetector, LivenessResult, DEFAULT_PASS_MARGIN};
pub use recognizer::{init_onnx_runtime, FaceRecognizer, REFERENCE_POINTS};
pub use overlay::{draw_rect, draw_text, parse_hex_color};
pub use camera::PixelFormat;
pub use metrics::{VisionMetricsAggregator, calculate_central_roi_luma, calculate_luma_and_variance};
pub use tracker::TrackedStudent;

/// Konversi satu triplet YUV (BT.601) menjadi RGB8
#[inline(always)]
pub fn yuv_to_rgb(y: u8, u: u8, v: u8) -> (u8, u8, u8) {
    let y = y as i32;
    let u = u as i32 - 128;
    let v = v as i32 - 128;

    // BT.601 fixed-point integer (diskala 1024):
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

/// Konversi satu triplet YUV menjadi BGR8
#[inline(always)]
pub fn yuv_to_bgr(y: u8, u: u8, v: u8) -> (u8, u8, u8) {
    let (r, g, b) = yuv_to_rgb(y, u, v);
    (b, g, r)
}

/// Konversi buffer YUYV (4:2:2 packed) yang bersambung menjadi buffer RGB8 dengan dukungan line stride.
/// Mengembalikan false jika buffer masukan terlalu pendek, sehingga tidak memicu panic pada thread.
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

/// Konversi buffer YUYV (4:2:2 packed) yang bersambung menjadi buffer RGB8 (stride default = width * 2).
pub fn yuyv_to_rgb(yuyv: &[u8], width: usize, height: usize, rgb_out: &mut [u8]) -> bool {
    yuyv_to_rgb_stride(yuyv, width, height, width * 2, rgb_out)
}

/// Konversi buffer YUYV (4:2:2 packed) yang bersambung menjadi buffer BGR8 dengan dukungan line stride.
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

/// Konversi buffer YUYV (4:2:2 packed) yang bersambung menjadi buffer BGR8 (stride default = width * 2).
pub fn yuyv_to_bgr(yuyv: &[u8], width: usize, height: usize, bgr_out: &mut [u8]) -> bool {
    yuyv_to_bgr_stride(yuyv, width, height, width * 2, bgr_out)
}

/// Encode slice RGB8 ke dalam buffer Vec<u8> yang sudah ada.
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

/// Encode slice RGB8 menjadi Vec<u8> JPEG yang baru dialokasikan
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

#[cfg(not(feature = "native-camera"))]
/// Validate whether a candidate command name or path can execute Python >= 3.8.
pub fn is_valid_python(cmd: &str) -> bool {
    std::process::Command::new(cmd)
        .args(["-c", "import sys; sys.exit(0 if sys.version_info >= (3, 8) else 2)"])
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
}

#[cfg(not(feature = "native-camera"))]
/// Check whether the specified Python executable has OpenCV (`cv2`) installed and importable.
pub fn has_opencv(cmd: &str) -> bool {
    std::process::Command::new(cmd)
        .args(["-c", "import cv2"])
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
}

#[cfg(not(feature = "native-camera"))]
/// Query `uv` for an installed Python interpreter (preferring versions <= 3.13 for OpenCV wheel compatibility).
fn find_uv_python() -> Option<String> {
    // 1. Direct filesystem check on Windows (%APPDATA%\uv\python\...)
    // Zero subprocess overhead, instant stat, and immune to CLI cache ACL issues.
    #[cfg(target_os = "windows")]
    {
        if let Ok(appdata) = std::env::var("APPDATA") {
            let uv_dir = std::path::Path::new(&appdata).join("uv").join("python");
            if uv_dir.exists() {
                let candidates = [
                    "cpython-3.11-windows-x86_64-none",
                    "cpython-3.12-windows-x86_64-none",
                    "cpython-3.10-windows-x86_64-none",
                ];
                for c in &candidates {
                    let exe = uv_dir.join(c).join("python.exe");
                    if exe.exists() {
                        return Some(exe.to_string_lossy().to_string());
                    }
                }
            }
        }
    }

    // 2. Query uv CLI if available (fast early exit if uv is missing)
    let is_uv_installed = std::process::Command::new("uv")
        .arg("--version")
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .status()
        .map(|s| s.success())
        .unwrap_or(false);

    if !is_uv_installed {
        return None;
    }

    for q in &["<=3.13", ""] {
        let mut cmd = std::process::Command::new("uv");
        cmd.arg("python").arg("find");
        if !q.is_empty() {
            cmd.arg(q);
        }
        if let Ok(output) = cmd.output() {
            if output.status.success() {
                let path = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if !path.is_empty() && std::path::Path::new(&path).exists() {
                    return Some(path);
                }
            }
        }
    }
    None
}

#[cfg(not(feature = "native-camera"))]
/// Resolve the Python binary executable for cross-platform compatibility.
/// Prioritizes:
/// 1. `PYTHON_BIN` environment variable (if specified and valid)
/// 2. `uv` managed Python runtime (`uv python find` / `%APPDATA%\uv\python`)
/// 3. Project virtual environments (`.venv`)
/// 4. System `python3` / `python`
///
/// On non-Linux (Windows/macOS), camera capture strictly requires OpenCV (`cv2`).
/// Interpreters without OpenCV are rejected to prevent masking the capture failure.
pub fn resolve_python_command() -> Option<String> {
    static CACHE: std::sync::OnceLock<Option<String>> = std::sync::OnceLock::new();
    CACHE
        .get_or_init(|| {
            if let Ok(custom) = std::env::var("PYTHON_BIN") {
                let trimmed = custom.trim();
                #[cfg(not(target_os = "linux"))]
                {
                    if !trimmed.is_empty() && is_valid_python(trimmed) && has_opencv(trimmed) {
                        return Some(trimmed.to_string());
                    }
                }
                #[cfg(target_os = "linux")]
                {
                    if !trimmed.is_empty() && is_valid_python(trimmed) {
                        return Some(trimmed.to_string());
                    }
                }
            }

            let mut candidates: Vec<String> = Vec::new();

            // 1. Check if uv provides a Python interpreter
            if let Some(uv_py) = find_uv_python() {
                candidates.push(uv_py);
            }

            // 2. Check local and parent virtual environments
            let venv_relative_paths = [
                ".venv/Scripts/python.exe",
                ".venv/bin/python",
                "../.venv/Scripts/python.exe",
                "../.venv/bin/python",
                "../../.venv/Scripts/python.exe",
                "../../.venv/bin/python",
            ];
            for venv_path in &venv_relative_paths {
                let p = std::path::Path::new(venv_path);
                if p.exists() {
                    candidates.push(venv_path.to_string());
                }
            }

            // 3. System PATH candidates
            #[cfg(not(target_os = "windows"))]
            {
                candidates.push("python3".to_string());
                candidates.push("python".to_string());
            }

            #[cfg(target_os = "windows")]
            {
                candidates.push("python".to_string());
                candidates.push("python3".to_string());
            }

            // Non-Linux: camera capture strictly requires OpenCV (cv2).
            // Reject any interpreter without cv2 so errors are accurate and not masked.
            #[cfg(not(target_os = "linux"))]
            {
                for candidate in &candidates {
                    if is_valid_python(candidate) && has_opencv(candidate) {
                        return Some(candidate.clone());
                    }
                }
                None
            }

            // Linux: native V4L2 kernel capture is used, so cv2 is not required.
            #[cfg(target_os = "linux")]
            {
                for candidate in &candidates {
                    if is_valid_python(candidate) {
                        return Some(candidate.clone());
                    }
                }
                None
            }
        })
        .clone()
}

#[cfg(not(feature = "native-camera"))]
/// Return the Python expression string that selects the OpenCV VideoCapture backend for current OS.
pub fn cv2_backend_expr() -> &'static str {
    "cv2.CAP_DSHOW if sys.platform == 'win32' else (cv2.CAP_AVFOUNDATION if sys.platform == 'darwin' else cv2.CAP_V4L2)"
}

/// Pilih kamera terbaik secara otomatis:
/// - Windows / macOS: enumerasi via nokhwa, tolak virtual/OBS/DroidCam/IR, pilih kamera RGB fisik pertama.
/// - Linux: enumerasi /dev/video0..9, verifikasi device capture V4L2 fungsional via Camera::open.
/// - Fallback: "0" (non-Linux) atau "/dev/video0" (Linux).
pub fn auto_select_camera() -> Option<String> {
    #[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
    {
        if let Ok(list) = nokhwa::query(nokhwa::utils::ApiBackend::Auto) {
            if !list.is_empty() {
                if list.len() == 1 {
                    return Some("0".to_string());
                }

                let candidates: Vec<(usize, &nokhwa::utils::CameraInfo)> = list
                    .iter()
                    .enumerate()
                    .filter(|(_, d)| {
                        let name = d.human_name().to_lowercase();
                        let desc = d.description().to_lowercase();
                        !name.contains("virtual")
                            && !name.contains("obs")
                            && !name.contains("droidcam")
                            && !name.contains("iriun")
                            && !desc.contains("virtual")
                            && !desc.contains("obs")
                            && !desc.contains("droidcam")
                            && !desc.contains("iriun")
                    })
                    .collect();

                if !candidates.is_empty() {
                    if let Some(&(idx, _)) = candidates.iter().find(|(_, d)| {
                        let name = d.human_name().to_lowercase();
                        !name.contains("ir ") && !name.contains("ir camera") && !name.contains("windows hello")
                    }) {
                        return Some(idx.to_string());
                    }
                    return Some(candidates[0].0.to_string());
                }
                return Some("0".to_string());
            }
        }
    }

    #[cfg(target_os = "linux")]
    {
        for i in 0..10 {
            let path = format!("/dev/video{}", i);
            if std::path::Path::new(&path).exists() {
                if let Ok(cam) = camera::linux::Camera::open(&path, 640, 480) {
                    drop(cam);
                    return Some(path);
                }
            }
        }
        if std::path::Path::new("/dev/video0").exists() {
            return Some("/dev/video0".to_string());
        }
    }

    #[cfg(not(target_os = "linux"))]
    {
        Some("0".to_string())
    }
    #[cfg(target_os = "linux")]
    {
        Some("/dev/video0".to_string())
    }
}


#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_yuyv_to_rgb_neutral_gray() {
        // Y0=128, U=128, Y1=128, V=128 -> seharusnya menghasilkan R=128, G=128, B=128
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
        // Lebar 2 piksel (4 byte YUYV), tetapi row stride 8 byte (4 byte padding per baris)
        // 2 baris
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
        // JPEG yang valid diawali SOI 0xFF, 0xD8 dan diakhiri EOI 0xFF, 0xD9
        assert!(jpeg.len() > 4);
        assert_eq!(&jpeg[..2], &[0xFF, 0xD8]);
        assert_eq!(&jpeg[jpeg.len() - 2..], &[0xFF, 0xD9]);
    }

    #[test]
    #[cfg(not(feature = "native-camera"))]
    fn test_resolve_python_command_works_when_available() {
        if let Some(py) = resolve_python_command() {
            assert!(is_valid_python(&py), "Resolved Python command must be valid: {}", py);
        }
    }
}
