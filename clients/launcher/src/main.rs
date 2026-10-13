mod theme;

use eframe::egui::{self, Color32, FontFamily, FontId, RichText, Vec2};

const LATEST_ENGINE_VERSION: &str = "v0.3.0-rust";

#[derive(Debug, Clone, PartialEq)]
enum DeviceStatus {
    PendingPairing,
    Online,
    Offline,
}

#[derive(Debug, Clone)]
struct EdgeDevice {
    id: String,
    hostname: String,
    ip_address: String,
    mac_address: String,
    pairing_code: Option<String>,
    status: DeviceStatus,
    engine_version: String,
    temperature_c: f32,
    last_seen_sec: u64,
    has_timeout_error: bool,
}

#[derive(Debug, Clone, PartialEq)]
enum FilterTab {
    All,
    Pending,
    Online,
    Offline,
}

/// Simulation states to demonstrate error & edge scenarios before wiring real networking
#[derive(Debug, Clone, Copy, PartialEq)]
enum SimState {
    Normal,
    Scanning,
    NoDevices,
    ServerUnreachable,
    TokenExpired,
    DeviceTimeout,
}

fn format_relative_time_id(seconds: u64) -> String {
    if seconds < 60 {
        format!("{seconds} dtk lalu")
    } else if seconds < 3600 {
        format!("{} mnt lalu", seconds / 60)
    } else if seconds < 86400 {
        format!("{} jam lalu", seconds / 3600)
    } else {
        format!("{} hari lalu", seconds / 86400)
    }
}

struct LauncherApp {
    server_url: String,
    search_query: String,
    active_tab: FilterTab,
    sim_state: SimState,
    scan_progress: f32,
    devices: Vec<EdgeDevice>,
    selected_device_id: Option<String>,
    last_scanned_time: String,
    notification_msg: Option<String>,
    rename_device_id: Option<String>,
    rename_input: String,
    token_input_open: bool,
    token_input: String,
}

impl LauncherApp {
    fn new(cc: &eframe::CreationContext<'_>) -> Self {
        theme::configure_theme(&cc.egui_ctx);

        Self {
            server_url: "http://192.168.1.100:8000".to_string(),
            search_query: String::new(),
            active_tab: FilterTab::All,
            sim_state: SimState::Normal,
            scan_progress: 0.0,
            last_scanned_time: "10:42 WIB".to_string(),
            notification_msg: None,
            rename_device_id: None,
            rename_input: String::new(),
            token_input_open: false,
            token_input: String::new(),
            devices: Self::sample_devices(),
            selected_device_id: None,
        }
    }

    fn sample_devices() -> Vec<EdgeDevice> {
        vec![
            EdgeDevice {
                id: "dev-04".to_string(),
                hostname: "orangepi-new-device".to_string(),
                ip_address: "192.168.1.88".to_string(),
                mac_address: "02:42:C0:A8:01:58".to_string(),
                pairing_code: Some("PAIR-7842".to_string()),
                status: DeviceStatus::PendingPairing,
                engine_version: "v0.3.0-rust".to_string(),
                temperature_c: 50.2,
                last_seen_sec: 12,
                has_timeout_error: false,
            },
            EdgeDevice {
                id: "dev-01".to_string(),
                hostname: "orangepi-gate-in".to_string(),
                ip_address: "192.168.1.50".to_string(),
                mac_address: "02:42:C0:A8:01:32".to_string(),
                pairing_code: None,
                status: DeviceStatus::Online,
                engine_version: "v0.3.0-rust".to_string(),
                temperature_c: 54.8,
                last_seen_sec: 2,
                has_timeout_error: false,
            },
            EdgeDevice {
                id: "dev-02".to_string(),
                hostname: "orangepi-gate-out".to_string(),
                ip_address: "192.168.1.51".to_string(),
                mac_address: "02:42:C0:A8:01:33".to_string(),
                pairing_code: None,
                status: DeviceStatus::Online,
                engine_version: "v0.3.0-rust".to_string(),
                temperature_c: 56.1,
                last_seen_sec: 5,
                has_timeout_error: false,
            },
            EdgeDevice {
                id: "dev-03".to_string(),
                hostname: "orangepi-canteen".to_string(),
                ip_address: "192.168.1.55".to_string(),
                mac_address: "02:42:C0:A8:01:37".to_string(),
                pairing_code: None,
                status: DeviceStatus::Offline,
                engine_version: "v0.2.8".to_string(),
                temperature_c: 0.0,
                last_seen_sec: 1420,
                has_timeout_error: false,
            },
        ]
    }

