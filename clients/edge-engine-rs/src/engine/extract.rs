use crate::engine::utils::{find_model_path, model_search_dirs};
use crate::vision::{
    alignment::align_face_to_tensor_nchw,
    detector::FaceDetector,
    recognizer::{init_onnx_runtime, FaceRecognizer},
};
use base64::Engine;
use image::{DynamicImage, ImageDecoder, ImageReader};
use std::path::Path;
use thiserror::Error;

#[derive(Debug, Error)]
pub enum ExtractError {
    #[error("Could not read image")]
    CouldNotReadImage,

    #[error("No face detected")]
    NoFaceDetected,

    #[error("Multiple faces detected. Please upload a photo with only one face.")]
    MultipleFacesDetected,

    #[error("Face alignment failed")]
    FaceAlignmentFailed,

    #[error("{0}")]
    Other(String),
}

/// Calculate downscale dimensions preserving aspect ratio such that width <= max_dim and height <= max_dim.
/// Returns (new_width, new_height, scale_factor). If image already fits, returns (width, height, 1.0).
pub fn compute_downscale_dimensions(width: u32, height: u32, max_dim: u32) -> (u32, u32, f32) {
    if width == 0 || height == 0 {
        return (width, height, 1.0);
    }

    if width <= max_dim && height <= max_dim {
        return (width, height, 1.0);
    }

    let scale = (max_dim as f32 / width as f32).min(max_dim as f32 / height as f32);
    let new_w = ((width as f32 * scale).round() as u32).max(1);
    let new_h = ((height as f32 * scale).round() as u32).max(1);

    (new_w, new_h, scale)
}

/// Convert 512-d f32 embedding into 2048 little-endian bytes and return standard base64 string.
pub fn embedding_to_b64(embedding: &[f32]) -> String {
    let mut bytes = Vec::with_capacity(embedding.len() * 4);
    for val in embedding {
        bytes.extend_from_slice(&val.to_le_bytes());
    }
    base64::engine::general_purpose::STANDARD.encode(&bytes)
}

/// Open an image with EXIF orientation auto-applied (matching cv2.imread behavior).
pub fn load_image_with_exif<P: AsRef<Path>>(path: P) -> Result<DynamicImage, ExtractError> {
    let reader = ImageReader::open(path.as_ref())
        .map_err(|_| ExtractError::CouldNotReadImage)?
        .with_guessed_format()
        .map_err(|_| ExtractError::CouldNotReadImage)?;

    let mut decoder = reader
        .into_decoder()
        .map_err(|_| ExtractError::CouldNotReadImage)?;

    let orientation = decoder
        .orientation()
        .unwrap_or(image::metadata::Orientation::NoTransforms);

    let mut img = DynamicImage::from_decoder(decoder)
        .map_err(|_| ExtractError::CouldNotReadImage)?;

    img.apply_orientation(orientation);
    Ok(img)
}

/// Extract 512-d normalized face embedding from a photo image.
///
/// Ported behaviours:
/// 1. Large photos: Downscale so the image fits in 640x640 (never upscale), detect,
///    scale landmarks back to the original full-resolution space, then align on the
///    original full-resolution image.
/// 2. Face count: Exactly one face required (defaults: conf 0.6, NMS 0.3).
///
/// Note on unported Python behavior:
/// Low-light gamma preprocessing in Python `face_detector/detector.py` is intentionally
/// omitted to avoid CPU overhead on Edge/Server workers, as it only affects dark photos.
pub fn extract_embedding(
    image: &DynamicImage,
    detector: &mut FaceDetector,
    recognizer: &mut FaceRecognizer,
) -> Result<Vec<f32>, ExtractError> {
    let orig_w = image.width();
    let orig_h = image.height();

    if orig_w == 0 || orig_h == 0 {
        return Err(ExtractError::CouldNotReadImage);
    }

    // Downscale for detection if either dimension exceeds 640px
    let (det_w, det_h, scale) = compute_downscale_dimensions(orig_w, orig_h, 640);

    let faces = if scale < 1.0 {
        let resized = image.resize(det_w, det_h, image::imageops::FilterType::Triangle);
        let rgb_resized = resized.to_rgb8();
        let actual_w = rgb_resized.width() as usize;
        let actual_h = rgb_resized.height() as usize;
        let stride = actual_w * 3;

        detector
            .detect(rgb_resized.as_raw(), actual_w, actual_h, stride)
            .map_err(|e| ExtractError::Other(format!("Detection error: {e}")))?
    } else {
        let rgb_orig = image.to_rgb8();
        let stride = orig_w as usize * 3;

        detector
            .detect(rgb_orig.as_raw(), orig_w as usize, orig_h as usize, stride)
            .map_err(|e| ExtractError::Other(format!("Detection error: {e}")))?
    };

    if faces.is_empty() {
        return Err(ExtractError::NoFaceDetected);
    }

    if faces.len() > 1 {
        return Err(ExtractError::MultipleFacesDetected);
    }

    let face = &faces[0];

    // Map landmarks back to full-resolution image coordinates if downscaled
    let mut landmarks = face.landmarks;
    if scale < 1.0 && scale > 0.0 {
        for pt in &mut landmarks {
            pt[0] /= scale;
            pt[1] /= scale;
        }
    }

    // Align on original full-resolution image
    let rgb_orig = image.to_rgb8();
    let aligned_tensor = align_face_to_tensor_nchw(
        rgb_orig.as_raw(),
        orig_w as usize,
        orig_h as usize,
        orig_w as usize * 3,
        &landmarks,
    )
    .ok_or(ExtractError::FaceAlignmentFailed)?;

    // Run recognition model (512-d normalized embedding)
    let embedding = recognizer
        .extract_embedding(&aligned_tensor)
        .map_err(|e| ExtractError::Other(format!("Recognition inference failed: {e}")))?;

    if embedding.len() != 512 {
        return Err(ExtractError::Other(format!(
            "Unexpected embedding dimension: expected 512, got {}",
            embedding.len()
        )));
    }

    Ok(embedding)
}

