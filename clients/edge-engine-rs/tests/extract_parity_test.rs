mod common;

use base64::Engine;
use common::find_model;
use smart_absensi_engine::engine::extract::{extract_embedding, load_image_with_exif};
use smart_absensi_engine::vision::recognizer::{init_onnx_runtime, FaceRecognizer};
use smart_absensi_engine::vision::FaceDetector;
use std::fs;
use std::path::PathBuf;

#[test]
fn test_python_rust_extraction_parity() {
    let manifest = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let parity_dir = manifest.join("tests/fixtures/parity");

    if !parity_dir.exists() {
        println!("[SKIP] Parity fixtures directory {:?} does not exist, skipping.", parity_dir);
        return;
    }

    let entries = match fs::read_dir(&parity_dir) {
        Ok(e) => e,
        Err(_) => {
            println!("[SKIP] Cannot read parity fixtures directory, skipping.");
            return;
        }
    };

    let mut found_fixture = false;

    // Initialize models
    let det_path = match find_model(&["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"]) {
        Some(p) => p,
        None => {
            println!("[SKIP] detector.onnx not found for parity test, skipping.");
            return;
        }
    };

    let rec_path = match find_model(&["recognizer.onnx", "sface.onnx", "arcface.onnx"]) {
        Some(p) => p,
        None => {
            println!("[SKIP] recognizer.onnx not found for parity test, skipping.");
            return;
        }
    };

    if let Err(e) = init_onnx_runtime(None) {
        println!("[SKIP] Failed to initialize ONNX Runtime: {e}, skipping.");
        return;
    }

    let mut detector = match FaceDetector::load(&det_path) {
        Ok(d) => d,
        Err(e) => {
            println!("[SKIP] Failed to load detector: {e}, skipping.");
            return;
        }
    };

    let mut recognizer = match FaceRecognizer::load(&rec_path) {
        Ok(r) => r,
        Err(e) => {
            println!("[SKIP] Failed to load recognizer: {e}, skipping.");
            return;
        }
    };

    for entry in entries.flatten() {
        let path = entry.path();
        if path.extension().and_then(|s| s.to_str()).map(|s| s.eq_ignore_ascii_case("jpg") || s.eq_ignore_ascii_case("jpeg")).unwrap_or(false) {
            let json_path = path.with_extension("json");
            if !json_path.exists() {
                continue;
            }

            found_fixture = true;
            let json_content = fs::read_to_string(&json_path).expect("Failed to read reference json");
            let parsed: serde_json::Value = serde_json::from_str(&json_content).expect("Failed to parse reference json");

            let ref_b64 = parsed["embedding_b64"].as_str().expect("Fixture json missing embedding_b64");
            let ref_bytes = base64::engine::general_purpose::STANDARD
                .decode(ref_b64)
                .expect("Failed to decode reference base64");

            assert_eq!(ref_bytes.len(), 2048, "Reference embedding must be 2048 bytes");

            let mut ref_embedding = vec![0.0f32; 512];
            for i in 0..512 {
                let bytes: [u8; 4] = [
                    ref_bytes[i * 4],
                    ref_bytes[i * 4 + 1],
                    ref_bytes[i * 4 + 2],
                    ref_bytes[i * 4 + 3],
                ];
                ref_embedding[i] = f32::from_le_bytes(bytes);
            }

            let img = load_image_with_exif(&path).expect("Failed to load fixture image");
            let rust_embedding = extract_embedding(&img, &mut detector, &mut recognizer)
                .expect("Rust extraction must succeed on parity fixture");

            let similarity = FaceRecognizer::cosine_similarity(&rust_embedding, &ref_embedding);
            println!(
                "Fixture {:?} - Cosine similarity with Python reference: {:.4}",
                path.file_name().unwrap_or_default(),
                similarity
            );

            assert!(
                similarity >= 0.99,
                "Cosine similarity {:.4} for {:?} is below parity threshold 0.99",
                similarity,
                path
            );
        }
    }

    if !found_fixture {
        println!("[SKIP] No *.jpg with matching *.json found in {:?}, skipping.", parity_dir);
    }
}
