use anyhow::Result;
use ort::session::builder::GraphOptimizationLevel;
use ort::session::Session;
use ort::value::Value;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};

pub const REFERENCE_POINTS: [[f32; 2]; 5] = [
    [38.2946, 51.6963], // Left eye
    [73.5318, 51.5014], // Right eye
    [56.0252, 71.7366], // Nose
    [41.5493, 92.3655], // Left mouth
    [70.7299, 92.2041], // Right mouth
];

static ONNX_INITIALIZED: AtomicBool = AtomicBool::new(false);

fn find_file_recursive(dir: &Path, prefix: &str, depth: usize) -> Option<PathBuf> {
    if depth == 0 || !dir.exists() {
        return None;
    }
    let entries = std::fs::read_dir(dir).ok()?;
    for entry in entries.flatten() {
        let path = entry.path();
        if path.is_file() || path.is_symlink() {
            if let Some(name) = path.file_name().and_then(|n| n.to_str()) {
                if name.starts_with(prefix) {
                    return Some(path);
                }
            }
        } else if path.is_dir() {
            let name = entry.file_name();
            let name_str = name.to_string_lossy();
            if name_str == ".git" || name_str == "__pycache__" || name_str == "node_modules" {
                continue;
            }
            if let Some(found) = find_file_recursive(&path, prefix, depth - 1) {
                return Some(found);
            }
        }
    }
    None
}

/// Find libonnxruntime.so dynamic library across system, virtualenvs, conda, and app paths
pub fn find_onnxruntime_dylib() -> Option<PathBuf> {
    if let Ok(path) = std::env::var("ORT_DYLIB_PATH") {
        if Path::new(&path).exists() {
            return Some(PathBuf::from(path));
        }
    }

    #[cfg(target_os = "windows")]
    let explicit_candidates = [
        "target\\debug\\onnxruntime.dll",
        "target\\release\\onnxruntime.dll",
        "onnxruntime.dll",
    ];

    #[cfg(not(target_os = "windows"))]
    let explicit_candidates = [
        "/usr/local/lib/libonnxruntime.so",
        "/usr/lib/libonnxruntime.so",
        "/usr/lib/aarch64-linux-gnu/libonnxruntime.so",
        "/opt/smart-absensi/edge-engine/models/libonnxruntime.so",
        "./libonnxruntime.so",
    ];

    for path in &explicit_candidates {
        if Path::new(path).exists() {
            return Some(PathBuf::from(path));
        }
    }

    // Search inside python virtual environments & app directories
    let search_roots = [
        "/opt/smart-absensi/edge-engine/miniforge3/envs",
        "/opt/smart-absensi/edge-engine/venv",
        "/opt/smart-absensi/edge-engine",
        "/home/edge",
        "/usr/local/lib",
        "/usr/lib/aarch64-linux-gnu",
    ];

    for root in &search_roots {
        if let Some(found) = find_file_recursive(Path::new(root), "libonnxruntime.so", 12) {
            return Some(found);
        }
    }

    None
}

/// Initialize ONNX Runtime environment, checking well-known paths on Orange Pi / Linux
pub fn init_onnx_runtime(custom_path: Option<&Path>) -> Result<()> {
    if ONNX_INITIALIZED.load(Ordering::SeqCst) {
        return Ok(());
    }

    let resolved_path = custom_path
        .map(|p| p.to_path_buf())
        .or_else(find_onnxruntime_dylib);

    if let Some(path) = resolved_path {
        eprintln!("[ONNX Runtime] Memuat dylib dari: {:?}", path);
        tracing::info!("Menginisialisasi ONNX Runtime dari: {:?}", path);
        ort::init_from(path.to_str().unwrap())
            .map_err(|e| anyhow::anyhow!("Gagal inisialisasi ONNX Runtime dari {:?}: {e}", path))?
            .commit();
        eprintln!("[ONNX Runtime] Berhasil diinisialisasi.");
        ONNX_INITIALIZED.store(true, Ordering::SeqCst);
        Ok(())
    } else {
        #[cfg(target_os = "linux")]
        {
            anyhow::bail!(
                "libonnxruntime.so tidak ditemukan di sistem. \
                 Mohon tentukan lokasi melalui argumen --ort-path atau variabel ORT_DYLIB_PATH"
            );
        }
        #[cfg(not(target_os = "linux"))]
        {
            ort::init().commit();
            ONNX_INITIALIZED.store(true, Ordering::SeqCst);
            Ok(())
        }
    }
}

pub struct FaceRecognizer {
    session: Session,
}

impl FaceRecognizer {
    pub fn load<P: AsRef<Path>>(model_path: P) -> Result<Self> {
        let session = Session::builder()
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_optimization_level(GraphOptimizationLevel::Level3)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .with_intra_threads(1)
            .map_err(|e| anyhow::anyhow!("{e}"))?
            .commit_from_file(model_path)
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        Ok(Self { session })
    }

    /// Extract 512-d normalized embedding from preprocessed (112, 112, 3) NCHW float tensor
    pub fn extract_embedding(&mut self, nchw_input: &[f32]) -> Result<Vec<f32>> {
        anyhow::ensure!(
            nchw_input.len() == 3 * 112 * 112,
            "Input tensor must have shape [1, 3, 112, 112]"
        );

        let input_value = Value::from_array(([1usize, 3, 112, 112], nchw_input.to_vec()))
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let outputs = self
            .session
            .run(ort::inputs![input_value])
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let (_, slice) = outputs[0]
            .try_extract_tensor::<f32>()
            .map_err(|e| anyhow::anyhow!("{e}"))?;

        let mut embedding: Vec<f32> = slice.to_vec();

        // L2 normalization
        let norm: f32 = embedding.iter().map(|x| x * x).sum::<f32>().sqrt();
        if norm > 1e-6 {
            for val in &mut embedding {
                *val /= norm;
            }
        }

        Ok(embedding)
    }

    /// Calculate cosine similarity between two 512-d embeddings
    pub fn cosine_similarity(v1: &[f32], v2: &[f32]) -> f32 {
        if v1.len() != v2.len() || v1.is_empty() {
            return 0.0;
        }

        let mut dot = 0.0;
        for (a, b) in v1.iter().zip(v2.iter()) {
            dot += a * b;
        }
        dot
    }
}