/// Run extraction CLI command wrapper.
///
/// Outputs strictly to stdout:
/// - Success (exit 0): `{"success":true,"embedding_b64":"..."}`
///
/// Outputs strictly to stderr:
/// - Failure (exit 1): `{"error":"<message>"}`
pub fn run_extract(
    image_path: &Path,
    models_dir: Option<&str>,
    ort_path: Option<&str>,
) -> i32 {
    let base_dirs = model_search_dirs(models_dir);

    let detector_path = match find_model_path(&base_dirs, &["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"]) {
        Some(p) => p,
        None => {
            let err = serde_json::json!({
                "error": "Detector model not found in model search directories"
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
    };

    let recognizer_path = match find_model_path(&base_dirs, &["recognizer.onnx", "sface.onnx", "arcface.onnx"]) {
        Some(p) => p,
        None => {
            let err = serde_json::json!({
                "error": "Recognizer model not found in model search directories"
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
    };

    if let Err(e) = init_onnx_runtime(ort_path.map(Path::new)) {
        let err = serde_json::json!({
            "error": format!("Failed to initialize ONNX Runtime: {e}")
        });
        eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
        return 1;
    }

    let mut detector = match FaceDetector::load(&detector_path) {
        Ok(d) => d,
        Err(e) => {
            let err = serde_json::json!({
                "error": format!("Failed to load detector model from {:?}: {e}", detector_path)
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
    };

    let mut recognizer = match FaceRecognizer::load(&recognizer_path) {
        Ok(r) => r,
        Err(e) => {
            let err = serde_json::json!({
                "error": format!("Failed to load recognizer model from {:?}: {e}", recognizer_path)
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
    };

    let img = match load_image_with_exif(image_path) {
        Ok(i) => i,
        Err(ExtractError::CouldNotReadImage) => {
            let err = serde_json::json!({
                "error": "Could not read image"
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
        Err(e) => {
            let err = serde_json::json!({
                "error": e.to_string()
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            return 1;
        }
    };

    match extract_embedding(&img, &mut detector, &mut recognizer) {
        Ok(embedding) => {
            let b64 = embedding_to_b64(&embedding);
            let success_json = serde_json::json!({
                "success": true,
                "embedding_b64": b64
            });
            println!("{}", serde_json::to_string(&success_json).unwrap_or_default());
            0
        }
        Err(e) => {
            let err = serde_json::json!({
                "error": e.to_string()
            });
            eprintln!("{}", serde_json::to_string(&err).unwrap_or_default());
            1
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_compute_downscale_dimensions_already_small() {
        let (w, h, scale) = compute_downscale_dimensions(320, 240, 640);
        assert_eq!(w, 320);
        assert_eq!(h, 240);
        assert!((scale - 1.0).abs() < 1e-6);
    }

    #[test]
    fn test_compute_downscale_dimensions_large_photo() {
        let (w, h, scale) = compute_downscale_dimensions(4000, 3000, 640);
        assert!(w <= 640);
        assert!(h <= 640);
        assert_eq!(w, 640);
        assert_eq!(h, 480);
        assert!((scale - (640.0 / 4000.0)).abs() < 1e-4);
    }

    #[test]
    fn test_compute_downscale_dimensions_tall_photo() {
        let (w, h, scale) = compute_downscale_dimensions(1080, 1920, 640);
        assert!(w <= 640);
        assert!(h <= 640);
        assert_eq!(h, 640);
        assert_eq!(w, 360);
        assert!((scale - (640.0 / 1920.0)).abs() < 1e-4);
    }

    #[test]
    fn test_embedding_to_b64_produces_2048_bytes() {
        let dummy = vec![0.5f32; 512];
        let b64 = embedding_to_b64(&dummy);
        let decoded = base64::engine::general_purpose::STANDARD
            .decode(&b64)
            .expect("Base64 decode must succeed");

        assert_eq!(
            decoded.len(),
            2048,
            "512 float32 little endian must be exactly 2048 bytes"
        );
    }
}
