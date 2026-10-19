#[cfg(target_os = "linux")]
use anyhow::Result;
#[cfg(target_os = "linux")]
use std::fs;
#[cfg(target_os = "linux")]
use std::path::Path;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;
use tracing::{info, warn};

/// Direct Linux sysfs GPIO controller for LEDs and Buzzer.
/// Works out-of-the-box on Allwinner H6 (Orange Pi Lite 2) and all Linux SBCs.
#[derive(Clone)]
pub struct LinuxGpio {
    green_pin: Option<u32>,
    red_pin: Option<u32>,
    buzzer_pin: Option<u32>,
    active: Arc<AtomicBool>,
}

impl LinuxGpio {
    pub fn new(green_pin: Option<u32>, red_pin: Option<u32>, buzzer_pin: Option<u32>) -> Self {
        let instance = Self {
            green_pin,
            red_pin,
            buzzer_pin,
            active: Arc::new(AtomicBool::new(true)),
        };

        // Export and set direction for available pins on Linux
        #[cfg(target_os = "linux")]
        {
            if let Some(pin) = green_pin {
                let _ = instance.export_pin(pin);
            }
            if let Some(pin) = red_pin {
                let _ = instance.export_pin(pin);
            }
            if let Some(pin) = buzzer_pin {
                let _ = instance.export_pin(pin);
            }
        }

        instance
    }

    #[cfg(target_os = "linux")]
    fn export_pin(&self, pin: u32) -> Result<()> {
        let pin_dir = format!("/sys/class/gpio/gpio{}", pin);
        if !Path::new(&pin_dir).exists() {
            fs::write("/sys/class/gpio/export", pin.to_string())?;
            thread::sleep(Duration::from_millis(50));
        }
        let dir_file = format!("{}/direction", pin_dir);
        fs::write(dir_file, "out")?;
        Ok(())
    }

    fn write_pin(&self, pin_opt: Option<u32>, high: bool) {
        if !self.active.load(Ordering::Relaxed) {
            return;
        }
        #[cfg(target_os = "linux")]
        if let Some(pin) = pin_opt {
            let val_file = format!("/sys/class/gpio/gpio{}/value", pin);
            let val_str = if high { "1" } else { "0" };
            let _ = fs::write(val_file, val_str);
        }
        #[cfg(not(target_os = "linux"))]
        {
            let _ = (pin_opt, high);
        }
    }

    pub fn set_green(&self, high: bool) {
        self.write_pin(self.green_pin, high);
    }

    pub fn set_red(&self, high: bool) {
        self.write_pin(self.red_pin, high);
    }

    pub fn set_buzzer(&self, high: bool) {
        self.write_pin(self.buzzer_pin, high);
    }

    /// Trigger asynchronous pattern: 2 short beeps + Green LED (Presensi Sukses)
    pub fn trigger_success(&self) {
        let gpio = self.clone();
        thread::spawn(move || {
            info!("GPIO: Trigger SUCCESS feedback (2 bip pendek + LED hijau)");
            gpio.set_green(true);
            gpio.set_buzzer(true);
            thread::sleep(Duration::from_millis(80));
            gpio.set_buzzer(false);
            thread::sleep(Duration::from_millis(80));
            gpio.set_buzzer(true);
            thread::sleep(Duration::from_millis(80));
            gpio.set_buzzer(false);
            thread::sleep(Duration::from_millis(600));
            gpio.set_green(false);
        });
    }

    /// Trigger asynchronous pattern: 1 long beep + Red LED (Presensi Gagal / Tidak Dikenal / Spoof)
    pub fn trigger_rejected(&self) {
        let gpio = self.clone();
        thread::spawn(move || {
            warn!("GPIO: Trigger REJECTED feedback (1 bip panjang + LED merah)");
            gpio.set_red(true);
            gpio.set_buzzer(true);
            thread::sleep(Duration::from_millis(400));
            gpio.set_buzzer(false);
            thread::sleep(Duration::from_millis(600));
            gpio.set_red(false);
        });
    }

    /// Matikan semua pin saat shutdown
    pub fn reset_all(&self) {
        self.active.store(false, Ordering::Relaxed);
        self.set_green(false);
        self.set_red(false);
        self.set_buzzer(false);
    }
}
