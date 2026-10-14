mod app;
mod layout;
mod theme;
mod ui;
mod widgets;

use app::LauncherApp;
use eframe::egui::{self, Vec2};

fn main() -> eframe::Result<()> {
    let native_options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_inner_size(Vec2::new(1180.0, 700.0))
            .with_min_inner_size(Vec2::new(760.0, 560.0))
            .with_title("Absensi-Cam · Perangkat"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam · Perangkat",
        native_options,
        Box::new(|cc| Ok(Box::new(LauncherApp::new(cc)))),
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use eframe::egui::Color32;
    use launcher_core::{ApiClient, DeviceStatus, MockApiClient};

    #[test]
    fn test_launcher_theme_colors() {
        assert_eq!(theme::LATAR, Color32::from_rgb(0x12, 0x14, 0x13));
        assert_eq!(theme::PERMUKAAN, Color32::from_rgb(0x18, 0x1B, 0x19));
        assert_eq!(theme::GARIS, Color32::from_rgb(0x26, 0x2A, 0x27));
        assert_eq!(theme::PEMISAH_BARIS, Color32::from_rgb(0x1F, 0x23, 0x21));
        assert_eq!(theme::TEKS, Color32::from_rgb(0xE7, 0xEA, 0xE5));
        assert_eq!(theme::TEKS_REDUP, Color32::from_rgb(0x98, 0xA1, 0x99));
        assert_eq!(theme::OUTLINE_TOMBOL, Color32::from_rgb(0x3A, 0x40, 0x3B));
        assert_eq!(theme::AMBER, Color32::from_rgb(0xE8, 0xB4, 0x49));
        assert_eq!(theme::TEKS_DI_ATAS_AMBER, Color32::from_rgb(0x1A, 0x14, 0x05));
        assert_eq!(theme::LATAR_MENUNGGU, Color32::from_rgb(0x1B, 0x19, 0x12));
        assert_eq!(theme::HIJAU_ONLINE, Color32::from_rgb(0x62, 0xC4, 0x8F));
        assert_eq!(theme::MERAH_OFFLINE, Color32::from_rgb(0xE5, 0x83, 0x6F));
    }

    #[test]
    fn test_launcher_filter_tabs() {
        let client = MockApiClient::new();
        let devices = client.list_devices().unwrap();
        assert_eq!(devices.len(), 5);

        let registered_count = devices.iter().filter(|d| d.status != DeviceStatus::PendingPairing).count();
        let online_count = devices.iter().filter(|d| d.status == DeviceStatus::Online).count();
        let offline_count = devices.iter().filter(|d| d.status == DeviceStatus::Offline).count();
        let pending_count = devices.iter().filter(|d| d.status == DeviceStatus::PendingPairing).count();

        assert_eq!(registered_count, 4);
        assert_eq!(online_count, 3);
        assert_eq!(offline_count, 1);
        assert_eq!(pending_count, 1);
    }
}
