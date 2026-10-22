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
    let mut parity_dir = manifest.join("tests/fixtures/parity");

    if !parity_dir.exists() {
        let root_parity = manifest.join("../../tests/fixtures/parity");
        if root_parity.exists() {
            parity_dir = root_parity;
        } else {
            panic!("Parity fixtures directory {:?} does not exist. Parity test must fail, not skip.", parity_dir);
        }
    }

    let entries = fs::read_dir(&parity_dir)
        .expect("Cannot read parity fixtures directory");

    let mut found_fixture = false;

    // Jika direktori fixture tersedia, model dan ORT wajib ada serta valid
    let det_path = find_model(&["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"])
        .expect("detector.onnx model is required to run extract_parity_test");

    let rec_path = find_model(&["recognizer.onnx", "sface.onnx", "arcface.onnx"])
        .expect("recognizer.onnx model is required to run extract_parity_test");

    let ort_dylib = std::env::var("ORT_DYLIB_PATH").ok();
    init_onnx_runtime(ort_dylib.as_deref().map(std::path::Path::new))
        .expect("ONNX Runtime must initialize successfully for extract_parity_test");

    let mut detector = FaceDetector::load(&det_path)
        .expect("Face detector must load successfully from detector.onnx");

    let mut recognizer = FaceRecognizer::load(&rec_path)
        .expect("Face recognizer must load successfully from recognizer.onnx");

    for entry in entries.flatten() {
        let path = entry.path();
        let is_image = path.extension().and_then(|s| s.to_str()).map(|ext| {
            ext.eq_ignore_ascii_case("jpg") || ext.eq_ignore_ascii_case("jpeg") || ext.eq_ignore_ascii_case("png")
        }).unwrap_or(false);

        if is_image {
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
        panic!("No matching (*.jpg/*.jpeg/*.png with *.json) fixtures found in {:?}", parity_dir);
    }
}
