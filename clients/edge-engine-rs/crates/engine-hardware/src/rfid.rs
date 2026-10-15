use anyhow::{Context, Result};
use std::io::{BufRead, BufReader};
use std::path::Path;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;
use tokio::sync::mpsc;
use tracing::{info, warn};

/// Event ketika kartu RFID berhasil di-tap pada terminal
#[derive(Debug, Clone)]
pub struct RfidTapEvent {
    pub card_uid: String,
    pub reader_type: &'static str,
}

/// Pembaca kartu RFID berbasis Serial/UART (USB RFID reader, Wiegand-to-UART, dll.)
pub struct SerialRfidReader {
    port_name: String,
    baud_rate: u32,
    running: Arc<AtomicBool>,
}

impl SerialRfidReader {
    pub fn new(port_name: impl Into<String>, baud_rate: u32) -> Self {
        Self {
            port_name: port_name.into(),
            baud_rate,
            running: Arc::new(AtomicBool::new(true)),
        }
    }

    /// Mulai pembacaan kartu di background thread
    pub fn start_reading(&self, tx: mpsc::Sender<RfidTapEvent>) -> Result<()> {
        let port_name = self.port_name.clone();
        let baud_rate = self.baud_rate;
        let running = self.running.clone();

        thread::Builder::new()
            .name("serial-rfid-worker".to_string())
            .spawn(move || {
                info!("Serial RFID Reader aktif di [{}] ({} baud)", port_name, baud_rate);

                while running.load(Ordering::Relaxed) {
                    let port = match serialport::new(&port_name, baud_rate)
                        .timeout(Duration::from_millis(500))
                        .open()
                    {
                        Ok(p) => p,
                        Err(e) => {
                            warn!("Gagal membuka port RFID [{}]: {:#}. Mencoba lagi dalam 3 detik...", port_name, e);
                            thread::sleep(Duration::from_secs(3));
                            continue;
                        }
                    };

                    let mut reader = BufReader::new(port);
                    let mut line_buf = String::new();

                    while running.load(Ordering::Relaxed) {
                        line_buf.clear();
                        match reader.read_line(&mut line_buf) {
                            Ok(0) => {
                                // Port closed
                                break;
                            }
                            Ok(_) => {
                                let line = line_buf.trim();
                                if line.is_empty() {
                                    continue;
                                }

                                // Cek apakah format JSON (misal dari Arduino atau reader cerdas)
                                if let Ok(val) = serde_json::from_str::<serde_json::Value>(line) {
                                    if let Some(uid) = val.get("uid").or_else(|| val.get("card")).and_then(|v| v.as_str()) {
                                        info!("RFID Terbaca via JSON: UID=[{}]", uid);
                                        let evt = RfidTapEvent {
                                            card_uid: uid.to_string(),
                                            reader_type: "arduino_rfid",
                                        };
                                        let _ = tx.blocking_send(evt);
                                        continue;
                                    }
                                }

                                // Format raw text line (standard USB RFID HID/CDC reader)
                                let uid = line.trim_matches('\0').to_string();
                                if uid.len() >= 4 {
                                    info!("RFID Terbaca: UID=[{}]", uid);
                                    let evt = RfidTapEvent {
                                        card_uid: uid,
                                        reader_type: "serial_rfid",
                                    };
                                    let _ = tx.blocking_send(evt);
                                }
                            }
                            Err(ref e) if e.kind() == std::io::ErrorKind::TimedOut => {
                                // Timeout normal, lanjutkan loop
                            }
                            Err(e) => {
                                warn!("Error membaca port RFID: {:#}", e);
                                break;
                            }
                        }
                    }
                }
                info!("Serial RFID worker thread berhenti.");
            })
            .context("Failed to spawn serial rfid thread")?;

        Ok(())
    }

    pub fn stop(&self) {
        self.running.store(false, Ordering::Relaxed);
    }
}

/// Deteksi otomatis apakah ada device RFID (serial atau SPI) yang terpasang di sistem
pub fn detect_rfid_device() -> Option<String> {
    let candidates = [
        "/dev/ttyUSB0",
        "/dev/ttyUSB1",
        "/dev/ttyACM0",
        "/dev/ttyACM1",
        "/dev/ttyS1",
        "/dev/ttyS2",
    ];

    for dev in &candidates {
        if Path::new(dev).exists() {
            return Some((*dev).to_string());
        }
    }
    None
}
