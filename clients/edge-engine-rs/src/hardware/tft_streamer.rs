use anyhow::{Context, Result};
use std::io::{Read, Write};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tracing::{debug, info, warn};

/// Magic header bytes penanda paket frame biner RLE
pub const MAGIC_SYNC_HI: u8 = 0xFF;
pub const MAGIC_SYNC_LO: u8 = 0xAA;
pub const ACK_BYTE: u8 = 0x06;

/// Streamer RLE berkecepatan tinggi ke layar TFT Arduino Uno (Dual-Mode Protocol)
pub struct TftRleStreamer {
    port_name: String,
    baud_rate: u32,
    port: Option<Box<dyn serialport::SerialPort>>,
    active: Arc<AtomicBool>,
}

impl TftRleStreamer {
    pub fn new(port_name: impl Into<String>, baud_rate: u32) -> Self {
        Self {
            port_name: port_name.into(),
            baud_rate,
            port: None,
            active: Arc::new(AtomicBool::new(true)),
        }
    }

    /// Sambungkan serial port (rekomendasi baud rate: 115200 atau 2000000)
    pub fn connect(&mut self) -> Result<()> {
        let port = serialport::new(&self.port_name, self.baud_rate)
            .timeout(Duration::from_millis(50))
            .open()
            .with_context(|| format!("Gagal membuka serial port TFT streamer [{}]", self.port_name))?;

        info!(
            "TFT RLE Streamer terhubung ke [{}] @ {} baud",
            self.port_name, self.baud_rate
        );
        self.port = Some(port);
        Ok(())
    }

    /// Konversi citra RGB24 ke paket biner RLE RGB565 dengan kuantisasi bit (0xF7DE)
    /// Menghasilkan header [0xFF, 0xAA, X_hi, X_lo, Y_hi, Y_lo, W_hi, W_lo, H_hi, H_lo]
    /// diikuti triplet [count, color_hi, color_lo] dan diakhiri byte 0x00.
    #[allow(clippy::too_many_arguments)]
    pub fn encode_frame_rle(
        src_rgb: &[u8],
        src_w: u32,
        src_h: u32,
        dst_x: u16,
        dst_y: u16,
        dst_w: u16,
        dst_h: u16,
        quantize: bool,
    ) -> Vec<u8> {
        let mut out = Vec::with_capacity((dst_w as usize * dst_h as usize) / 2 + 16);

        // 1. Header (10 bytes): Magic Sync + Window Rect (X, Y, W, H)
        out.push(MAGIC_SYNC_HI);
        out.push(MAGIC_SYNC_LO);
        out.extend_from_slice(&dst_x.to_be_bytes());
        out.extend_from_slice(&dst_y.to_be_bytes());
        out.extend_from_slice(&dst_w.to_be_bytes());
        out.extend_from_slice(&dst_h.to_be_bytes());

        // 2. Scanline RLE Compression
        let mut current_color: Option<u16> = None;
        let mut current_count: u8 = 0;

        for dy in 0..dst_h {
            let sy = ((dy as u32) * src_h) / (dst_h as u32);
            for dx in 0..dst_w {
                let sx = ((dx as u32) * src_w) / (dst_w as u32);
                let src_idx = ((sy * src_w + sx) * 3) as usize;

                let (r, g, b) = if src_idx + 2 < src_rgb.len() {
                    (src_rgb[src_idx], src_rgb[src_idx + 1], src_rgb[src_idx + 2])
                } else {
                    (0, 0, 0)
                };

                // Konversi ke RGB565 (R:5, G:6, B:5)
                let r5 = (r >> 3) as u16;
                let g6 = (g >> 2) as u16;
                let b5 = (b >> 3) as u16;
                let mut rgb565 = (r5 << 11) | (g6 << 5) | b5;

                // Kuantisasi Bit (Bit-Masking 0xF7DE): buang LSB noise kamera untuk memaksimalkan RLE
                if quantize {
                    rgb565 &= 0xF7DE;
                }

                if let Some(color) = current_color {
                    if color == rgb565 && current_count < 255 {
                        current_count += 1;
                    } else {
                        // Tulis triplet [Count, Hi, Lo]
                        out.push(current_count);
                        out.push((color >> 8) as u8);
                        out.push((color & 0xFF) as u8);

                        current_color = Some(rgb565);
                        current_count = 1;
                    }
                } else {
                    current_color = Some(rgb565);
                    current_count = 1;
                }
            }
        }

        // Tulis sisa piksel terakhir
        if let Some(color) = current_color {
            out.push(current_count);
            out.push((color >> 8) as u8);
            out.push((color & 0xFF) as u8);
        }

        // 3. End of Frame Delimiter (count = 0)
        out.push(0x00);

        out
    }

