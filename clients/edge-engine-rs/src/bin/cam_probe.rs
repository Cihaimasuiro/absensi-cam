//! Alat Pengujian Capture Kamera Native (nokhwa)

#[cfg(all(not(target_os = "linux"), feature = "native-camera"))]
fn main() -> anyhow::Result<()> {
    use nokhwa::{
        pixel_format::RgbFormat,
        utils::{CameraIndex, RequestedFormat, RequestedFormatType},
        Camera,
    };

    println!("[i] Memeriksa perangkat kamera native via nokhwa...");
    let list = nokhwa::query(nokhwa::utils::ApiBackend::Auto)?;
    if list.is_empty() {
        println!("[!] Tidak ada perangkat kamera native terdeteksi.");
        return Ok(());
    }
    for (i, c) in list.iter().enumerate() {
        println!("[✓] Kamera {}: {:?}", i, c);
    }

    let args: Vec<String> = std::env::args().collect();
    let dev_idx: u32 = args.get(1).and_then(|s| s.parse().ok()).unwrap_or(0);

    let format = RequestedFormat::new::<RgbFormat>(
        RequestedFormatType::AbsoluteHighestFrameRate,
    );
    let mut cam = Camera::new(CameraIndex::Index(dev_idx), format)?;
    cam.open_stream()?;
    println!("[✓] Stream kamera terbuka, mengambil 5 frame sampel...");
    for i in 0..5 {
        let f = cam.frame()?;
        let img = f.decode_image::<RgbFormat>()?;
        let raw = img.as_raw();
        let mean = if !raw.is_empty() {
            raw.iter().map(|&b| b as f64).sum::<f64>() / raw.len() as f64
        } else {
            0.0
        };
        println!("  - Frame {}: {}x{} (mean luma: {:.1})", i, img.width(), img.height(), mean);
    }
    cam.stop_stream()?;
    println!("[✓] Pengujian kamera native selesai dengan sukses.");
    Ok(())
}

#[cfg(not(all(not(target_os = "linux"), feature = "native-camera")))]
fn main() {
    println!("Fitur 'native-camera' hanya untuk Windows/macOS. Pada Linux, gunakan driver kernel V4L2 native.");
}
