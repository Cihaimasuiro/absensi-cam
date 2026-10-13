use eframe::egui::{self, Color32, RichText, Vec2};

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

struct LauncherApp {
    server_url: String,
    search_query: String,
    active_tab: FilterTab,
    devices: Vec<EdgeDevice>,
    selected_device_id: Option<String>,
}

impl LauncherApp {
    fn new(_cc: &eframe::CreationContext<'_>) -> Self {
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
        // Set dark aesthetics
        let mut visuals = egui::Visuals::dark();
        visuals.panel_fill = Color32::from_rgb(18, 19, 24);
        visuals.window_fill = Color32::from_rgb(24, 25, 32);
        visuals.extreme_bg_color = Color32::from_rgb(12, 13, 17);
        ui.ctx().set_visuals(visuals);

        ui.add_space(8.0);

        // Header Top Bar
        ui.horizontal(|ui| {
            ui.heading(
                RichText::new("ABSENSI-CAM // FLEET LAUNCHER")
                    .size(15.0)
                    .strong()
                    .color(Color32::from_rgb(225, 230, 240)),
            );

            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                if ui.button("⚙ Settings").clicked() {}
                if ui.button("⟳ Rescan LAN").clicked() {}
                ui.label(
                    RichText::new(format!("Server: {}", self.server_url))
                        .size(12.0)
                        .color(Color32::from_rgb(140, 150, 170)),
                );
            });
        });

        ui.add_space(8.0);
        ui.separator();
        ui.add_space(8.0);

        // Filter Tabs & Search
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

            if ui
                .selectable_label(self.active_tab == FilterTab::All, format!("All ({all_cnt})"))
                .clicked()
            {
                self.active_tab = FilterTab::All;
            }
            if ui
                .selectable_label(
                    self.active_tab == FilterTab::Online,
                    format!("Online ({online_cnt})"),
                )
                .clicked()
            {
                self.active_tab = FilterTab::Online;
            }
            if ui
                .selectable_label(
                    self.active_tab == FilterTab::Offline,
                    format!("Offline ({offline_cnt})"),
                )
                .clicked()
            {
                self.active_tab = FilterTab::Offline;
            }
            if ui
                .selectable_label(
                    self.active_tab == FilterTab::Pending,
                    format!("Pending ({pending_cnt})"),
                )
                .clicked()
            {
                self.active_tab = FilterTab::Pending;
            }

            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.add(
                    egui::TextEdit::singleline(&mut self.search_query)
                        .hint_text("Search host, IP, or MAC...")
                        .desired_width(220.0),
                );
            });
        });

        ui.add_space(10.0);

        // Device Cards Scroll Area
        let filtered = self.filtered_devices();
        let mut new_selection: Option<String> = None;
        if filtered.is_empty() {
            ui.vertical_centered(|ui| {
                ui.add_space(40.0);
                ui.label(
                    RichText::new("No devices found matching filter.")
                        .size(13.0)
                        .color(Color32::from_rgb(130, 140, 160)),
                );
            });
        } else {
            let current_selected = self.selected_device_id.clone();
            egui::ScrollArea::vertical()
                .max_height(ui.available_height() - 32.0)
                .show(ui, |ui| {
                    for dev in filtered {
                        egui::Frame::group(ui.style())
                            .fill(Color32::from_rgb(26, 28, 36))
                            .stroke(egui::Stroke::new(1.0, Color32::from_rgb(45, 48, 60)))
                            .inner_margin(egui::Margin::same(12))
                            .corner_radius(egui::CornerRadius::same(6))
                            .show(ui, |ui| {
                                ui.horizontal(|ui| {
                                    let is_selected = current_selected.as_deref() == Some(&dev.id);
                                    // Left: Hostname, IP, info
                                    ui.vertical(|ui| {
                                        ui.horizontal(|ui| {
                                            let label = ui.selectable_label(
                                                is_selected,
                                                RichText::new(&dev.hostname)
                                                    .strong()
                                                    .size(14.0)
                                                    .color(Color32::from_rgb(240, 243, 250)),
                                            );
                                            if label.clicked() {
                                                new_selection = Some(dev.id.clone());
                                            }

                                            // Status pill
                                            let (badge_text, badge_color, badge_bg) = match dev.status {
                                                DeviceStatus::Online => (
                                                    "ONLINE",
                                                    Color32::from_rgb(52, 211, 153),
                                                    Color32::from_rgb(16, 45, 35),
                                                ),
                                                DeviceStatus::Offline => (
                                                    "OFFLINE",
                                                    Color32::from_rgb(248, 113, 113),
                                                    Color32::from_rgb(48, 20, 24),
                                                ),
                                                DeviceStatus::PendingPairing => (
                                                    "PENDING",
                                                    Color32::from_rgb(251, 191, 36),
                                                    Color32::from_rgb(48, 38, 16),
                                                ),
                                            };

                                            ui.label(
                                                RichText::new(format!(" {badge_text} "))
                                                    .size(10.0)
                                                    .strong()
                                                    .color(badge_color)
                                                    .background_color(badge_bg),
                                            );
                                        });

                                        ui.add_space(2.0);
                                        ui.label(
                                            RichText::new(format!(
                                                "IP: {}  •  MAC: {}  •  Engine: {}",
                                                dev.ip_address, dev.mac_address, dev.engine_version
                                            ))
                                            .size(11.0)
                                            .color(Color32::from_rgb(140, 150, 170)),
                                        );
                                    });

                                    // Right: Actions & telemetry
                                    ui.with_layout(
                                        egui::Layout::right_to_left(egui::Align::Center),
                                        |ui| {
                                            match dev.status {
                                                DeviceStatus::Online => {
                                                    if ui.button("Open Stream").clicked() {}
                                                    if ui.button("Inspect").clicked() {}
                                                }
                                                DeviceStatus::PendingPairing => {
                                                    if ui.button("✔ Approve & Pair").clicked() {}
                                                }
                                                DeviceStatus::Offline => {
                                                    if ui.button("Ping").clicked() {}
                                                }
                                            }

                                            // Temp indicator
                                            if dev.status == DeviceStatus::Online {
                                                ui.label(
                                                    RichText::new(format!("{:.1}°C", dev.temperature_c))
                                                        .size(12.0)
                                                        .color(if dev.temperature_c > 70.0 {
                                                            Color32::from_rgb(248, 113, 113)
                                                        } else {
                                                            Color32::from_rgb(52, 211, 153)
                                                        }),
                                                );
                                            }

                                            ui.label(
                                                RichText::new(format!("{}s ago", dev.last_seen_sec))
                                                    .size(11.0)
                                                    .color(Color32::from_rgb(110, 120, 140)),
                                            );
                                        },
                                    );
                                });
                            });
                        ui.add_space(6.0);
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
                    RichText::new("Fleet Engine: aarch64 (Rust) | Outbox: v1 | App: launcher-v0.1.0")
                        .size(11.0)
                        .color(Color32::from_rgb(100, 110, 130)),
                );
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    ui.label(
                        RichText::new("● Server Connected")
                            .size(11.0)
                            .color(Color32::from_rgb(52, 211, 153)),
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
