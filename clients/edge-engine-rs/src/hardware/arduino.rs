use anyhow::Result;
use serde_json::json;
use std::io::Write;
use std::sync::Mutex;
use std::time::Duration;
use tracing::{info, warn};

pub struct ArduinoBridge {
    pub port_name: String,
    pub baud_rate: u32,
    port: Mutex<Option<Box<dyn serialport::SerialPort>>>,
}

impl ArduinoBridge {
    pub fn new(port_name: impl Into<String>, baud_rate: u32) -> Self {
        Self {
            port_name: port_name.into(),
            baud_rate,
            port: Mutex::new(None),
        }
    }

    /// Coba buka koneksi serial ke Arduino
    pub fn connect(&self) -> bool {
        let mut guard = self.port.lock().unwrap();
        match serialport::new(&self.port_name, self.baud_rate)
            .timeout(Duration::from_millis(150))
            .open()
        {
            Ok(p) => {
                info!("ArduinoBridge: Berhasil terhubung ke serial {}", self.port_name);
                *guard = Some(p);
                true
            }
            Err(e) => {
                warn!("ArduinoBridge: Gagal membuka serial {}: {:#}", self.port_name, e);
                *guard = None;
                false
            }
        }
    }

    /// Kirim payload JSON ke Arduino
    pub fn send_json(&self, val: &serde_json::Value) -> Result<()> {
        let mut guard = self.port.lock().unwrap();
        if guard.is_none() {
            // Coba auto-reconnect
            if let Ok(p) = serialport::new(&self.port_name, self.baud_rate)
                .timeout(Duration::from_millis(150))
                .open()
            {
                *guard = Some(p);
            }
        }

        if let Some(port) = guard.as_mut() {
            let mut line = serde_json::to_string(val)?;
            line.push('\n');
            port.write_all(line.as_bytes())?;
            port.flush()?;
        }
        Ok(())
    }

    pub fn standby(&self, msg: Option<&str>) -> Result<()> {
        let payload = json!({
            "cmd": "standby",
            "msg": msg.unwrap_or("SILAKAN HADAP KAMERA")
        });
        self.send_json(&payload)
    }

    pub fn processing(&self) -> Result<()> {
        let payload = json!({
            "cmd": "display",
            "status": "PROCESSING"
        });
        self.send_json(&payload)
    }

    pub fn recognized(&self, name: &str, time: &str) -> Result<()> {
        let payload = json!({
            "cmd": "display",
            "status": "RECOGNIZED",
            "name": name,
            "time": time
        });
        self.send_json(&payload)?;
        self.io(Some("green"), Some("2short"))
    }

    pub fn unknown(&self) -> Result<()> {
        let payload = json!({
            "cmd": "display",
            "status": "UNKNOWN"
        });
        self.send_json(&payload)?;
        self.io(Some("red"), Some("1long"))
    }

    pub fn spoof(&self) -> Result<()> {
        let payload = json!({
            "cmd": "display",
            "status": "SPOOF"
        });
        self.send_json(&payload)?;
        self.io(Some("red"), Some("1long"))
    }

    pub fn io(&self, led: Option<&str>, buzzer: Option<&str>) -> Result<()> {
        let payload = json!({
            "cmd": "io",
            "led": led.unwrap_or("off"),
            "buzzer": buzzer.unwrap_or("off")
        });
        self.send_json(&payload)
    }
}
