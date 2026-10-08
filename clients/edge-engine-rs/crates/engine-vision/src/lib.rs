use anyhow::Result;

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
