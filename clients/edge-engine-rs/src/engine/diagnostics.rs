//! Modul Preflight Diagnostics untuk Smart Absensi Edge Engine
//!
//! Melakukan verifikasi awal lingkungan dan hardware (Python, ONNX Runtime,
//! Model AI, Kamera, GPIO) sebelum stream & AI loop dimulai.

#[cfg(target_os = "linux")]
use std::path::Path;

/// Jalankan diagnosis pra-terbang dan cetak hasilnya secara terstruktur.
/// Mengembalikan `true` jika semua komponen kritis tersedia.
pub fn run_preflight(models_dir: Option<&str>, ort_path: Option<&str>, inference_enabled: bool) -> bool {
    println!("==================================================");
    println!("  Smart Absensi Edge Engine — Preflight Check");
    println!("==================================================");

    let mut critical_ok = true;

    // 1. Platform & Arsitektur
    let arch = std::env::consts::ARCH;
    let os = std::env::consts::OS;
    let ptr_width = std::mem::size_of::<usize>() * 8;
    println!("[✓] Platform       : {} {} ({}-bit)", os, arch, ptr_width);

    // 2. Python Runtime
    #[cfg(feature = "native-camera")]
    println!("[i] Python Runtime : Dilewati (Driver kamera native nokhwa aktif)");

    #[cfg(not(feature = "native-camera"))]
    {
        let py_cmd = crate::vision::resolve_python_command();
        match py_cmd {
            Some(cmd) => {
                let ver = std::process::Command::new(&cmd)
                    .args(["-c", "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}')"])
                    .output()
                    .ok()
                    .and_then(|out| if out.status.success() { Some(String::from_utf8_lossy(&out.stdout).trim().to_string()) } else { None })
                    .unwrap_or_else(|| ">= 3.8".to_string());
                println!("[✓] Python Runtime : {} (v{})", cmd, ver);
            }
            None => {
                #[cfg(not(target_os = "linux"))]
                {
                    println!("[✗] Python Runtime : Tidak ditemukan Python >= 3.8 pada PATH / PYTHON_BIN");
                    critical_ok = false;
                }
                #[cfg(target_os = "linux")]
                println!("[i] Python Runtime : Tidak ditemukan (V4L2 native aktif, Python opsional)");
            }
        }
    }

    // 3. ONNX Runtime
    if !inference_enabled {
        println!("[i] ONNX Runtime   : Dilewati (--no-inference aktif)");
    } else {
        let ort_resolved = ort_path
            .map(std::path::PathBuf::from)
            .or_else(crate::vision::recognizer::find_onnxruntime_dylib);

        match ort_resolved {
            Some(ref p) if p.exists() => {
                println!("[✓] ONNX Runtime   : {:?}", p);
            }
            _ => {
                #[cfg(all(target_arch = "arm", target_os = "linux"))]
                {
                    println!("[✗] ONNX Runtime   : Tidak tersedia untuk ARM 32-bit (armv7/armhf)");
                    println!("    └─ Microsoft tidak merilis libonnxruntime.so untuk linux-arm32.");
                    if soc_supports_aarch64() {
                        println!("    └─ [i] SoC Anda mendukung aarch64 — flash OS 64-bit (Raspberry Pi OS 64-bit / Armbian aarch64) untuk inferensi penuh.");
                    } else {
                        println!("    └─ Alternatif: jalankan dengan --no-inference sebagai node capture.");
                    }
                }
                #[cfg(not(all(target_arch = "arm", target_os = "linux")))]
                #[cfg(target_pointer_width = "32")]
                println!("[i] ONNX Runtime   : Tidak tersedia untuk 32-bit (Gunakan mode --no-inference)");

                #[cfg(target_pointer_width = "64")]
                {
                    println!("[✗] ONNX Runtime   : Dylib tidak ditemukan");
                    #[cfg(target_os = "linux")]
                    {
                        println!("    └─ Perbaikan otomatis : sudo smart-absensi-repair");
                        println!("    └─ Lokasi standar     : /usr/lib/smart-absensi/libonnxruntime.so");
                    }
                    #[cfg(target_os = "windows")]
                    {
                        println!("    └─ Solusi: Jalankan scripts/package-onnxruntime.ps1 atau salin onnxruntime.dll ke direktori engine");
                    }
                    critical_ok = false;
                }
            }
        }
    }

    // 4. Model AI
    if !inference_enabled {
        println!("[i] Model AI       : Dilewati (--no-inference aktif)");
    } else {
        let base_dirs = crate::engine::model_search_dirs(models_dir);
        let det = crate::engine::find_model_path(&base_dirs, &["detector.onnx", "yunet_2023mar.onnx", "yunet.onnx"]);
        let rec = crate::engine::find_model_path(&base_dirs, &["recognizer.onnx", "sface.onnx", "arcface.onnx"]);

        match (&det, &rec) {
            (Some(d), Some(r)) => {
                println!("[✓] Model Detector : {:?}", d);
                println!("[✓] Model Recognizer: {:?}", r);
            }
            _ => {
                #[cfg(target_pointer_width = "32")]
                println!("[i] Model AI       : Dilewati (Mode capture 32-bit)");

                #[cfg(target_pointer_width = "64")]
                {
                    if det.is_none() {
                        println!("[✗] Model Detector : File detector.onnx / yunet.onnx tidak ditemukan");
                        critical_ok = false;
                    }
                    if rec.is_none() {
                        println!("[✗] Model Recognizer: File recognizer.onnx / sface.onnx tidak ditemukan");
                        critical_ok = false;
                    }
                }
            }
        }
    }

    // 5. Kamera
    #[cfg(target_os = "linux")]
    {
        let mut count = 0;
        for i in 0..10 {
            if Path::new(&format!("/dev/video{}", i)).exists() {
                count += 1;
            }
        }
        if count > 0 {
            println!("[✓] Kamera V4L2    : Terdeteksi {} perangkat video di /dev/video*", count);
        } else {
            println!("[!] Kamera V4L2    : Tidak ada perangkat /dev/video* terdeteksi");
        }
    }

    #[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
    {
        let native_cams = crate::vision::camera_win::query_native_camera_devices();
        if !native_cams.is_empty() {
            println!("[✓] Kamera Native  : Terdeteksi {} perangkat (Media Foundation / AVFoundation)", native_cams.len());
            for cam in &native_cams {
                println!("    └─ {}", cam);
            }
        } else {
            println!("[!] Kamera Native  : Driver nokhwa aktif, tetapi tidak ada perangkat kamera ditemukan");
        }
    }

    if let Some(selected) = crate::vision::auto_select_camera() {
        println!("    └─ [✓] Auto-select : Kamera '{}' dipilih otomatis tanpa perlu konfigurasi manual", selected);
    }

    #[cfg(all(not(target_os = "linux"), not(feature = "native-camera")))]
    {
        match crate::vision::resolve_python_command() {
            Some(ref py) => {
                let cv2_ok = std::process::Command::new(py)
                    .args(["-c", "import cv2; print(cv2.__version__)"])
                    .output()
                    .ok()
                    .filter(|o| o.status.success())
                    .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string());

                match cv2_ok {
                    Some(ver) => println!("[✓] OpenCV         : v{} via {}", ver, py),
                    None => {
                        println!("[✗] OpenCV         : Tidak terpasang pada {} (Jalankan: uv pip install --python \"{}\" opencv-python)", py, py);
                        critical_ok = false;
                    }
                }
            }
            None => {
                println!("[✗] Kamera Backend : Python >= 3.8 dengan modul OpenCV ('cv2') tidak ditemukan (Jalankan: uv python install 3.11 && uv pip install --python \"$(uv python find <=3.13)\" opencv-python)");
                critical_ok = false;
            }
        }
    }

    // 6. GPIO
    #[cfg(target_os = "linux")]
    {
        if Path::new("/sys/class/gpio").exists() {
            println!("[✓] GPIO Subsystem : /sys/class/gpio siap");
        } else {
            println!("[i] GPIO Subsystem : /sys/class/gpio tidak tersedia (dilewati)");
        }
    }

    #[cfg(not(target_os = "linux"))]
    {
        println!("[i] GPIO Subsystem : Non-Linux (dilewati)");
    }

    println!("--------------------------------------------------");
    if critical_ok {
        println!("Status Preflight: LULUS (Siap dijalankan)");
    } else {
        println!("Status Preflight: PERHATIAN / GAGAL (Terdapat dependensi kritis belum terpenuhi)");
    }
    println!("==================================================");

    critical_ok
}

/// Deteksi apakah SoC armv7 sebenarnya mampu aarch64 (Cortex-A53/A55/A72/A76).
#[cfg(all(target_arch = "arm", target_os = "linux"))]
fn soc_supports_aarch64() -> bool {
    std::fs::read_to_string("/proc/cpuinfo")
        .map(|s| {
            let l = s.to_lowercase();
            ["cortex-a53", "cortex-a55", "cortex-a72", "cortex-a73",
             "cortex-a76", "cortex-a78", "cortex-a35"]
                .iter().any(|m| l.contains(m))
        })
        .unwrap_or(false)
}
