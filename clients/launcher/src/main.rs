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
    status: DeviceStatus,
    engine_version: String,
    temperature_c: f32,
    last_seen_sec: u64,
}

#[derive(Debug, Clone, PartialEq)]
enum FilterTab {
    All,
    Online,
    Offline,
    Pending,
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
    devices: Vec<EdgeDevice>,
    selected_device_id: Option<String>,
}

impl LauncherApp {
    fn new(cc: &eframe::CreationContext<'_>) -> Self {
        theme::configure_theme(&cc.egui_ctx);

        Self {
            server_url: "http://192.168.1.100:8000".to_string(),
            search_query: String::new(),
            active_tab: FilterTab::All,
            devices: vec![
                EdgeDevice {
                    id: "dev-04".to_string(),
                    hostname: "orangepi-new-device".to_string(),
                    ip_address: "192.168.1.88".to_string(),
                    mac_address: "02:42:c0:a8:01:58".to_string(),
                    status: DeviceStatus::PendingPairing,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 50.2,
                    last_seen_sec: 12,
                },
                EdgeDevice {
                    id: "dev-01".to_string(),
                    hostname: "orangepi-gate-in".to_string(),
                    ip_address: "192.168.1.50".to_string(),
                    mac_address: "02:42:c0:a8:01:32".to_string(),
                    status: DeviceStatus::Online,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 54.8,
                    last_seen_sec: 2,
                },
                EdgeDevice {
                    id: "dev-02".to_string(),
                    hostname: "orangepi-gate-out".to_string(),
                    ip_address: "192.168.1.51".to_string(),
                    mac_address: "02:42:c0:a8:01:33".to_string(),
                    status: DeviceStatus::Online,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 56.1,
                    last_seen_sec: 5,
                },
                EdgeDevice {
                    id: "dev-03".to_string(),
                    hostname: "orangepi-canteen".to_string(),
                    ip_address: "192.168.1.55".to_string(),
                    mac_address: "02:42:c0:a8:01:37".to_string(),
                    status: DeviceStatus::Offline,
                    engine_version: "v0.2.8".to_string(),
                    temperature_c: 0.0,
                    last_seen_sec: 1420,
                },
            ],
            selected_device_id: None,
        }
    }