    fn filtered_devices(&self) -> Vec<&EdgeDevice> {
        if self.sim_state == SimState::NoDevices {
            return Vec::new();
        }

        let mut list: Vec<&EdgeDevice> = self
            .devices
            .iter()
            .filter(|d| match self.active_tab {
                FilterTab::All => true,
                FilterTab::Online => d.status == DeviceStatus::Online,
                FilterTab::Offline => d.status == DeviceStatus::Offline,
                FilterTab::Pending => d.status == DeviceStatus::PendingPairing,
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
            .collect();

        // Hierarchy rule: Pending devices ALWAYS float to the top
        list.sort_by_key(|d| match d.status {
            DeviceStatus::PendingPairing => 0,
            DeviceStatus::Online => 1,
            DeviceStatus::Offline => 2,
        });

        list
    }
}

impl eframe::App for LauncherApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        // Enforce 20px perimeter padding around entire application window
        egui::Frame::new()
            .fill(theme::BG)
            .inner_margin(egui::Margin::symmetric(20, 16))
            .show(ui, |ui| {
                // 1. Header Top Bar
                ui.horizontal(|ui| {
                    let display_font = FontId::new(16.0, FontFamily::Name("display".into()));
                    ui.label(
                        RichText::new("ABSENSI-CAM")
                            .font(display_font)
                            .color(theme::INK),
                    );

                    ui.label(
                        RichText::new("// PELUNCUR ARMADA")
                            .font(FontId::monospace(11.0))
                            .color(theme::INK_MUTED),
                    );

                    // Honest simulation tag
                    ui.label(
                        RichText::new(" DATA SIMULASI ")
                            .font(FontId::monospace(10.0))
                            .strong()
                            .color(theme::PENDING_FG)
                            .background_color(theme::PENDING_BG),
                    );

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        // Settings button
                        let _ = ui.add(
                            egui::Button::new(
                                RichText::new("⚙ Pengaturan").font(FontId::proportional(12.0)),
                            )
                            .min_size(Vec2::new(96.0, 30.0)),
                        );

                        // Scan LAN button with active feedback
                        let is_scanning = self.sim_state == SimState::Scanning;
                        let scan_text = if is_scanning {
                            "⏳ Memindai..."
                        } else {
                            "⟳ Pindai LAN"
                        };
                        let scan_btn = egui::Button::new(
                            RichText::new(scan_text).font(FontId::proportional(12.0)),
                        )
                        .min_size(Vec2::new(96.0, 30.0));

                        if ui.add(scan_btn).clicked() {
                            if self.sim_state == SimState::Scanning {
                                self.sim_state = SimState::Normal;
                            } else {
                                self.sim_state = SimState::Scanning;
                                self.scan_progress = 0.35;
                                self.last_scanned_time = "Baru saja".to_string();
                            }
                        }

                        // Consistent label: "Server:" (matching footer)
                        let server_status_text = match self.sim_state {
                            SimState::ServerUnreachable => "Server: Terputus (ECONNREFUSED)".to_string(),
                            _ => format!("Server: {}", self.server_url),
                        };
                        let server_color = match self.sim_state {
                            SimState::ServerUnreachable => theme::OFFLINE_FG,
                            _ => theme::INK_MUTED,
                        };
                        ui.label(
                            RichText::new(&server_status_text)
                                .font(FontId::monospace(11.0))
                                .color(server_color),
                        );
                    });
                });

                ui.add_space(8.0);

                // State Simulation Switcher (Pre-network verification bar)
                ui.horizontal(|ui| {
                    ui.label(
                        RichText::new("Simulasi Keadaan:")
                            .font(FontId::monospace(10.0))
                            .color(theme::INK_DIM),
                    );

                    let sim_modes = [
                        (SimState::Normal, "Normal"),
                        (SimState::Scanning, "⏳ Memindai"),
                        (SimState::NoDevices, "∅ Kosong"),
                        (SimState::ServerUnreachable, "⚠ Server Putus"),
                        (SimState::TokenExpired, "🔒 Token Habis"),
                        (SimState::DeviceTimeout, "⏱ Device Timeout"),
                    ];

                    for (mode, label) in sim_modes {
                        let is_selected = self.sim_state == mode;
                        let text = RichText::new(label)
                            .font(FontId::monospace(10.0))
                            .color(if is_selected {
                                theme::TAB_ACTIVE_FG
                            } else {
                                theme::INK_MUTED
                            });

                        let btn = egui::Button::new(text)
                            .fill(if is_selected {
                                theme::TAB_ACTIVE_BG
                            } else {
                                Color32::TRANSPARENT
                            })
                            .stroke(theme::hairline())
                            .corner_radius(theme::RADIUS)
                            .min_size(Vec2::new(0.0, 22.0));

                        if ui.add(btn).clicked() {
                            self.sim_state = mode;
                            if mode == SimState::DeviceTimeout {
                                if let Some(dev) = self.devices.first_mut() {
                                    dev.has_timeout_error = true;
                                }
                            } else {
                                for dev in &mut self.devices {
                                    dev.has_timeout_error = false;
                                }
                            }
                        }
                    }
                });

                ui.add_space(8.0);
                ui.separator();
                ui.add_space(8.0);

                // 2. Alert & Edge State Banners (Top Priority Indicators)
                match self.sim_state {
                    SimState::Scanning => {
                        egui::Frame::group(ui.style())
                            .fill(Color32::from_rgb(20, 28, 42))
                            .stroke(egui::Stroke::new(1.0, theme::ACCENT))
                            .inner_margin(egui::Margin::symmetric(14, 10))
                            .corner_radius(theme::RADIUS)
                            .show(ui, |ui| {
                                ui.horizontal(|ui| {
                                    ui.label(
                                        RichText::new("⏳ MEMINDAI SUBNET:")
                                            .font(FontId::monospace(11.0))
                                            .strong()
                                            .color(theme::ACCENT),
                                    );
                                    ui.label(
                                        RichText::new("Mengirim probe ARP & UDP port 8000 ke 192.168.1.0/24...")
                                            .font(FontId::proportional(12.0))
                                            .color(theme::INK),
                                    );

                                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                        if ui
                                            .add(
                                                egui::Button::new(
                                                    RichText::new("Hentikan").font(FontId::proportional(11.0)),
                                                )
                                                .min_size(Vec2::new(70.0, 24.0)),
                                            )
                                            .clicked()
                                        {
                                            self.sim_state = SimState::Normal;
                                        }

                                        ui.add(
                                            egui::ProgressBar::new(0.42)
                                                .desired_width(120.0)
                                                .text("108/254 IP"),
                                        );
                                    });
                                });
                            });
                        ui.add_space(8.0);
                    }
                    SimState::ServerUnreachable => {
                        egui::Frame::group(ui.style())
                            .fill(theme::BANNER_WARN_BG)
                            .stroke(egui::Stroke::new(1.0, theme::PENDING_FG))
                            .inner_margin(egui::Margin::symmetric(14, 10))
                            .corner_radius(theme::RADIUS)
                            .show(ui, |ui| {
                                ui.horizontal(|ui| {
                                    ui.label(
                                        RichText::new("⚠ SERVER TERPUTUS:")
                                            .font(FontId::monospace(11.0))
                                            .strong()
                                            .color(theme::PENDING_FG),
                                    );
                                    ui.label(
                                        RichText::new(format!(
                                            "Gagal menghubungi {} (ECONNREFUSED). Mode offline aktif.",
                                            self.server_url
                                        ))
                                        .font(FontId::proportional(12.0))
                                        .color(theme::INK),
                                    );

                                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                        if ui
                                            .add(
                                                egui::Button::new(
                                                    RichText::new("Uji Ulang").font(FontId::proportional(11.0)),
                                                )
                                                .min_size(Vec2::new(80.0, 24.0)),
                                            )
                                            .clicked()
                                        {
                                            self.notification_msg = Some("Mencoba hubungkan ke server...".to_string());
                                        }

                                        if ui
                                            .add(
                                                egui::Button::new(
                                                    RichText::new("Ubah URL").font(FontId::proportional(11.0)),
                                                )
                                                .min_size(Vec2::new(80.0, 24.0)),
                                            )
                                            .clicked()
                                        {}
                                    });
                                });
                            });
                        ui.add_space(8.0);
                    }
                    SimState::TokenExpired => {
                        egui::Frame::group(ui.style())
                            .fill(theme::BANNER_ERR_BG)
                            .stroke(egui::Stroke::new(1.0, theme::OFFLINE_FG))
                            .inner_margin(egui::Margin::symmetric(14, 10))
                            .corner_radius(theme::RADIUS)
                            .show(ui, |ui| {
                                ui.horizontal(|ui| {
                                    ui.label(
                                        RichText::new("🔒 SESI TOKEN HABIS:")
                                            .font(FontId::monospace(11.0))
                                            .strong()
                                            .color(theme::OFFLINE_FG),
                                    );
                                    ui.label(
                                        RichText::new("Token otorisasi API kedaluwarsa. Persetujuan dan perubahan armada dikunci.")
                                            .font(FontId::proportional(12.0))
                                            .color(theme::INK),
                                    );

                                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                        if ui
                                            .add(
                                                egui::Button::new(
                                                    RichText::new("Perbarui Token").font(FontId::proportional(11.0)),
                                                )
                                                .min_size(Vec2::new(100.0, 24.0)),
                                            )
                                            .clicked()
                                        {
                                            self.token_input_open = !self.token_input_open;
                                        }
                                    });
                                });

                                if self.token_input_open {
                                    ui.add_space(6.0);
                                    ui.horizontal(|ui| {
                                        ui.label(
                                            RichText::new("Token Baru:")
                                                .font(FontId::monospace(11.0))
                                                .color(theme::INK_MUTED),
                                        );
                                        ui.add(
                                            egui::TextEdit::singleline(&mut self.token_input)
                                                .hint_text("Masukkan Bearer Sanctum Token...")
                                                .desired_width(280.0),
                                        );
                                        if ui.button("Simpan & Aktifkan").clicked() {
                                            self.token_input_open = false;
                                            self.sim_state = SimState::Normal;
                                            self.notification_msg = Some("Token berhasil diperbarui".to_string());
                                        }
                                    });
                                }
                            });
                        ui.add_space(8.0);
                    }
                    _ => {}
                }

                // 3. Filter Tabs & Search Bar (Uniform 30px height, High-Contrast Active Tab)
                ui.horizontal(|ui| {
                    let all_cnt = self.devices.len();
                    let pending_cnt = self
                        .devices
                        .iter()
                        .filter(|d| d.status == DeviceStatus::PendingPairing)
                        .count();
                    let online_cnt = self
                        .devices
                        .iter()
                        .filter(|d| d.status == DeviceStatus::Online)
                        .count();
                    let offline_cnt = self
                        .devices
                        .iter()
                        .filter(|d| d.status == DeviceStatus::Offline)
                        .count();

                    let tabs = [
                        (FilterTab::All, format!("Semua ({all_cnt})")),
                        (FilterTab::Pending, format!("Menunggu ({pending_cnt})")),
                        (FilterTab::Online, format!("Online ({online_cnt})")),
                        (FilterTab::Offline, format!("Offline ({offline_cnt})")),
                    ];

                    for (tab, label) in tabs {
                        let is_active = self.active_tab == tab;
                        let text = RichText::new(label)
                            .font(FontId::monospace(11.0))
                            .strong()
                            .color(if is_active {
                                theme::TAB_ACTIVE_FG
                            } else {
                                theme::INK_MUTED
                            });

                        let btn = egui::Button::new(text)
                            .fill(if is_active {
                                theme::TAB_ACTIVE_BG
                            } else {
                                Color32::TRANSPARENT
                            })
                            .stroke(if is_active {
                                egui::Stroke::new(1.0, theme::TAB_ACTIVE_BG)
                            } else {
                                theme::hairline()
                            })
                            .corner_radius(theme::RADIUS)
                            .min_size(Vec2::new(94.0, 30.0));

                        if ui.add(btn).clicked() {
                            self.active_tab = tab;
                        }
                    }

                    // Search input precisely matching 30px height
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add(
                            egui::TextEdit::singleline(&mut self.search_query)
                                .hint_text("Cari hostname, IP, MAC, atau kode...")
                                .font(FontId::proportional(12.0))
                                .desired_width(280.0)
                                .min_size(Vec2::new(0.0, 30.0))
                                .margin(egui::Margin::symmetric(10, 7)),
                        );
                    });
                });

                ui.add_space(12.0);

                // Rename Modal Dialog (if triggered from "⋯" menu)
                if let Some(ref dev_id) = self.rename_device_id.clone() {
                    egui::Frame::group(ui.style())
                        .fill(theme::PANEL)
                        .stroke(egui::Stroke::new(1.0, theme::ACCENT))
                        .inner_margin(egui::Margin::symmetric(16, 12))
                        .corner_radius(theme::RADIUS)
                        .show(ui, |ui| {
                            ui.horizontal(|ui| {
                                ui.label(
                                    RichText::new("Ubah Nama Perangkat:")
                                        .font(FontId::proportional(12.0))
                                        .strong()
                                        .color(theme::INK),
                                );
                                ui.add(
                                    egui::TextEdit::singleline(&mut self.rename_input)
                                        .desired_width(220.0),
                                );
                                if ui.button("Simpan").clicked() {
                                    if let Some(dev) = self.devices.iter_mut().find(|d| &d.id == dev_id) {
                                        dev.hostname = self.rename_input.clone();
                                    }
                                    self.rename_device_id = None;
                                    self.rename_input.clear();
                                }
                                if ui.button("Batal").clicked() {
                                    self.rename_device_id = None;
                                    self.rename_input.clear();
                                }
                            });
                        });
                    ui.add_space(8.0);
                }

                // 4. Device Cards Scroll Area or Rich Empty State
                let filtered = self.filtered_devices();
                let mut new_selection: Option<String> = None;
                let mut approved_id: Option<String> = None;
                let mut rejected_id: Option<String> = None;
                let mut forget_id: Option<String> = None;
                let mut copy_notification: Option<String> = None;
                let mut retry_device_id: Option<String> = None;
                let mut trigger_rename: Option<(String, String)> = None;

                if filtered.is_empty() {
                    // Empty State: Technician-friendly diagnostic screen
                    ui.vertical_centered(|ui| {
                        ui.add_space(40.0);
                        ui.label(
                            RichText::new("[ ∅ ]")
                                .font(FontId::monospace(26.0))
                                .color(theme::INK_FAINT),
                        );
                        ui.add_space(8.0);
                        ui.label(
                            RichText::new("Tidak ada perangkat yang terdeteksi.")
                                .font(FontId::proportional(15.0))
                                .strong()
                                .color(theme::INK),
                        );
                        ui.add_space(6.0);
                        ui.label(
                            RichText::new(
                                "Tidak ditemukan Orange Pi yang cocok dengan kriteria pencarian / filter ini.",
                            )
                            .font(FontId::proportional(12.0))
                            .color(theme::INK_MUTED),
                        );
                        ui.add_space(14.0);

                        egui::Frame::group(ui.style())
                            .fill(theme::PANEL)
                            .stroke(theme::hairline())
                            .inner_margin(egui::Margin::symmetric(20, 14))
                            .corner_radius(theme::RADIUS)
                            .show(ui, |ui| {
                                ui.vertical(|ui| {
                                    ui.label(
                                        RichText::new("PANDUAN PEMERIKSAAN LAPANGAN:")
                                            .font(FontId::monospace(10.0))
                                            .strong()
                                            .color(theme::INK_DIM),
                                    );
                                    ui.add_space(4.0);
                                    ui.label(
                                        RichText::new("1. Pastikan kabel Ethernet / WiFi Orange Pi terpasang dan LED link berkedip.")
                                            .font(FontId::proportional(11.0))
                                            .color(theme::INK_MUTED),
                                    );
                                    ui.label(
                                        RichText::new("2. Pastikan layanan edge-engine-rs aktif: systemctl status smart-absensi-engine")
                                            .font(FontId::monospace(11.0))
                                            .color(theme::INK_MUTED),
                                    );
                                    ui.label(
                                        RichText::new("3. Pastikan subnet workstation dan perangkat berada dalam segmen yang sama (192.168.1.0/24).")
                                            .font(FontId::proportional(11.0))
                                            .color(theme::INK_MUTED),
                                    );
                                });
                            });

                        ui.add_space(14.0);
                        if ui
                            .add(
                                egui::Button::new(
                                    RichText::new("⟳ Pindai Ulang Jaringan Lokal")
                                        .font(FontId::proportional(12.0)),
                                )
                                .min_size(Vec2::new(180.0, 32.0)),
                            )
                            .clicked()
                        {
                            self.sim_state = SimState::Scanning;
                        }
                    });
                } else {
                    let current_selected = self.selected_device_id.clone();
                    egui::ScrollArea::vertical()
                        .max_height(ui.available_height() - 44.0)
                        .show(ui, |ui| {
                            for dev in filtered {
                                let is_selected = current_selected.as_deref() == Some(&dev.id);
                                let is_pending = dev.status == DeviceStatus::PendingPairing;
                                let is_offline = dev.status == DeviceStatus::Offline;
                                let is_outdated = dev.engine_version != LATEST_ENGINE_VERSION;

                                // Card Surface Styling: Pending has warm amber border and warm background
                                let card_fill = if is_selected {
                                    Color32::from_rgb(32, 36, 48)
                                } else if is_pending {
                                    theme::CARD_PENDING_BG
                                } else if is_offline {
                                    Color32::from_rgb(20, 21, 26)
                                } else {
                                    theme::CARD_BG
                                };

                                let card_stroke = if is_selected {
                                    egui::Stroke::new(1.0, theme::ACCENT)
                                } else if is_pending {
                                    egui::Stroke::new(1.2, theme::PENDING_FG)
                                } else {
                                    theme::hairline()
                                };

                                egui::Frame::group(ui.style())
                                    .fill(card_fill)
                                    .stroke(card_stroke)
                                    .inner_margin(egui::Margin::symmetric(16, 12))
                                    .corner_radius(theme::RADIUS)
                                    .show(ui, |ui| {
                                        ui.horizontal(|ui| {
                                            // Left Block: Identity & Admin Verification Focus
                                            ui.vertical(|ui| {
                                                ui.horizontal(|ui| {
                                                    let host_color = if is_offline {
                                                        theme::INK_MUTED
                                                    } else {
                                                        theme::INK
                                                    };

                                                    let host_btn = ui.selectable_label(
                                                        is_selected,
                                                        RichText::new(&dev.hostname)
                                                            .font(FontId::proportional(14.0))
                                                            .strong()
                                                            .color(host_color),
                                                    );
                                                    if host_btn.clicked() {
                                                        new_selection = Some(dev.id.clone());
                                                    }

                                                    // Status Chip (Amber STRICTLY reserved for MENUNGGU)
                                                    let (badge_text, badge_color, badge_bg) = match dev.status {
                                                        DeviceStatus::Online => (
                                                            "ONLINE",
                                                            theme::ONLINE_FG,
                                                            theme::ONLINE_BG,
                                                        ),
                                                        DeviceStatus::Offline => (
                                                            "OFFLINE",
                                                            theme::OFFLINE_FG,
                                                            theme::OFFLINE_BG,
                                                        ),
                                                        DeviceStatus::PendingPairing => (
                                                            "MENUNGGU PERSETUJUAN",
                                                            theme::PENDING_FG,
                                                            theme::PENDING_BG,
                                                        ),
                                                    };

                                                    ui.label(
                                                        RichText::new(format!(" {badge_text} "))
                                                            .font(FontId::monospace(10.0))
                                                            .strong()
                                                            .color(badge_color)
                                                            .background_color(badge_bg),
                                                    );

                                                    // Prominent Pairing Code sticker for pending cards
                                                    if let Some(ref code) = dev.pairing_code {
                                                        ui.label(
                                                            RichText::new(format!(" KODE: {code} "))
                                                                .font(FontId::monospace(10.0))
                                                                .strong()
                                                                .color(theme::PENDING_FG)
                                                                .background_color(Color32::from_rgb(52, 40, 18)),
                                                        );
                                                    }

                                                    // Outdated chip uses neutral slate outline (NOT amber!)
                                                    if is_outdated {
                                                        ui.label(
                                                            RichText::new(" VERSI USANG ")
                                                                .font(FontId::monospace(10.0))
                                                                .strong()
                                                                .color(theme::OUTDATED_FG)
                                                                .background_color(theme::OUTDATED_BG),
                                                        );
                                                    }
                                                });

                                                ui.add_space(4.0);

                                                // Monospace Details: Prominent MAC verification for Pending
                                                ui.horizontal(|ui| {
                                                    let detail_ink = if is_offline {
                                                        theme::INK_DIM
                                                    } else {
                                                        theme::INK_MUTED
                                                    };

                                                    ui.label(
                                                        RichText::new(format!("IP: {}", dev.ip_address))
                                                            .font(FontId::monospace(11.0))
                                                            .color(detail_ink),
                                                    );

                                                    ui.label(RichText::new("•").size(10.0).color(theme::INK_FAINT));

                                                    // Admin Verification: High-contrast, larger MAC for Pending cards
                                                    if is_pending {
                                                        ui.label(
                                                            RichText::new(format!("VERIFIKASI MAC: {}", dev.mac_address))
                                                                .font(FontId::monospace(12.0))
                                                                .strong()
                                                                .color(theme::INK),
                                                        );
                                                    } else {
                                                        ui.label(
                                                            RichText::new(format!("MAC: {}", dev.mac_address))
                                                                .font(FontId::monospace(11.0))
                                                                .color(detail_ink),
                                                        );
                                                    }

                                                    ui.label(RichText::new("•").size(10.0).color(theme::INK_FAINT));

                                                    ui.label(
                                                        RichText::new(format!("Mesin: {}", dev.engine_version))
                                                            .font(FontId::monospace(11.0))
                                                            .color(detail_ink),
                                                    );
                                                });
                                            });

                                            // Right Block: Fixed-width aligned Columns (Time | CPU | Actions)
                                            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                                // Column 3: Actions (Fixed 240px slot for pixel alignment)
                                                ui.allocate_ui_with_layout(
                                                    Vec2::new(240.0, 30.0),
                                                    egui::Layout::right_to_left(egui::Align::Center),
                                                    |ui| {
                                                        // Context Menu "⋯" (Holds Rename, Revoke/Forget, Copy IP/MAC)
                                                        ui.menu_button(
                                                            RichText::new("⋯").font(FontId::proportional(14.0)),
                                                            |ui| {
                                                                if ui.button("📋 Salin Alamat IP").clicked() {
                                                                    ui.ctx().copy_text(dev.ip_address.clone());
                                                                    copy_notification = Some(format!(
                                                                        "IP {} disalin ke clipboard",
                                                                        dev.ip_address
                                                                    ));
                                                                    ui.close();
                                                                }
                                                                if ui.button("📋 Salin Alamat MAC").clicked() {
                                                                    ui.ctx().copy_text(dev.mac_address.clone());
                                                                    copy_notification = Some(format!(
                                                                        "MAC {} disalin ke clipboard",
                                                                        dev.mac_address
                                                                    ));
                                                                    ui.close();
                                                                }
                                                                ui.separator();
                                                                if ui.button("✏ Ubah Nama...").clicked() {
                                                                    trigger_rename = Some((dev.id.clone(), dev.hostname.clone()));
                                                                    ui.close();
                                                                }
                                                                if ui
                                                                    .button(
                                                                        RichText::new("🗑 Lupakan Perangkat")
                                                                            .color(theme::OFFLINE_FG),
                                                                    )
                                                                    .clicked()
                                                                {
                                                                    forget_id = Some(dev.id.clone());
                                                                    ui.close();
                                                                }
                                                            },
                                                        );

                                                        match dev.status {
                                                            DeviceStatus::PendingPairing => {
                                                                // Single Primary Button Rule: Approve is SOLID
                                                                let approve_btn = egui::Button::new(
                                                                    RichText::new("✔ Setujui")
                                                                        .font(FontId::proportional(12.0))
                                                                        .strong()
                                                                        .color(Color32::WHITE),
                                                                )
                                                                .fill(theme::SOLID_APPROVE_BG)
                                                                .min_size(Vec2::new(96.0, 30.0));
                                                                if ui.add(approve_btn).clicked() {
                                                                    approved_id = Some(dev.id.clone());
                                                                }

                                                                // Security control: Reject button ("Tolak" - Outline)
                                                                let reject_btn = egui::Button::new(
                                                                    RichText::new("Tolak")
                                                                        .font(FontId::proportional(12.0))
                                                                        .color(theme::REJECT_FG),
                                                                )
                                                                .fill(Color32::TRANSPARENT)
                                                                .stroke(egui::Stroke::new(1.0, theme::REJECT_FG))
                                                                .min_size(Vec2::new(64.0, 30.0));
                                                                if ui.add(reject_btn).clicked() {
                                                                    rejected_id = Some(dev.id.clone());
                                                                }
                                                            }
                                                            DeviceStatus::Online => {
                                                                // Secondary action: Buka Stream (Outline with Accent stroke)
                                                                let stream_btn = egui::Button::new(
                                                                    RichText::new("Buka Stream")
                                                                        .font(FontId::proportional(12.0))
                                                                        .color(theme::ACCENT),
                                                                )
                                                                .fill(Color32::TRANSPARENT)
                                                                .stroke(egui::Stroke::new(1.0, theme::ACCENT))
                                                                .min_size(Vec2::new(96.0, 30.0));
                                                                if ui.add(stream_btn).clicked() {
                                                                    copy_notification = Some(format!(
                                                                        "Membuka stream RTSP untuk {}",
                                                                        dev.hostname
                                                                    ));
                                                                }

                                                                // Secondary action: Inspeksi (Hairline outline)
                                                                let inspect_btn = egui::Button::new(
                                                                    RichText::new("Inspeksi")
                                                                        .font(FontId::proportional(12.0))
                                                                        .color(theme::INK),
                                                                )
                                                                .fill(Color32::TRANSPARENT)
                                                                .stroke(theme::hairline())
                                                                .min_size(Vec2::new(76.0, 30.0));
                                                                if ui.add(inspect_btn).clicked() {}
                                                            }
                                                            DeviceStatus::Offline => {
                                                                let ping_btn = egui::Button::new(
                                                                    RichText::new("Ping")
                                                                        .font(FontId::proportional(12.0))
                                                                        .color(theme::INK_MUTED),
                                                                )
                                                                .fill(Color32::TRANSPARENT)
                                                                .stroke(theme::hairline())
                                                                .min_size(Vec2::new(76.0, 30.0));
                                                                if ui.add(ping_btn).clicked() {
                                                                    copy_notification = Some(format!(
                                                                        "Mengirim ICMP ping ke {}",
                                                                        dev.ip_address
                                                                    ));
                                                                }
                                                            }
                                                        }
                                                    },
                                                );

                                                ui.add(egui::Separator::default().vertical());

                                                // Column 2: CPU Telemetry (Fixed 90px slot for vertical alignment)
                                                ui.allocate_ui_with_layout(
                                                    Vec2::new(90.0, 30.0),
                                                    egui::Layout::left_to_right(egui::Align::Center),
                                                    |ui| {
                                                        if dev.status == DeviceStatus::Online {
                                                            let temp_col = theme::temp_color(dev.temperature_c);
                                                            ui.label(
                                                                RichText::new(format!("CPU: {:.1}°C", dev.temperature_c))
                                                                    .font(FontId::monospace(11.0))
                                                                    .color(temp_col),
                                                                );
                                                        } else if dev.status == DeviceStatus::PendingPairing {
                                                            ui.label(
                                                                RichText::new("CPU: --")
                                                                    .font(FontId::monospace(11.0))
                                                                    .color(theme::INK_FAINT),
                                                            );
                                                        } else {
                                                            ui.label(
                                                                RichText::new("CPU: Off")
                                                                    .font(FontId::monospace(11.0))
                                                                    .color(theme::INK_FAINT),
                                                            );
                                                        }
                                                    },
                                                );

                                                ui.add(egui::Separator::default().vertical());

                                                // Column 1: Time Freshness (Fixed 80px slot for vertical alignment)
                                                ui.allocate_ui_with_layout(
                                                    Vec2::new(80.0, 30.0),
                                                    egui::Layout::right_to_left(egui::Align::Center),
                                                    |ui| {
                                                        ui.label(
                                                            RichText::new(format_relative_time_id(dev.last_seen_sec))
                                                                .font(FontId::monospace(11.0))
                                                                .color(theme::INK_DIM),
                                                        );
                                                    },
                                                );
                                            });
                                        });

                                        // State 5: Device stops responding mid-action (Inline timeout alert)
                                        if dev.has_timeout_error {
                                            ui.add_space(6.0);
                                            egui::Frame::group(ui.style())
                                                .fill(Color32::from_rgb(38, 16, 20))
                                                .stroke(egui::Stroke::new(1.0, theme::OFFLINE_FG))
                                                .inner_margin(egui::Margin::symmetric(10, 6))
                                                .corner_radius(theme::RADIUS)
                                                .show(ui, |ui| {
                                                    ui.horizontal(|ui| {
                                                        ui.label(
                                                            RichText::new("⚠ TIMEOUT (5000ms):")
                                                                .font(FontId::monospace(10.0))
                                                                .strong()
                                                                .color(theme::OFFLINE_FG),
                                                        );
                                                        ui.label(
                                                            RichText::new("Perangkat tidak merespons perintah. Periksa suplai daya & kabel LAN.")
                                                                .font(FontId::proportional(11.0))
                                                                .color(theme::INK),
                                                        );
                                                        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                                            if ui.button("✕ Tutup").clicked() {
                                                                retry_device_id = Some(dev.id.clone());
                                                            }
                                                            if ui.button("⟳ Coba Lagi").clicked() {
                                                                retry_device_id = Some(dev.id.clone());
                                                                copy_notification = Some(format!("Mencoba ulang koneksi ke {}", dev.hostname));
                                                            }
                                                        });
                                                    });
                                                });
                                        }
                                    });
                                ui.add_space(8.0);
                            }
                        });

                    if let Some(id) = new_selection {
                        self.selected_device_id = Some(id);
                    }
                    if let Some(id) = approved_id {
                        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
                            dev.status = DeviceStatus::Online;
                            dev.pairing_code = None;
                            self.notification_msg = Some(format!("Perangkat {} disetujui", dev.hostname));
                        }
                    }
                    if let Some(id) = rejected_id {
                        self.devices.retain(|d| d.id != id);
                        self.notification_msg = Some("Perangkat ditolak dan dihapus dari antrean".to_string());
                    }
                    if let Some(id) = forget_id {
                        self.devices.retain(|d| d.id != id);
                        self.notification_msg = Some("Perangkat dilupakan".to_string());
                    }
                    if let Some((id, hostname)) = trigger_rename {
                        self.rename_device_id = Some(id);
                        self.rename_input = hostname;
                    }
                    if let Some(id) = retry_device_id {
                        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
                            dev.has_timeout_error = false;
                        }
                    }
                    if let Some(msg) = copy_notification {
                        self.notification_msg = Some(msg);
                    }
                }

                // 5. Footer Bar with practical technician metrics
                ui.with_layout(egui::Layout::bottom_up(egui::Align::LEFT), |ui| {
                    ui.add_space(2.0);
                    ui.separator();
                    ui.add_space(4.0);
                    ui.horizontal(|ui| {
                        let total_devices = self.devices.len();
                        ui.label(
                            RichText::new(format!(
                                "Dipindai {} • {} perangkat terdaftar • Port: 8000",
                                self.last_scanned_time, total_devices
                            ))
                            .font(FontId::monospace(11.0))
                            .color(theme::INK_MUTED),
                        );

                        if let Some(ref note) = self.notification_msg {
                            ui.label(
                                RichText::new(format!("• {note}"))
                                    .font(FontId::monospace(11.0))
                                    .color(theme::ONLINE_FG),
                            );
                        }

                        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                            let (status_text, status_color) = match self.sim_state {
                                SimState::ServerUnreachable => (
                                    "● Server: Terputus (ECONNREFUSED)",
                                    theme::OFFLINE_FG,
                                ),
                                _ => (
                                    "○ Server: Terputus (Mode Simulasi Lokal)",
                                    theme::PENDING_FG,
                                ),
                            };
                            ui.label(
                                RichText::new(status_text)
                                    .font(FontId::monospace(11.0))
                                    .color(status_color),
                            );
                        });
                    });
                });
            });
    }
}

fn main() -> eframe::Result<()> {
    let native_options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_inner_size(Vec2::new(1040.0, 680.0))
            .with_min_inner_size(Vec2::new(840.0, 520.0))
            .with_title("Absensi-Cam // Peluncur Armada Edge"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam // Peluncur Armada Edge",
        native_options,
        Box::new(|cc| Ok(Box::new(LauncherApp::new(cc)))),
    )
}
