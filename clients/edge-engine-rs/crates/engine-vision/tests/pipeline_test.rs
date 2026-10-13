use engine_vision::{
    align_face_to_tensor_nchw, init_onnx_runtime, FaceDetector, FaceRecognizer, LivenessDetector,
};
use std::path::{Path, PathBuf};

fn find_model(names: &[&str]) -> Option<PathBuf> {
    let manifest = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let dirs = [
        manifest.join("../../../packages/models"),
        manifest.join("../../target/aarch64-unknown-linux-gnu/release"),
        manifest.join("../../../archive/edge-engine-py/models"),
    ];

    for dir in &dirs {
        for name in names {
            let p = dir.join(name);
            if p.exists() {
                return Some(p);
            }
        }
    }
    None
}

#[test]
fn test_full_vision_pipeline_local() {
    println!("\n=== Testing Pure-Rust AI Vision Pipeline Locally ===");

    // 1. Initialize ONNX Runtime
    let init_res = init_onnx_runtime(None);
    assert!(init_res.is_ok(), "Failed to initialize ONNX Runtime: {:?}", init_res.err());
    println!("[1/5] ONNX Runtime loaded successfully.");

    // 2. Locate models
    let det_path = find_model(&["detector.onnx", "yunet_2023mar.onnx"]).expect("detector.onnx not found");
    let rec_path = find_model(&["recognizer.onnx", "sface.onnx"]).expect("recognizer.onnx not found");
    let liv_path = find_model(&["liveness.onnx", "minifasnet.onnx"]).expect("liveness.onnx not found");

    println!("[2/5] Models located:");
    println!("      - Detector   : {:?}", det_path);
    println!("      - Recognizer : {:?}", rec_path);
    println!("      - Liveness   : {:?}", liv_path);

    let mut detector = FaceDetector::load(&det_path).expect("Failed to load FaceDetector");
    detector.conf_threshold = 0.5;
    let mut recognizer = FaceRecognizer::load(&rec_path).expect("Failed to load FaceRecognizer");
    let mut liveness = LivenessDetector::load(&liv_path).expect("Failed to load LivenessDetector");
    println!("[3/5] All 3 AI models instantiated successfully.");

    // 3. Load test face image
    let manifest = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let img_path = manifest.join("test_face.png");
    assert!(img_path.exists(), "test_face.png not found in crate directory");
    let dyn_img = image::open(img_path).expect("Failed to open test_face.png").to_rgb8();
    let (width, height) = dyn_img.dimensions();
    let rgb_bytes = dyn_img.into_raw();

    println!("[4/5] Test face image loaded: {}x{} (RGB bytes: {})", width, height, rgb_bytes.len());

    // 4. Run YuNet face detection
    let start_det = std::time::Instant::now();
    let faces = detector
        .detect(&rgb_bytes, width as usize, height as usize, (width * 3) as usize)
        .expect("Detection failed");
    let det_time = start_det.elapsed();

    println!("      Detected {} face(s) in {:?}", faces.len(), det_time);
    assert!(!faces.is_empty(), "Face detection failed: no face found in test_face.png");

    for (idx, face) in faces.iter().enumerate() {
        println!(
            "      Face #{}: bbox=[{:.1}, {:.1}, {:.1}, {:.1}], score={:.2}",
            idx + 1, face.bbox[0], face.bbox[1], face.bbox[2], face.bbox[3], face.score
        );
        println!(
            "              right_eye=[{:.1}, {:.1}], left_eye=[{:.1}, {:.1}], nose=[{:.1}, {:.1}]",
            face.landmarks[0][0], face.landmarks[0][1],
            face.landmarks[1][0], face.landmarks[1][1],
            face.landmarks[2][0], face.landmarks[2][1]
        );

        // 5. Evaluate Liveness
        let start_liv = std::time::Instant::now();
        let liv_res = liveness
            .evaluate(&rgb_bytes, width as usize, height as usize, (width * 3) as usize, &face.bbox)
            .expect("Liveness evaluation failed");
        let liv_time = start_liv.elapsed();

        println!(
            "      Liveness: is_real={}, prob_real={:.2}%, logit_diff={:.2} (evaluated in {:?})",
            liv_res.is_real, liv_res.prob_real * 100.0, liv_res.logit_diff, liv_time
        );

        // 6. Umeyama alignment
        let aligned = align_face_to_tensor_nchw(
            &rgb_bytes,
            width as usize,
            height as usize,
            (width * 3) as usize,
            &face.landmarks,
        )
        .expect("Umeyama face alignment failed");
        assert_eq!(aligned.len(), 3 * 112 * 112);

        // 7. ArcFace feature extraction
        let start_rec = std::time::Instant::now();
        let embedding = recognizer.extract_embedding(&aligned).expect("Feature extraction failed");
        let rec_time = start_rec.elapsed();

        assert_eq!(embedding.len(), 512, "ArcFace embedding must be 512 dimensions");
        let norm: f32 = embedding.iter().map(|x| x * x).sum::<f32>().sqrt();
        assert!((norm - 1.0).abs() < 1e-4, "Embedding must be L2-normalized, got norm={}", norm);

        let self_similarity = FaceRecognizer::cosine_similarity(&embedding, &embedding);
        println!(
            "      ArcFace Embedding: 512-d (L2-norm={:.4}), self-similarity={:.4} (in {:?})",
            norm, self_similarity, rec_time
        );
        assert!((self_similarity - 1.0).abs() < 1e-4, "Self-similarity must equal 1.0");
    }

    println!("[5/5] Full Vision & AI pipeline passed 100% with zero errors.\n");
}