    fn filtered_devices(&self) -> Vec<&EdgeDevice> {
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
                }
            })
            .collect();

        // Hierarchy rule: Pending devices always float to the top
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
        // Enforce 18px perimeter padding around entire application window
        egui::Frame::new()
            .fill(theme::BG)
            .inner_margin(egui::Margin::symmetric(20, 16))
            .show(ui, |ui| {
                // 1. Header Bar
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

                    // Mock data badge
                    ui.label(
                        RichText::new(" DATA SIMULASI ")
                            .font(FontId::monospace(10.0))
                            .color(theme::PENDING_FG)
                            .background_color(theme::PENDING_BG),
                    );

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        if ui
                            .add(
                                egui::Button::new(
                                    RichText::new("⚙ Pengaturan")
                                        .font(FontId::proportional(12.0)),
                                )
                                .min_size(Vec2::new(96.0, 30.0)),
                            )
                            .clicked()
                        {}
                        if ui
                            .add(
                                egui::Button::new(
                                    RichText::new("⟳ Pindai LAN")
                                        .font(FontId::proportional(12.0)),
                                )
                                .min_size(Vec2::new(96.0, 30.0)),
                            )
                            .clicked()
                        {}
                        ui.label(
                            RichText::new(format!("Target: {}", self.server_url))
                                .font(FontId::monospace(11.0))
                                .color(theme::INK_DIM),
                        );
                    });
                });

                ui.add_space(12.0);
                ui.separator();
                ui.add_space(12.0);

                // 2. Filter Tabs & Search Bar (Uniform 30px height)
                ui.horizontal(|ui| {
                    let all_cnt = self.devices.len();
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
                    let pending_cnt = self
                        .devices
                        .iter()
                        .filter(|d| d.status == DeviceStatus::PendingPairing)
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
                            .color(if is_active {
                                theme::INK
                            } else {
                                theme::INK_MUTED
                            });

                        let btn = egui::Button::new(text)
                            .fill(if is_active {
                                theme::CONTROLS
                            } else {
                                Color32::TRANSPARENT
                            })
                            .stroke(if is_active {
                                egui::Stroke::new(1.0, theme::LINE_STRONG)
                            } else {
                                theme::hairline()
                            })
                            .corner_radius(theme::RADIUS)
                            .min_size(Vec2::new(90.0, 30.0));

                        if ui.add(btn).clicked() {
                            self.active_tab = tab;
                        }
                    }

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add(
                            egui::TextEdit::singleline(&mut self.search_query)
                                .hint_text("Cari hostname, IP, atau MAC...")
                                .font(FontId::proportional(12.0))
                                .desired_width(260.0)
                                .margin(egui::Margin::symmetric(10, 7)),
                        );
                    });
                });

                ui.add_space(14.0);

                // 3. Device Cards Scroll Area
                let filtered = self.filtered_devices();
                let mut new_selection: Option<String> = None;

                if filtered.is_empty() {
                    ui.vertical_centered(|ui| {
                        ui.add_space(60.0);
                        ui.label(
                            RichText::new("Tidak ada perangkat yang sesuai filter.")
                                .size(13.0)
                                .color(theme::INK_MUTED),
                        );
                    });
                } else {
                    let current_selected = self.selected_device_id.clone();
                    egui::ScrollArea::vertical()
                        .max_height(ui.available_height() - 40.0)
                        .show(ui, |ui| {
                            for dev in filtered {
                                let is_selected = current_selected.as_deref() == Some(&dev.id);
                                let is_pending = dev.status == DeviceStatus::PendingPairing;
                                let is_offline = dev.status == DeviceStatus::Offline;
                                let is_outdated = dev.engine_version != LATEST_ENGINE_VERSION;

                                // Card Styling based on status
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
                                    egui::Stroke::new(1.0, theme::PENDING_FG)
                                } else {
                                    theme::hairline()
                                };

                                egui::Frame::group(ui.style())
                                    .fill(card_fill)
                                    .stroke(card_stroke)
                                    .inner_margin(egui::Margin::symmetric(16, 14))
                                    .corner_radius(theme::RADIUS)
                                    .show(ui, |ui| {
                                        ui.horizontal(|ui| {
                                            // Left: Hostname & Technical Specs
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

                                                    // Status Pill
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
                                                            "MENUNGGU",
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

                                                    // Outdated version pill
                                                    if is_outdated {
                                                        ui.label(
                                                            RichText::new(" USANG ")
                                                                .font(FontId::monospace(10.0))
                                                                .strong()
                                                                .color(theme::PENDING_FG)
                                                                .background_color(theme::PENDING_BG),
                                                        );
                                                    }
                                                });

                                                ui.add_space(4.0);
                                                // High contrast Monospace Details
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
                                                    ui.label(
                                                        RichText::new("•")
                                                            .size(10.0)
                                                            .color(theme::INK_FAINT),
                                                    );
                                                    ui.label(
                                                        RichText::new(format!("MAC: {}", dev.mac_address))
                                                            .font(FontId::monospace(11.0))
                                                            .color(detail_ink),
                                                    );
                                                    ui.label(
                                                        RichText::new("•")
                                                            .size(10.0)
                                                            .color(theme::INK_FAINT),
                                                    );
                                                    ui.label(
                                                        RichText::new(format!("Engine: {}", dev.engine_version))
                                                            .font(FontId::monospace(11.0))
                                                            .color(detail_ink),
                                                    );
                                                });
                                            });

                                            // Right: Telemetry & Actions with clear hierarchy
                                            ui.with_layout(
                                                egui::Layout::right_to_left(egui::Align::Center),
                                                |ui| {
                                                    match dev.status {
                                                        DeviceStatus::Online => {
                                                            // Primary Action: Buka Stream
                                                            let stream_btn = egui::Button::new(
                                                                RichText::new("Buka Stream")
                                                                    .font(FontId::proportional(12.0))
                                                                    .strong()
                                                                    .color(Color32::WHITE),
                                                            )
                                                            .fill(theme::ACCENT_BG)
                                                            .min_size(Vec2::new(104.0, 30.0));

                                                            if ui.add(stream_btn).clicked() {}

                                                            // Secondary Action: Inspeksi (Outline)
                                                            let inspect_btn = egui::Button::new(
                                                                RichText::new("Inspeksi")
                                                                    .font(FontId::proportional(12.0))
                                                                    .color(theme::INK),
                                                            )
                                                            .fill(Color32::TRANSPARENT)
                                                            .stroke(theme::hairline())
                                                            .min_size(Vec2::new(88.0, 30.0));

                                                            if ui.add(inspect_btn).clicked() {}
                                                        }
                                                        DeviceStatus::PendingPairing => {
                                                            // Highlighted action for Pending
                                                            let approve_btn = egui::Button::new(
                                                                RichText::new("✔ Setujui & Hubungkan")
                                                                    .font(FontId::proportional(12.0))
                                                                    .strong()
                                                                    .color(theme::ONLINE_FG),
                                                            )
                                                            .fill(theme::ONLINE_BG)
                                                            .stroke(egui::Stroke::new(1.0, theme::ONLINE_FG))
                                                            .min_size(Vec2::new(160.0, 30.0));

                                                            if ui.add(approve_btn).clicked() {}
                                                        }
                                                        DeviceStatus::Offline => {
                                                            let ping_btn = egui::Button::new(
                                                                RichText::new("Ping")
                                                                    .font(FontId::proportional(12.0))
                                                                    .color(theme::INK_MUTED),
                                                            )
                                                            .fill(Color32::TRANSPARENT)
                                                            .stroke(theme::hairline())
                                                            .min_size(Vec2::new(88.0, 30.0));

                                                            if ui.add(ping_btn).clicked() {}
                                                        }
                                                    }

                                                    // CPU Telemetry with explicit label & threshold color
                                                    if dev.status == DeviceStatus::Online {
                                                        let temp_col = theme::temp_color(dev.temperature_c);
                                                        ui.label(
                                                            RichText::new(format!("CPU: {:.1}°C", dev.temperature_c))
                                                                .font(FontId::monospace(11.0))
                                                                .color(temp_col),
                                                        );
                                                    }

                                                    // Relative time in Indonesian
                                                    ui.label(
                                                        RichText::new(format_relative_time_id(dev.last_seen_sec))
                                                            .font(FontId::monospace(11.0))
                                                            .color(theme::INK_DIM),
                                                    );
                                                },
                                            );
                                        });
                                    });
                                ui.add_space(8.0);
                            }
                        });

                    if let Some(id) = new_selection {
                        self.selected_device_id = Some(id);
                    }
                }

                // 4. Footer Bar
                ui.with_layout(egui::Layout::bottom_up(egui::Align::LEFT), |ui| {
                    ui.add_space(4.0);
                    ui.separator();
                    ui.horizontal(|ui| {
                        ui.label(
                            RichText::new("Engine: aarch64 (Rust) | Outbox: v1 | App: launcher-v0.1.0-alpha")
                                .font(FontId::monospace(10.0))
                                .color(theme::INK_DIM),
                        );

                        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                            ui.label(
                                RichText::new("○ Server: Terputus (Mode Simulasi Lokal)")
                                    .font(FontId::monospace(10.0))
                                    .color(theme::PENDING_FG),
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
            .with_inner_size(Vec2::new(980.0, 640.0))
            .with_min_inner_size(Vec2::new(800.0, 500.0))
            .with_title("Absensi-Cam // Peluncur Armada Edge"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam // Peluncur Armada Edge",
        native_options,
        Box::new(|cc| Ok(Box::new(LauncherApp::new(cc)))),
    )
}
