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

/// Pengendali GPIO sysfs Linux langsung untuk LED dan Buzzer.
/// Langsung berfungsi pada Allwinner H6 (Orange Pi Lite 2) dan semua SBC Linux.
#[derive(Clone)]
pub struct LinuxGpio {
    green_pin: Option<u32>,
    red_pin: Option<u32>,
    buzzer_pin: Option<u32>,
    active_high: bool,
    active: Arc<AtomicBool>,
}

impl LinuxGpio {
    pub fn new(
        green_pin: Option<u32>,
        red_pin: Option<u32>,
        buzzer_pin: Option<u32>,
        active_high: bool,
    ) -> Self {
        let instance = Self {
            green_pin,
            red_pin,
            buzzer_pin,
            active_high,
            active: Arc::new(AtomicBool::new(true)),
        };

        // Export dan set arah untuk pin yang tersedia di Linux
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

        // Pastikan kondisi awal pin adalah OFF (mencegah pin aktif sisa crash sebelumnya)
        let val_file = format!("{}/value", pin_dir);
        let off_str = if self.active_high { "0" } else { "1" };
        let _ = fs::write(val_file, off_str);

        Ok(())
    }

    fn write_pin(&self, pin_opt: Option<u32>, on: bool) {
        if !self.active.load(Ordering::Relaxed) {
            return;
        }
        #[cfg(target_os = "linux")]
        if let Some(pin) = pin_opt {
            let val_file = format!("/sys/class/gpio/gpio{}/value", pin);
            let val_str = if on == self.active_high { "1" } else { "0" };
            let _ = fs::write(val_file, val_str);
        }
        #[cfg(not(target_os = "linux"))]
        {
            let _ = (pin_opt, on, self.active_high);
        }
    }

    pub fn set_green(&self, on: bool) {
        self.write_pin(self.green_pin, on);
    }

    pub fn set_red(&self, on: bool) {
        self.write_pin(self.red_pin, on);
    }

    pub fn set_buzzer(&self, on: bool) {
        self.write_pin(self.buzzer_pin, on);
    }

    /// Picu pola asinkron: 2 beep pendek + LED Hijau (Presensi Sukses)
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

    /// Picu pola asinkron: 1 beep panjang + LED Merah (Presensi Gagal / Tidak Dikenal / Spoof)
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

    /// Matikan semua pin saat shutdown (matikan pin terlebih dahulu sebelum menonaktifkan kontrol)
    pub fn reset_all(&self) {
        self.set_green(false);
        self.set_red(false);
        self.set_buzzer(false);
        self.active.store(false, Ordering::Relaxed);
    }
}

/// Uji pulsa satu pin GPIO secara independen selama durasi yang ditentukan.
#[cfg(target_os = "linux")]
pub fn pulse_test_pin(pin: u32, active_high: bool, duration: Duration) -> Result<()> {
    let pin_dir = format!("/sys/class/gpio/gpio{}", pin);
    let was_exported = Path::new(&pin_dir).exists();
    if !was_exported {
        fs::write("/sys/class/gpio/export", pin.to_string())?;
        thread::sleep(Duration::from_millis(50));
    }
    let dir_file = format!("{}/direction", pin_dir);
    fs::write(&dir_file, "out")?;

    let val_file = format!("{}/value", pin_dir);
    let on_str = if active_high { "1" } else { "0" };
    let off_str = if active_high { "0" } else { "1" };

    fs::write(&val_file, on_str)?;

    thread::spawn(move || {
        thread::sleep(duration);
        let _ = fs::write(&val_file, off_str);
        // Bersihkan sysfs export jika pin ini diekspor khusus untuk pengujian diagnostik
        if !was_exported {
            thread::sleep(Duration::from_millis(50));
            let _ = fs::write("/sys/class/gpio/unexport", pin.to_string());
        }
    });

    Ok(())
}

#[cfg(not(target_os = "linux"))]
pub fn pulse_test_pin(_pin: u32, _active_high: bool, _duration: Duration) -> Result<(), String> {
    Err("GPIO hardware testing is only supported on Linux devices".to_string())
}
