mod theme;

use eframe::egui::{self, Color32, FontFamily, FontId, RichText, Vec2};

#[derive(Debug, Clone, PartialEq)]
enum DeviceStatus {
    Online,
    Offline,
    PendingPairing,
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

fn format_relative_time(seconds: u64) -> String {
    if seconds < 60 {
        format!("{seconds}s ago")
    } else if seconds < 3600 {
        format!("{}m ago", seconds / 60)
    } else if seconds < 86400 {
        format!("{}h ago", seconds / 3600)
    } else {
        format!("{}d ago", seconds / 86400)
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
        // Configure custom fonts & dark brutalist tokens
        theme::configure_theme(&cc.egui_ctx);

        Self {
            server_url: "http://192.168.1.100:8000".to_string(),
            search_query: String::new(),
            active_tab: FilterTab::All,
            devices: vec![
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
            ],
            selected_device_id: None,
        }
    }

    fn filtered_devices(&self) -> Vec<&EdgeDevice> {
        self.devices
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
            .collect()
    }
}

impl eframe::App for LauncherApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        ui.add_space(10.0);

        // Header Top Bar
        ui.horizontal(|ui| {
            // Archivo display font for title
            let display_font = FontId::new(16.0, FontFamily::Name("display".into()));
            ui.label(
                RichText::new("ABSENSI-CAM")
                    .font(display_font)
                    .color(theme::INK),
            );

            // Subtitle with Geist Mono
            ui.label(
                RichText::new("// FLEET LAUNCHER")
                    .font(FontId::monospace(12.0))
                    .color(theme::INK_MUTED),
            );

            // Mock Data Tag
            ui.label(
                RichText::new(" MOCK DATA ")
                    .font(FontId::monospace(10.0))
                    .color(theme::PENDING_FG)
                    .background_color(theme::PENDING_BG),
            );

            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                if ui
                    .add(egui::Button::new(RichText::new("⚙ Settings").size(12.0)).min_size(Vec2::new(80.0, 28.0)))
                    .clicked()
                {}
                if ui
                    .add(egui::Button::new(RichText::new("⟳ Scan LAN").size(12.0)).min_size(Vec2::new(80.0, 28.0)))
                    .clicked()
                {}
                ui.label(
                    RichText::new(format!("Target: {}", self.server_url))
                        .font(FontId::monospace(11.0))
                        .color(theme::INK_DIM),
                );
            });
        });

        ui.add_space(10.0);
        ui.separator();
        ui.add_space(10.0);

        // Filter Tabs & Search Bar
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

            // Custom styled filter pill buttons
            let tabs = [
                (FilterTab::All, format!("All ({all_cnt})")),
                (FilterTab::Online, format!("Online ({online_cnt})")),
                (FilterTab::Offline, format!("Offline ({offline_cnt})")),
                (FilterTab::Pending, format!("Pending ({pending_cnt})")),
            ];

            for (tab, label) in tabs {
                let is_active = self.active_tab == tab;
                let text = RichText::new(label)
                    .font(FontId::monospace(11.0))
                    .color(if is_active { theme::INK } else { theme::INK_MUTED });

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
                    .min_size(Vec2::new(76.0, 28.0));

                if ui.add(btn).clicked() {
                    self.active_tab = tab;
                }
            }

            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.add(
                    egui::TextEdit::singleline(&mut self.search_query)
                        .hint_text("Search hostname, IP, or MAC...")
                        .font(FontId::proportional(12.0))
                        .desired_width(240.0)
                        .margin(egui::Margin::symmetric(8, 6)),
                );
            });
        });

        ui.add_space(12.0);

        // Device Cards Scroll Area
        let filtered = self.filtered_devices();
        let mut new_selection: Option<String> = None;

        if filtered.is_empty() {
            ui.vertical_centered(|ui| {
                ui.add_space(50.0);
                ui.label(
                    RichText::new("No devices found matching filter.")
                        .size(13.0)
                        .color(theme::INK_MUTED),
                );
            });
        } else {
            let current_selected = self.selected_device_id.clone();
            egui::ScrollArea::vertical()
                .max_height(ui.available_height() - 36.0)
                .show(ui, |ui| {
                    for dev in filtered {
                        let is_selected = current_selected.as_deref() == Some(&dev.id);

                        egui::Frame::group(ui.style())
                            .fill(if is_selected {
                                Color32::from_rgb(30, 33, 44)
                            } else {
                                theme::PANEL
                            })
                            .stroke(if is_selected {
                                egui::Stroke::new(1.0, theme::ACCENT)
                            } else {
                                theme::hairline()
                            })
                            .inner_margin(egui::Margin::symmetric(14, 12))
                            .corner_radius(theme::RADIUS)
                            .show(ui, |ui| {
                                ui.horizontal(|ui| {
                                    // Left: Hostname & Details
                                    ui.vertical(|ui| {
                                        ui.horizontal(|ui| {
                                            let host_btn = ui.selectable_label(
                                                is_selected,
                                                RichText::new(&dev.hostname)
                                                    .font(FontId::proportional(14.0))
                                                    .strong()
                                                    .color(theme::INK),
                                            );
                                            if host_btn.clicked() {
                                                new_selection = Some(dev.id.clone());
                                            }

                                            // Status pill
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
                                                    "PENDING",
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
                                        });

                                        ui.add_space(4.0);
                                        // Monospace IP, MAC, and Version
                                        ui.horizontal(|ui| {
                                            ui.label(
                                                RichText::new(format!("IP: {}", dev.ip_address))
                                                    .font(FontId::monospace(11.0))
                                                    .color(theme::INK_MUTED),
                                            );
                                            ui.label(
                                                RichText::new("•")
                                                    .size(10.0)
                                                    .color(theme::INK_DIM),
                                            );
                                            ui.label(
                                                RichText::new(format!("MAC: {}", dev.mac_address))
                                                    .font(FontId::monospace(11.0))
                                                    .color(theme::INK_MUTED),
                                            );
                                            ui.label(
                                                RichText::new("•")
                                                    .size(10.0)
                                                    .color(theme::INK_DIM),
                                            );
                                            ui.label(
                                                RichText::new(format!("Engine: {}", dev.engine_version))
                                                    .font(FontId::monospace(11.0))
                                                    .color(theme::INK_MUTED),
                                            );
                                        });
                                    });

                                    // Right: Actions & Telemetry
                                    ui.with_layout(
                                        egui::Layout::right_to_left(egui::Align::Center),
                                        |ui| {
                                            // Action Buttons with larger hit targets (min 30px height)
                                            match dev.status {
                                                DeviceStatus::Online => {
                                                    let btn = egui::Button::new(
                                                        RichText::new("Open Stream").size(12.0),
                                                    )
                                                    .min_size(Vec2::new(96.0, 30.0));
                                                    if ui.add(btn).clicked() {}

                                                    let inspect_btn = egui::Button::new(
                                                        RichText::new("Inspect").size(12.0),
                                                    )
                                                    .min_size(Vec2::new(72.0, 30.0));
                                                    if ui.add(inspect_btn).clicked() {}
                                                }
                                                DeviceStatus::PendingPairing => {
                                                    let approve_btn = egui::Button::new(
                                                        RichText::new("✔ Approve & Pair")
                                                            .size(12.0)
                                                            .color(theme::ONLINE_FG),
                                                    )
                                                    .min_size(Vec2::new(120.0, 30.0));
                                                    if ui.add(approve_btn).clicked() {}
                                                }
                                                DeviceStatus::Offline => {
                                                    let ping_btn = egui::Button::new(
                                                        RichText::new("Ping").size(12.0),
                                                    )
                                                    .min_size(Vec2::new(64.0, 30.0));
                                                    if ui.add(ping_btn).clicked() {}
                                                }
                                            }

                                            // Temp indicator
                                            if dev.status == DeviceStatus::Online {
                                                let temp_color = if dev.temperature_c > 70.0 {
                                                    theme::OFFLINE_FG
                                                } else {
                                                    theme::ONLINE_FG
                                                };
                                                ui.label(
                                                    RichText::new(format!("{:.1}°C", dev.temperature_c))
                                                        .font(FontId::monospace(12.0))
                                                        .color(temp_color),
                                                );
                                            }

                                            // Humanized relative time (e.g. "23m ago", "2s ago")
                                            ui.label(
                                                RichText::new(format_relative_time(dev.last_seen_sec))
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

        // Footer at bottom
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
                        RichText::new("○ Server: Not Connected (Local Mock Mode)")
                            .font(FontId::monospace(10.0))
                            .color(theme::PENDING_FG),
                    );
                });
            });
        });
    }
}

fn main() -> eframe::Result<()> {
    let native_options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_inner_size(Vec2::new(960.0, 620.0))
            .with_min_inner_size(Vec2::new(760.0, 480.0))
            .with_title("Absensi-Cam // Edge Fleet Launcher"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam // Edge Fleet Launcher",
        native_options,
        Box::new(|cc| Ok(Box::new(LauncherApp::new(cc)))),
    )
}
