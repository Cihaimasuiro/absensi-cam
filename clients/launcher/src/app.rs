use std::time::{Duration, Instant};
use eframe::egui;
use launcher_core::{
    ApiClient, DeviceStatus, EdgeDevice, FilterTab, MockApiClient,
};
use crate::theme;
use crate::ui::{footer, header, pending_banner, table};

pub const LATEST_ENGINE_VERSION: &str = "v0.3.0-rust";

pub struct LauncherApp {
    pub client: MockApiClient,
    pub server_url: String,
    pub server_connected: bool,
    pub search_query: String,
    pub active_tab: FilterTab,
    pub devices: Vec<EdgeDevice>,
    pub is_scanning: bool,
    pub scan_started_at: Option<Instant>,
    pub last_scanned_time: String,
    pub notification: Option<(String, Instant)>,
    pub rename_device_id: Option<String>,
    pub rename_input: String,
    pub selected_building: String,
    pub buildings: Vec<String>,
    pub active_menu_device_id: Option<String>,
    pub simulated_server_error: bool,
    pub just_approved_device: Option<(String, Instant)>,
}

impl LauncherApp {
    pub fn new(cc: &eframe::CreationContext<'_>) -> Self {
        theme::configure_theme(&cc.egui_ctx);
        let client = MockApiClient::new();
        let devices = client.list_devices().unwrap_or_default();

        Self {
            client,
            server_url: "http://192.168.1.4:8000".to_string(),
            server_connected: true,
            search_query: String::new(),
            active_tab: FilterTab::All,
            devices,
            is_scanning: false,
            scan_started_at: None,
            last_scanned_time: "10:42".to_string(),
            notification: None,
            rename_device_id: None,
            rename_input: String::new(),
            selected_building: "Gedung Utama".to_string(),
            buildings: vec![
                "Gedung Utama".to_string(),
                "Gedung Lab".to_string(),
                "Gedung Timur".to_string(),
            ],
            active_menu_device_id: None,
            simulated_server_error: false,
            just_approved_device: None,
        }
    }

    /// Filter perangkat untuk ditampilkan di tabel utama:
    /// - Tab 'Semua': hanya perangkat terdaftar (Online / Offline), pending hanya di blok persetujuan
    /// - Tab 'Menunggu': hanya perangkat pending
    /// - Tab 'Online': hanya online
    /// - Tab 'Offline': hanya offline
    pub fn filtered_table_devices(&self) -> Vec<EdgeDevice> {
        self.devices
            .iter()
            .filter(|d| match self.active_tab {
                FilterTab::All => d.status != DeviceStatus::PendingPairing,
                FilterTab::Pending => d.status == DeviceStatus::PendingPairing,
                FilterTab::Online => d.status == DeviceStatus::Online,
                FilterTab::Offline => d.status == DeviceStatus::Offline,
            })
            .filter(|d| {
                if self.search_query.trim().is_empty() {
                    true
                } else {
                    let q = self.search_query.to_lowercase();
                    d.hostname.to_lowercase().contains(&q)
                        || d.ip_address.contains(&q)
                        || d.mac_address.to_lowercase().contains(&q)
                        || d.pairing_code
                            .as_deref()
                            .unwrap_or("")
                            .to_lowercase()
                            .contains(&q)
                }
            })
            .cloned()
            .collect()
    }
}

impl eframe::App for LauncherApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        // Handle scanning state progression
        if self.is_scanning
            && let Some(started) = self.scan_started_at
        {
            if started.elapsed() >= Duration::from_millis(1500) {
                self.is_scanning = false;
                self.scan_started_at = None;
                self.last_scanned_time = "baru saja".to_string();
                self.devices = self.client.list_devices().unwrap_or_default();
                let count = self.devices.len();
                self.notification = Some((
                    format!("Pindai jaringan selesai: {count} perangkat terdeteksi"),
                    Instant::now(),
                ));
            } else {
                ui.ctx().request_repaint_after(Duration::from_millis(50));
            }
        }

        // Handle auto-repaint for highlight fading
        if let Some((_, timestamp)) = self.just_approved_device {
            if timestamp.elapsed() < Duration::from_millis(1500) {
                let remaining = Duration::from_millis(1500).saturating_sub(timestamp.elapsed());
                ui.ctx().request_repaint_after(remaining);
            } else {
                self.just_approved_device = None;
            }
        }

        let mut approved_id: Option<String> = None;
        let mut rejected_id: Option<String> = None;
        let mut forget_id: Option<String> = None;
        let mut copy_notification: Option<String> = None;
        let mut trigger_rename: Option<(String, String)> = None;
        let mut close_active_menu = false;

        // Padding halaman: 40 (kiri-kanan) x 28 (atas-bawah)
        egui::Frame::new()
            .fill(theme::LATAR)
            .inner_margin(egui::Margin {
                left: 40,
                right: 40,
                top: 28,
                bottom: 28,
            })
            .show(ui, |ui| {
                // 1. Header (judul, pemindaian, status server, scanning bar)
                header::render_header(self, ui);

                // Jarak antar bagian: 24px
                ui.add_space(24.0);

                // 2. Blok "Menunggu persetujuan"
                pending_banner::render_pending_banners(self, ui, &mut approved_id, &mut rejected_id);

                // 3 & 4. Tab navigasi, pencarian, modal ganti nama, dan tabel perangkat
                table::render_table(
                    self,
                    ui,
                    &mut trigger_rename,
                    &mut copy_notification,
                    &mut forget_id,
                    &mut close_active_menu,
                );

                if close_active_menu {
                    self.active_menu_device_id = None;
                }

                // Actions handling
                if let Some(id) = approved_id
                    && self.client.approve_device(&id).is_ok()
                    && let Some(dev) = self.devices.iter_mut().find(|d| d.id == id)
                {
                    dev.status = DeviceStatus::Online;
                    dev.pairing_code = None;
                    let name = dev.hostname.clone();
                    self.just_approved_device = Some((id, Instant::now()));
                    // Pesan netral di footer ~4 detik
                    self.notification = Some((
                        format!("{} disetujui", name),
                        Instant::now(),
                    ));
                }
                if let Some(id) = rejected_id {
                    let rejected_name = self.devices.iter().find(|d| d.id == id).map(|d| d.hostname.clone()).unwrap_or_else(|| id.clone());
                    if self.client.reject_device(&id).is_ok() {
                        self.devices.retain(|d| d.id != id);
                        self.notification = Some((
                            format!("Perangkat {} ditolak", rejected_name),
                            Instant::now(),
                        ));
                    }
                }
                if let Some(id) = forget_id {
                    let forgotten_name = self.devices.iter().find(|d| d.id == id).map(|d| d.hostname.clone()).unwrap_or_else(|| id.clone());
                    if self.client.forget_device(&id).is_ok() {
                        self.devices.retain(|d| d.id != id);
                        self.notification = Some((
                            format!("Perangkat {} dilupakan", forgotten_name),
                            Instant::now(),
                        ));
                    }
                }
                if let Some((id, hostname)) = trigger_rename {
                    self.rename_device_id = Some(id);
                    self.rename_input = hostname;
                }
                if let Some(msg) = copy_notification {
                    self.notification = Some((msg, Instant::now()));
                }

                // 5. Footer (hitungan perangkat, waktu pemindaian, notifikasi toast)
                footer::render_footer(self, ui);
            });
    }
}