    /// Kirim frame RLE ke Arduino dengan flow control ACK
    pub fn send_frame(&mut self, rle_packet: &[u8]) -> Result<bool> {
        if !self.active.load(Ordering::Relaxed) {
            return Ok(false);
        }

        let port = match self.port.as_mut() {
            Some(p) => p,
            None => {
                self.connect()?;
                self.port.as_mut().unwrap()
            }
        };

        // Kirim seluruh paket biner
        port.write_all(rle_packet)?;
        port.flush()?;

        // Tunggu handshake ACK (0x06) dari Arduino (timeout 50ms)
        let mut ack_buf = [0u8; 1];
        match port.read_exact(&mut ack_buf) {
            Ok(_) => {
                if ack_buf[0] == ACK_BYTE {
                    debug!("TFT RLE Frame ACK diterima");
                    Ok(true)
                } else {
                    debug!("TFT non-ACK byte diterima: 0x{:02X}", ack_buf[0]);
                    Ok(true)
                }
            }
            Err(ref e) if e.kind() == std::io::ErrorKind::TimedOut => {
                // Jangan crash jika timeout, lewati frame untuk menjaga stabilitas
                debug!("TFT RLE ACK timeout");
                Ok(false)
            }
            Err(e) => {
                warn!("Error membaca serial TFT: {:#}", e);
                Err(e.into())
            }
        }
    }

    pub fn stop(&mut self) {
        self.active.store(false, Ordering::Relaxed);
        self.port = None;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_encode_frame_rle_header_and_delimiter() {
        // Citra 4x4 warna merah murni (255, 0, 0)
        let red_rgb = vec![
            255, 0, 0,  255, 0, 0,  255, 0, 0,  255, 0, 0,
            255, 0, 0,  255, 0, 0,  255, 0, 0,  255, 0, 0,
            255, 0, 0,  255, 0, 0,  255, 0, 0,  255, 0, 0,
            255, 0, 0,  255, 0, 0,  255, 0, 0,  255, 0, 0,
        ];

        let rle = TftRleStreamer::encode_frame_rle(&red_rgb, 4, 4, 10, 20, 4, 4, true);

        // Header harus diawali 0xFF 0xAA
        assert_eq!(rle[0], MAGIC_SYNC_HI);
        assert_eq!(rle[1], MAGIC_SYNC_LO);

        // Rect: X=10, Y=20, W=4, H=4 (masing-masing 2 bytes big-endian)
        assert_eq!(&rle[2..4], &10u16.to_be_bytes());
        assert_eq!(&rle[4..6], &20u16.to_be_bytes());
        assert_eq!(&rle[6..8], &4u16.to_be_bytes());
        assert_eq!(&rle[8..10], &4u16.to_be_bytes());

        // Body: 16 piksel merah dalam 1 triplet RLE [count=16, hi, lo]
        assert_eq!(rle[10], 16); // count
        // Warna merah RGB565 terkuantisasi: ((31 << 11) | 0 | 0) & 0xF7DE = 0xF800 & 0xF7DE = 0xF000
        let color_hi = rle[11];
        let color_lo = rle[12];
        let color_u16 = ((color_hi as u16) << 8) | (color_lo as u16);
        assert_eq!(color_u16 & 0xF800, 0xF000);

        // Footer: delimiter 0x00
        assert_eq!(rle[13], 0x00);
        assert_eq!(rle.len(), 14);
    }
}

