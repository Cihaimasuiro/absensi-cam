use anyhow::Result;
use std::time::Duration;
use tracing::info;

pub struct ArduinoBridge {
    pub port_name: String,
    pub baud_rate: u32,
}

impl ArduinoBridge {
    pub fn new(port_name: impl Into<String>, baud_rate: u32) -> Self {
        Self {
            port_name: port_name.into(),
            baud_rate,
        }
    }

    /// Kirim perintah aksi ke Arduino
    pub fn send_command(&self, cmd: &str) -> Result<()> {
        info!("Kirim serial ke Arduino [{}]: {}", self.port_name, cmd);
        // Membuka port serial dengan timeout singkat
        let mut port = serialport::new(&self.port_name, self.baud_rate)
            .timeout(Duration::from_millis(100))
            .open()?;

        use std::io::Write;
        writeln!(port, "{}", cmd)?;
        port.flush()?;
        Ok(())
    }
}
