mod theme;

use std::time::{Duration, Instant};
use eframe::egui::{self, Color32, Pos2, Rect, Response, RichText, Sense, Stroke, StrokeKind, Vec2};
use launcher_core::{
    format_relative_time_id, ApiClient, DeviceStatus, EdgeDevice, FilterTab, MockApiClient,
};

const LATEST_ENGINE_VERSION: &str = "v0.3.0-rust";

struct LauncherApp {
    client: MockApiClient,
    server_url: String,
    server_connected: bool,
    search_query: String,
    active_tab: FilterTab,
    devices: Vec<EdgeDevice>,
    is_scanning: bool,
    scan_started_at: Option<Instant>,
    last_scanned_time: String,
    notification: Option<(String, Instant)>,
    rename_device_id: Option<String>,
    rename_input: String,
    selected_building: String,
    buildings: Vec<String>,
    active_menu_device_id: Option<String>,
    simulated_server_error: bool,
    just_approved_device: Option<(String, Instant)>,
}

impl LauncherApp {
    fn new(cc: &eframe::CreationContext<'_>) -> Self {
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
    fn filtered_table_devices(&self) -> Vec<EdgeDevice> {
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

// -----------------------------------------------------------------------------
// Layout Helper untuk Menjamin Kolom Header & Baris 100% Sejajar Vertikal
// -----------------------------------------------------------------------------

struct TableLayout {
    col_x: [f32; 7],
    col_w: [f32; 7],
    total_w: f32,
}

impl TableLayout {
    fn compute(available_width: f32) -> Self {
        let col_gap = 16.0;
        let w_status = 110.0;
        let w_engine = 110.0;
        let w_temp = 90.0;
        let w_time = 110.0;
        let w_action = 190.0;

        let fixed_sum = w_status + w_engine + w_temp + w_time + w_action + (6.0 * col_gap);
        let avail_for_fr = (available_width - fixed_sum).max(280.0);
        let w_device = avail_for_fr * 0.5;
        let w_address = avail_for_fr * 0.5;

        let col_w = [
            w_status,
            w_device,
            w_address,
            w_engine,
            w_temp,
            w_time,
            w_action,
        ];

        let mut col_x = [0.0; 7];
        let mut cur = 0.0;
        for i in 0..7 {
            col_x[i] = cur;
            cur += col_w[i] + col_gap;
        }

        Self {
            col_x,
            col_w,
            total_w: cur - col_gap,
        }
    }
}

/// Helper untuk mengalokasikan scoped UI pada koordinat tepat (sub-sel tabel)
/// dengan perataan vertikal tengah (Align::Center) di egui 0.36
fn child_cell<R>(
    ui: &mut egui::Ui,
    rect: Rect,
    add_contents: impl FnOnce(&mut egui::Ui) -> R,
) -> R {
    ui.scope_builder(
        egui::UiBuilder::new()
            .max_rect(rect)
            .layout(egui::Layout::left_to_right(egui::Align::Center)),
        add_contents,
    )
    .inner
}

// -----------------------------------------------------------------------------
// Custom UI Helpers (Painter-based components)
// -----------------------------------------------------------------------------

/// Gambar titik status 8px:
/// - Online: lingkaran penuh hijau
/// - Offline: cincin 2px merah
/// - Pending: lingkaran penuh amber
fn draw_status_dot(ui: &mut egui::Ui, status: DeviceStatus) {
    let (rect, _) = ui.allocate_exact_size(Vec2::new(10.0, 10.0), Sense::hover());
    let center = rect.center();
    let painter = ui.painter();
    match status {
        DeviceStatus::Online => {
            painter.circle_filled(center, 4.0, theme::HIJAU_ONLINE);
        }
        DeviceStatus::Offline => {
            painter.circle_stroke(center, 4.0, Stroke::new(2.0, theme::MERAH_OFFLINE));
        }
        DeviceStatus::PendingPairing => {
            painter.circle_filled(center, 4.0, theme::AMBER);
        }
    }
}

/// Tombol tiga titik digambar dengan painter (circle_filled), TANPA glif teks
fn draw_three_dots_button(ui: &mut egui::Ui) -> Response {
    let size = Vec2::new(36.0, 36.0);
    let (rect, response) = ui.allocate_exact_size(size, Sense::click());
    if ui.is_rect_visible(rect) {
        let painter = ui.painter();
        let is_hovered = response.hovered();
        let is_active = response.is_pointer_button_down_on();

        let border_stroke = if is_active {
            Stroke::new(1.0, theme::TEKS)
        } else if is_hovered {
            Stroke::new(1.0, theme::TEKS_REDUP)
        } else {
            Stroke::new(1.0, theme::OUTLINE_TOMBOL)
        };

        let bg_color = if is_hovered || is_active {
            theme::PERMUKAAN
        } else {
            Color32::TRANSPARENT
        };

        painter.rect(rect, theme::RADIUS_SM, bg_color, border_stroke, StrokeKind::Inside);

        let dot_color = if is_hovered || is_active {
            theme::TEKS
        } else {
            theme::TEKS_REDUP
        };

        let center = rect.center();
        painter.circle_filled(Pos2::new(center.x, center.y - 5.0), 1.5, dot_color);
        painter.circle_filled(center, 1.5, dot_color);
        painter.circle_filled(Pos2::new(center.x, center.y + 5.0), 1.5, dot_color);
    }
    response
}

/// Tab sebagai teks dengan garis bawah 2px untuk yang aktif, jumlah berwarna redup
fn draw_tab_item(ui: &mut egui::Ui, label: &str, count: usize, is_active: bool) -> bool {
    let text_font = theme::font_body();
    let count_font = theme::font_caption();

    let text_color = if is_active {
        theme::TEKS
    } else {
        theme::TEKS_REDUP
    };
    let count_color = theme::TEKS_REDUP;

    let galley_label = ui.painter().layout_no_wrap(label.to_string(), text_font, text_color);
    let galley_count = ui.painter().layout_no_wrap(format!(" {count}"), count_font, count_color);

    let total_width = galley_label.size().x + galley_count.size().x;
    let height = 30.0;

    let (rect, response) = ui.allocate_exact_size(Vec2::new(total_width, height), Sense::click());

    if ui.is_rect_visible(rect) {
        let painter = ui.painter();
        let y_pos = rect.top() + (height - galley_label.size().y) / 2.0 - 2.0;
        let label_pos = Pos2::new(rect.left(), y_pos);
        painter.galley(label_pos, galley_label, text_color);

        let count_pos = Pos2::new(label_pos.x + rect.width() - galley_count.size().x, y_pos + 1.0);
        painter.galley(count_pos, galley_count, count_color);

        // Garis bawah 2px untuk yang aktif
        if is_active {
            painter.line_segment(
                [
                    Pos2::new(rect.left(), rect.bottom() - 1.0),
                    Pos2::new(rect.right(), rect.bottom() - 1.0),
                ],
                Stroke::new(2.0, theme::TEKS),
            );
        }
    }

    response.clicked()
}

// -----------------------------------------------------------------------------
// eframe App Implementation
// -----------------------------------------------------------------------------

impl eframe::App for LauncherApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        // Handle scanning state progression
        if self.is_scanning {
            if let Some(started) = self.scan_started_at {
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
                // -----------------------------------------------------------------
                // 1. Header
                // judul "Perangkat" 22px semibold + teks redup "Absensi-Cam · armada edge";
                // kanan: titik status server + teks, dan tombol outline "Pindai jaringan"
                // -----------------------------------------------------------------
                ui.horizontal(|ui| {
                    ui.label(
                        RichText::new("Perangkat")
                            .font(theme::font_title())
                            .color(theme::TEKS),
                    );
                    ui.add_space(8.0);
                    ui.label(
                        RichText::new("Absensi-Cam · armada edge")
                            .font(theme::font_body())
                            .color(theme::TEKS_REDUP),
                    );

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        // Tombol outline transparan "Pindai jaringan" (tinggi 36, radius 6, stroke #3A403B)
                        let scan_text = if self.is_scanning {
                            "Memindai..."
                        } else {
                            "Pindai jaringan"
                        };

                        let scan_btn = egui::Button::new(
                            RichText::new(scan_text)
                                .font(theme::font_body())
                                .color(if self.is_scanning {
                                    theme::TEKS_REDUP
                                } else {
                                    theme::TEKS
                                }),
                        )
                        .fill(Color32::TRANSPARENT)
                        .stroke(Stroke::new(1.0, theme::OUTLINE_TOMBOL))
                        .corner_radius(theme::RADIUS_SM)
                        .min_size(Vec2::new(130.0, 36.0));

                        let btn_resp = ui.add_enabled(!self.is_scanning, scan_btn);
                        if btn_resp.clicked() && !self.is_scanning {
                            self.is_scanning = true;
                            self.scan_started_at = Some(Instant::now());
                            // Langsung hapus notifikasi lama saat pemindaian baru dimulai
                            self.notification = None;
                        }

                        ui.add_space(16.0);

                        // Titik status server + teks
                        ui.horizontal(|ui| {
                            draw_status_dot(
                                ui,
                                if self.server_connected && !self.simulated_server_error {
                                    DeviceStatus::Online
                                } else {
                                    DeviceStatus::Offline
                                },
                            );
                            ui.add_space(6.0);
                            let server_text = if self.server_connected && !self.simulated_server_error {
                                format!("server: {}", self.server_url)
                            } else {
                                "server tidak terjangkau".to_string()
                            };
                            ui.label(
                                RichText::new(server_text)
                                    .font(theme::font_caption())
                                    .color(theme::TEKS_REDUP),
                            );
                        });
                    });
                });

                // -----------------------------------------------------------------
                // Keadaan: Banner Tipis (Memindai / Server Putus / Token Habis)
                // -----------------------------------------------------------------
                if self.is_scanning {
                    ui.add_space(12.0);
                    ui.vertical(|ui| {
                        ui.label(
                            RichText::new("Memindai subnet LAN 192.168.1.0/24...")
                                .font(theme::font_caption())
                                .color(theme::TEKS_REDUP),
                        );
                        ui.add_space(4.0);
                        let progress = if let Some(started) = self.scan_started_at {
                            (started.elapsed().as_secs_f32() / 1.5).min(1.0)
                        } else {
                            0.0
                        };
                        let (rect, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 2.0), Sense::hover());
                        let painter = ui.painter();
                        painter.rect_filled(rect, 0.0, theme::GARIS);
                        // Warna netral untuk progress bar (bukan amber)
                        let fill_rect = Rect::from_min_size(rect.min, Vec2::new(rect.width() * progress, 2.0));
                        painter.rect_filled(fill_rect, 0.0, theme::TEKS_REDUP);
                    });
                }

                if self.simulated_server_error {
                    ui.add_space(12.0);
                    egui::Frame::new()
                        .fill(theme::PERMUKAAN)
                        .stroke(Stroke::new(1.0, theme::MERAH_OFFLINE))
                        .corner_radius(theme::RADIUS_SM)
                        .inner_margin(egui::Margin::symmetric(14, 10))
                        .show(ui, |ui| {
                            ui.horizontal(|ui| {
                                ui.label(
                                    RichText::new("Server tidak terjangkau atau token sesi telah habis.")
                                        .font(theme::font_body())
                                        .color(theme::TEKS),
                                );
                                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                    if ui.button(RichText::new("Ubah URL").font(theme::font_caption())).clicked() {
                                        // Ubah URL
                                    }
                                    if ui.button(RichText::new("Coba lagi").font(theme::font_caption())).clicked() {
                                        self.simulated_server_error = false;
                                    }
                                });
                            });
                        });
                }

                // Jarak antar bagian: 24px
                ui.add_space(24.0);

                // -----------------------------------------------------------------
                // 2. Blok "Menunggu persetujuan"
                // Muncul hanya jika ada; bila lebih dari satu, tumpuk.
                // Bingkai 1px amber, radius 6, latar #1B1912, tanpa garis tebal di satu sisi.
                // -----------------------------------------------------------------
                let pending_devices: Vec<EdgeDevice> = self
                    .devices
                    .iter()
                    .filter(|d| d.status == DeviceStatus::PendingPairing)
                    .cloned()
                    .collect();

                for dev in &pending_devices {
                    egui::Frame::new()
                        .fill(theme::LATAR_MENUNGGU)
                        .stroke(Stroke::new(1.0, theme::AMBER))
                        .corner_radius(theme::RADIUS_SM)
                        .inner_margin(egui::Margin::symmetric(20, 16))
                        .show(ui, |ui| {
                            ui.horizontal(|ui| {
                                // Kolom Kiri: Detail teknis perangkat
                                ui.vertical(|ui| {
                                    // Nama perangkat 18px semibold
                                    ui.label(
                                        RichText::new(&dev.hostname)
                                            .font(theme::font_subheading())
                                            .color(theme::TEKS),
                                    );
                                    ui.add_space(4.0);

                                    // MAC 20px mono + label "(cocokkan dengan stiker)" sejajar vertikal
                                    ui.horizontal(|ui| {
                                        ui.label(
                                            RichText::new(&dev.mac_address)
                                                .font(theme::font_mac_large())
                                                .color(theme::TEKS),
                                        );
                                        ui.add_space(6.0);
                                        ui.label(
                                            RichText::new("(cocokkan dengan stiker)")
                                                .font(theme::font_caption())
                                                .color(theme::TEKS_REDUP),
                                        );
                                    });

                                    ui.add_space(4.0);

                                    // IP terlihat server (BUKAN dilaporkan perangkat) & kode
                                    ui.horizontal(|ui| {
                                        ui.label(
                                            RichText::new(format!("IP terlihat server: {}", dev.ip_address))
                                                .font(theme::font_technical())
                                                .color(theme::TEKS_REDUP),
                                        );
                                        ui.add_space(12.0);
                                        if let Some(ref code) = dev.pairing_code {
                                            ui.label(
                                                RichText::new(format!("kode: {code}"))
                                                    .font(theme::font_technical())
                                                    .color(theme::AMBER),
                                            );
                                        }
                                    });

                                    ui.add_space(4.0);

                                    // Waktu permintaan & kedaluwarsa
                                    let rel_time = format_relative_time_id(dev.last_seen_sec);
                                    ui.label(
                                        RichText::new(format!("Meminta {rel_time} lalu · kedaluwarsa 09:48"))
                                            .font(theme::font_caption())
                                            .color(theme::TEKS_REDUP),
                                    );
                                });

                                // Kolom Kanan: Pilihan Gedung (dengan label & border 1px #3A403B),
                                // Tombol Padat Amber "Setujui", Tombol Teks Merah "Tolak"
                                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                    // Tombol teks merah "Tolak"
                                    let reject_btn = egui::Button::new(
                                        RichText::new("Tolak")
                                            .font(theme::font_body())
                                            .color(theme::MERAH_OFFLINE),
                                    )
                                    .fill(Color32::TRANSPARENT)
                                    .stroke(Stroke::NONE);

                                    if ui.add(reject_btn).clicked() {
                                        rejected_id = Some(dev.id.clone());
                                    }

                                    ui.add_space(16.0);

                                    // SATU-SATUNYA tombol padat di layar: "Setujui" amber #E8B449 dengan teks #1A1405
                                    let approve_btn = egui::Button::new(
                                        RichText::new("Setujui")
                                            .font(theme::font_body_medium())
                                            .color(theme::TEKS_DI_ATAS_AMBER),
                                    )
                                    .fill(theme::AMBER)
                                    .stroke(Stroke::NONE)
                                    .corner_radius(theme::RADIUS_SM)
                                    .min_size(Vec2::new(96.0, 36.0));

                                    if ui.add(approve_btn).clicked() {
                                        approved_id = Some(dev.id.clone());
                                    }

                                    ui.add_space(16.0);

                                    // Pilihan Gedung: berlabel "Gedung" dan border 1px #3A403B, tinggi serasi (36px)
                                    ui.horizontal(|ui| {
                                        ui.label(
                                            RichText::new("Gedung:")
                                                .font(theme::font_caption())
                                                .color(theme::TEKS_REDUP),
                                        );
                                        ui.add_space(4.0);

                                        egui::Frame::new()
                                            .fill(theme::PERMUKAAN)
                                            .stroke(Stroke::new(1.0, theme::OUTLINE_TOMBOL))
                                            .corner_radius(theme::RADIUS_SM)
                                            .inner_margin(egui::Margin::symmetric(8, 4))
                                            .show(ui, |ui| {
                                                egui::ComboBox::from_id_salt(format!("building_select_{}", dev.id))
                                                    .selected_text(
                                                        RichText::new(&self.selected_building)
                                                            .font(theme::font_body())
                                                            .color(theme::TEKS),
                                                    )
                                                    .show_ui(ui, |ui| {
                                                        for b in &self.buildings {
                                                            ui.selectable_value(
                                                                &mut self.selected_building,
                                                                b.clone(),
                                                                RichText::new(b).font(theme::font_body()),
                                                            );
                                                        }
                                                    });
                                            });
                                    });
                                });
                            });
                        });

                    ui.add_space(12.0);
                }

                if !pending_devices.is_empty() {
                    ui.add_space(12.0);
                }

                // -----------------------------------------------------------------
                // 3. Tab sebagai teks dengan garis bawah 2px & Kolom Cari 260px di kanan
                // Urutan: Semua, Menunggu, Online, Offline (Huruf Awal Kapital)
                // -----------------------------------------------------------------
                ui.horizontal(|ui| {
                    // Hitungan tab 'Semua' hanya perangkat terdaftar
                    let registered_cnt = self
                        .devices
                        .iter()
                        .filter(|d| d.status != DeviceStatus::PendingPairing)
                        .count();
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

                    if draw_tab_item(ui, "Semua", registered_cnt, self.active_tab == FilterTab::All) {
                        self.active_tab = FilterTab::All;
                    }
                    ui.add_space(20.0);

                    // Tetap tampilkan tab Menunggu agar bilah navigasi tidak bergeser
                    if draw_tab_item(ui, "Menunggu", pending_cnt, self.active_tab == FilterTab::Pending) {
                        self.active_tab = FilterTab::Pending;
                    }
                    ui.add_space(20.0);

                    if draw_tab_item(ui, "Online", online_cnt, self.active_tab == FilterTab::Online) {
                        self.active_tab = FilterTab::Online;
                    }
                    ui.add_space(20.0);

                    if draw_tab_item(ui, "Offline", offline_cnt, self.active_tab == FilterTab::Offline) {
                        self.active_tab = FilterTab::Offline;
                    }

                    // Kolom cari 260px di kanan (tinggi 36, radius 6)
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        let search_edit = egui::TextEdit::singleline(&mut self.search_query)
                            .hint_text("Cari perangkat, IP, MAC...")
                            .font(theme::font_body())
                            .desired_width(260.0)
                            .min_size(Vec2::new(260.0, 36.0))
                            .margin(egui::Margin::symmetric(12, 8));

                        ui.add(search_edit);
                    });
                });

                // Jarak antar bagian: 24px
                ui.add_space(24.0);

                // -----------------------------------------------------------------
                // Modal Rename (jika dipicu dari menu tiga titik)
                // -----------------------------------------------------------------
                if let Some(ref dev_id) = self.rename_device_id.clone() {
                    egui::Frame::new()
                        .fill(theme::PERMUKAAN)
                        .stroke(Stroke::new(1.0, theme::GARIS))
                        .inner_margin(egui::Margin::symmetric(16, 12))
                        .corner_radius(theme::RADIUS_SM)
                        .show(ui, |ui| {
                            ui.horizontal(|ui| {
                                ui.label(
                                    RichText::new("Ubah nama perangkat:")
                                        .font(theme::font_body())
                                        .color(theme::TEKS),
                                );
                                ui.add(
                                    egui::TextEdit::singleline(&mut self.rename_input)
                                        .desired_width(220.0)
                                        .font(theme::font_body()),
                                );
                                if ui.button(RichText::new("Simpan").font(theme::font_body())).clicked() {
                                    if self.client.rename_device(dev_id, &self.rename_input).is_ok() {
                                        if let Some(dev) = self.devices.iter_mut().find(|d| &d.id == dev_id) {
                                            dev.hostname = self.rename_input.clone();
                                        }
                                        self.notification = Some((
                                            format!("Nama diubah menjadi '{}'", self.rename_input),
                                            Instant::now(),
                                        ));
                                    }
                                    self.rename_device_id = None;
                                    self.rename_input.clear();
                                }
                                if ui.button(RichText::new("Batal").font(theme::font_body())).clicked() {
                                    self.rename_device_id = None;
                                    self.rename_input.clear();
                                }
                            });
                        });
                    ui.add_space(16.0);
                }

                // -----------------------------------------------------------------
                // 4. Tabel Tanpa Kartu (100% Sejajar dengan Header)
                // Kolom: Status 110 | Perangkat 1.4fr | Alamat 1.4fr | Engine 110 | Suhu CPU 90 | Terakhir 110 | Aksi 190, jarak kolom 16
                // Header kolom: huruf kapital awal, 12px, redup
                // Baris: padding vertikal 16 (tinggi baris ~56px), 1px pemisah baris
                // -----------------------------------------------------------------
                let table_devices = self.filtered_table_devices();
                let available_table_width = ui.available_width();
                let layout = TableLayout::compute(available_table_width);

                if self.devices.is_empty() {
                    // Daftar kosong total (0 perangkat) -> Tampilkan 3 langkah singkat
                    ui.vertical(|ui| {
                        ui.add_space(20.0);
                        ui.label(
                            RichText::new("Belum ada perangkat terdaftar")
                                .font(theme::font_subheading())
                                .color(theme::TEKS),
                        );
                        ui.add_space(6.0);
                        ui.label(
                            RichText::new("Tidak ditemukan perangkat edge Orange Pi di jaringan lokal. Lakukan pemeriksaan singkat berikut:")
                                .font(theme::font_body())
                                .color(theme::TEKS_REDUP),
                        );
                        ui.add_space(16.0);

                        egui::Frame::new()
                            .fill(theme::PERMUKAAN)
                            .stroke(Stroke::new(1.0, theme::GARIS))
                            .corner_radius(theme::RADIUS_SM)
                            .inner_margin(egui::Margin::symmetric(20, 16))
                            .show(ui, |ui| {
                                ui.vertical(|ui| {
                                    ui.label(
                                        RichText::new("1. Periksa sambungan kabel LAN dan pastikan lampu link switch menyala.")
                                            .font(theme::font_body())
                                            .color(theme::TEKS),
                                    );
                                    ui.add_space(6.0);
                                    ui.label(
                                        RichText::new("2. Pastikan service smart-absensi berjalan aktif di perangkat edge.")
                                            .font(theme::font_body())
                                            .color(theme::TEKS),
                                    );
                                    ui.add_space(6.0);
                                    ui.label(
                                        RichText::new("3. Verifikasi subnet jaringan berada pada jangkauan subnet yang sama (192.168.1.0/24).")
                                            .font(theme::font_body())
                                            .color(theme::TEKS),
                                    );
                                });
                            });

                        ui.add_space(16.0);
                        if ui
                            .button(RichText::new("Pindai ulang jaringan").font(theme::font_body()))
                            .clicked()
                        {
                            self.devices = self.client.list_devices().unwrap_or_default();
                        }
                    });
                } else if table_devices.is_empty() {
                    // Filter kosong (ada perangkat tetapi pencarian / tab tidak cocok)
                    ui.vertical(|ui| {
                        ui.add_space(20.0);
                        ui.label(
                            RichText::new("Tidak ada perangkat yang cocok")
                                .font(theme::font_subheading())
                                .color(theme::TEKS),
                        );
                        ui.add_space(6.0);
                        ui.label(
                            RichText::new("Tidak ditemukan perangkat yang memenuhi filter atau kata kunci pencarian.")
                                .font(theme::font_body())
                                .color(theme::TEKS_REDUP),
                        );
                        ui.add_space(12.0);
                        if ui
                            .button(RichText::new("Reset filter pencarian").font(theme::font_body()))
                            .clicked()
                        {
                            self.active_tab = FilterTab::All;
                            self.search_query.clear();
                        }
                    });
                } else {
                    // Header Kolom: huruf kapital awal, 12px, redup
                    let headers = [
                        "Status",
                        "Perangkat",
                        "Alamat",
                        "Engine",
                        "Suhu CPU",
                        "Terakhir",
                        "Aksi",
                    ];

                    let (header_row_rect, _) = ui.allocate_exact_size(Vec2::new(layout.total_w, 24.0), Sense::hover());
                    for (i, h) in headers.iter().enumerate() {
                        let cell_rect = Rect::from_min_size(
                            Pos2::new(header_row_rect.left() + layout.col_x[i], header_row_rect.top()),
                            Vec2::new(layout.col_w[i], 24.0),
                        );
                        ui.scope_builder(
                            egui::UiBuilder::new().max_rect(cell_rect).layout(egui::Layout::left_to_right(egui::Align::Center)),
                            |ui| {
                                ui.label(
                                    RichText::new(*h)
                                        .font(theme::font_table_header())
                                        .color(theme::TEKS_REDUP),
                                );
                            },
                        );
                    }

                    // Garis pemisah header
                    ui.add_space(4.0);
                    let (sep_rect, _) = ui.allocate_exact_size(Vec2::new(layout.total_w, 1.0), Sense::hover());
                    ui.painter().rect_filled(sep_rect, 0.0, theme::PEMISAH_BARIS);
                    ui.add_space(4.0);

                    // Daftar Baris Tabel dalam ScrollArea
                    egui::ScrollArea::vertical()
                        .max_height(ui.available_height() - 40.0)
                        .show(ui, |ui| {
                            for dev in &table_devices {
                                let is_offline = dev.status == DeviceStatus::Offline;
                                let is_pending = dev.status == DeviceStatus::PendingPairing;
                                let is_outdated = dev.engine_version != LATEST_ENGINE_VERSION;

                                // Offline rule: seluruh teks baris redup
                                let text_color = if is_offline {
                                    theme::TEKS_REDUP
                                } else {
                                    theme::TEKS
                                };

                                // Cek apakah baris ini baru saja disetujui (sorot selama ~1.5 detik)
                                let is_just_approved = self
                                    .just_approved_device
                                    .as_ref()
                                    .map(|(id, t)| id == &dev.id && t.elapsed() < Duration::from_millis(1500))
                                    .unwrap_or(false);

                                // Alokasi baris tepat dengan tinggi 56px (padding vertikal 16px terdistribusi di tengah)
                                let (row_rect, _) = ui.allocate_exact_size(Vec2::new(layout.total_w, 56.0), Sense::hover());

                                if is_just_approved {
                                    // Sorot baris baru netral/hijau lembut
                                    ui.painter().rect_filled(
                                        row_rect,
                                        theme::RADIUS_SM,
                                        Color32::from_rgba_unmultiplied(98, 196, 143, 20),
                                    );
                                }

                                // 1. Kolom Status (110px)
                                let cell_status = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[0], row_rect.top()),
                                    Vec2::new(layout.col_w[0], 56.0),
                                );
                                child_cell(ui, cell_status, |ui| {
                                    draw_status_dot(ui, dev.status);
                                    ui.add_space(6.0);
                                    let st_text = match dev.status {
                                        DeviceStatus::Online => "Online",
                                        DeviceStatus::Offline => "Offline",
                                        DeviceStatus::PendingPairing => "Menunggu",
                                    };
                                    ui.label(
                                        RichText::new(st_text)
                                            .font(theme::font_body())
                                            .color(text_color),
                                    );
                                });

                                // 2. Kolom Perangkat (1.4fr)
                                let cell_device = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[1], row_rect.top()),
                                    Vec2::new(layout.col_w[1], 56.0),
                                );
                                child_cell(ui, cell_device, |ui| {
                                    ui.label(
                                        RichText::new(&dev.hostname)
                                            .font(theme::font_body_medium())
                                            .color(text_color),
                                    );
                                });

                                // 3. Kolom Alamat (1.4fr): IP (baris 1) & MAC (baris 2) rata tengah vertikal
                                let cell_addr = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[2], row_rect.top()),
                                    Vec2::new(layout.col_w[2], 56.0),
                                );
                                child_cell(ui, cell_addr, |ui| {
                                    ui.vertical(|ui| {
                                        ui.label(
                                            RichText::new(&dev.ip_address)
                                                .font(theme::font_technical())
                                                .color(text_color),
                                        );
                                        ui.label(
                                            RichText::new(&dev.mac_address)
                                                .font(theme::font_technical_sm())
                                                .color(theme::TEKS_REDUP),
                                        );
                                    });
                                });

                                // 4. Kolom Engine (110px): versi usang tanpa chip
                                let cell_engine = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[3], row_rect.top()),
                                    Vec2::new(layout.col_w[3], 56.0),
                                );
                                child_cell(ui, cell_engine, |ui| {
                                    if is_outdated {
                                        ui.label(
                                            RichText::new(format!("{} · usang", dev.engine_version))
                                                .font(theme::font_technical())
                                                .color(theme::TEKS_REDUP),
                                        );
                                    } else {
                                        ui.label(
                                            RichText::new(&dev.engine_version)
                                                .font(theme::font_technical())
                                                .color(text_color),
                                        );
                                    }
                                });

                                // 5. Kolom Suhu CPU (90px)
                                let cell_temp = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[4], row_rect.top()),
                                    Vec2::new(layout.col_w[4], 56.0),
                                );
                                child_cell(ui, cell_temp, |ui| {
                                    let temp_text = if dev.status == DeviceStatus::Online {
                                        format!("{:.1}°C", dev.temperature_c)
                                    } else {
                                        "—".to_string()
                                    };
                                    ui.label(
                                        RichText::new(temp_text)
                                            .font(theme::font_technical())
                                            .color(text_color),
                                    );
                                });

                                // 6. Kolom Terakhir (110px)
                                let cell_time = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[5], row_rect.top()),
                                    Vec2::new(layout.col_w[5], 56.0),
                                );
                                child_cell(ui, cell_time, |ui| {
                                    let rel = format_relative_time_id(dev.last_seen_sec);
                                    ui.label(
                                        RichText::new(rel)
                                            .font(theme::font_caption())
                                            .color(theme::TEKS_REDUP),
                                    );
                                });

                                // 7. Kolom Aksi (190px): outline button (lebar sama 110px, tinggi 36) + tombol 3 titik
                                let cell_action = Rect::from_min_size(
                                    Pos2::new(row_rect.left() + layout.col_x[6], row_rect.top()),
                                    Vec2::new(layout.col_w[6], 56.0),
                                );
                                child_cell(ui, cell_action, |ui| {
                                    if is_pending {
                                        // Tidak ada tombol Ping untuk perangkat yang belum disetujui
                                        ui.label(
                                            RichText::new("Menunggu persetujuan")
                                                .font(theme::font_caption())
                                                .color(theme::TEKS_REDUP),
                                        );
                                    } else {
                                        let main_btn_text = if dev.status == DeviceStatus::Online {
                                            "Buka stream"
                                        } else {
                                            "Ping"
                                        };

                                        // Outline transparan, stroke #3A403B, lebar sama persis 110.0px
                                        let main_btn = egui::Button::new(
                                            RichText::new(main_btn_text)
                                                .font(theme::font_body())
                                                .color(theme::TEKS),
                                        )
                                        .fill(Color32::TRANSPARENT)
                                        .stroke(Stroke::new(1.0, theme::OUTLINE_TOMBOL))
                                        .corner_radius(theme::RADIUS_SM)
                                        .min_size(Vec2::new(110.0, 36.0));

                                        if ui.add(main_btn).clicked() {
                                            if dev.status == DeviceStatus::Online {
                                                copy_notification = Some(format!(
                                                    "Membuka stream http://{}:5000/stream",
                                                    dev.ip_address
                                                ));
                                            } else {
                                                copy_notification = Some(format!(
                                                    "Mengirim ping ke {} ({})",
                                                    dev.hostname, dev.ip_address
                                                ));
                                            }
                                        }

                                        ui.add_space(8.0);

                                        // Tombol tiga titik painter
                                        let dots_resp = draw_three_dots_button(ui);
                                        let popup_id = ui.make_persistent_id(format!("menu_{}", dev.id));
                                        let dots_rect = dots_resp.rect;

                                        if dots_resp.clicked() {
                                            if self.active_menu_device_id.as_deref() == Some(&dev.id) {
                                                self.active_menu_device_id = None;
                                            } else {
                                                self.active_menu_device_id = Some(dev.id.clone());
                                            }
                                        }

                                        // Popover Menu ~180px, rata kanan di bawah tombol titik tiga, tutup saat klik di luar
                                        if self.active_menu_device_id.as_deref() == Some(&dev.id) {
                                            let menu_width = 180.0;
                                            let menu_pos = Pos2::new(dots_rect.right() - menu_width, dots_rect.bottom() + 4.0);
                                            let menu_bounds = Rect::from_min_size(menu_pos, Vec2::new(menu_width, 140.0));

                                            // Deteksi klik di luar popover untuk menutup otomatis
                                            if ui.input(|i| i.pointer.any_pressed()) {
                                                if let Some(pos) = ui.input(|i| i.pointer.interact_pos()) {
                                                    if !menu_bounds.contains(pos) && !dots_rect.contains(pos) {
                                                        close_active_menu = true;
                                                    }
                                                }
                                            }

                                            egui::Area::new(popup_id)
                                                .order(egui::Order::Foreground)
                                                .fixed_pos(menu_pos)
                                                .show(ui.ctx(), |ui| {
                                                    egui::Frame::new()
                                                        .fill(theme::PERMUKAAN)
                                                        .stroke(Stroke::new(1.0, theme::GARIS))
                                                        .corner_radius(theme::RADIUS_SM)
                                                        .inner_margin(egui::Margin::symmetric(8, 6))
                                                        .show(ui, |ui| {
                                                            ui.set_width(180.0);

                                                            // Helper item menu: baris teks polos transparan dengan highlight saat hover
                                                            let menu_item = |ui: &mut egui::Ui, text: &str, is_danger: bool| -> bool {
                                                                let text_color = if is_danger {
                                                                    theme::MERAH_OFFLINE
                                                                } else {
                                                                    theme::TEKS
                                                                };
                                                                let resp = ui.add(
                                                                    egui::Button::new(
                                                                        RichText::new(text)
                                                                            .font(theme::font_body())
                                                                            .color(text_color),
                                                                    )
                                                                    .fill(Color32::TRANSPARENT)
                                                                    .stroke(Stroke::NONE)
                                                                    .corner_radius(egui::CornerRadius::same(4))
                                                                    .min_size(Vec2::new(164.0, 28.0)),
                                                                );
                                                                resp.clicked()
                                                            };

                                                            if menu_item(ui, "Salin IP", false) {
                                                                ui.ctx().copy_text(dev.ip_address.clone());
                                                                copy_notification = Some(format!("IP {} disalin", dev.ip_address));
                                                                close_active_menu = true;
                                                            }
                                                            if menu_item(ui, "Salin MAC", false) {
                                                                ui.ctx().copy_text(dev.mac_address.clone());
                                                                copy_notification = Some(format!("MAC {} disalin", dev.mac_address));
                                                                close_active_menu = true;
                                                            }

                                                            ui.add_space(2.0);
                                                            let (m_sep, _) = ui.allocate_exact_size(Vec2::new(164.0, 1.0), Sense::hover());
                                                            ui.painter().rect_filled(m_sep, 0.0, theme::PEMISAH_BARIS);
                                                            ui.add_space(2.0);

                                                            if menu_item(ui, "Ubah nama", false) {
                                                                trigger_rename = Some((dev.id.clone(), dev.hostname.clone()));
                                                                close_active_menu = true;
                                                            }
                                                            if menu_item(ui, "Lupakan perangkat", true) {
                                                                forget_id = Some(dev.id.clone());
                                                                close_active_menu = true;
                                                            }
                                                        });
                                                });
                                        }
                                    }
                                });

                                // Garis pemisah 1px antar baris
                                let (row_sep, _) = ui.allocate_exact_size(Vec2::new(layout.total_w, 1.0), Sense::hover());
                                ui.painter().rect_filled(row_sep, 0.0, theme::PEMISAH_BARIS);
                            }
                        });
                }

                if close_active_menu {
                    self.active_menu_device_id = None;
                }

                // -----------------------------------------------------------------
                // Actions handling
                // -----------------------------------------------------------------
                if let Some(id) = approved_id {
                    if self.client.approve_device(&id).is_ok() {
                        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
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
                    }
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

                // -----------------------------------------------------------------
                // 5. Footer
                // Kiri: "Dipindai 10:42 · 4 perangkat terdaftar"
                // Kanan: "Tampilan contoh dengan data simulasi"
                // Pesan notifikasi: warna teks netral (bukan amber)
                // -----------------------------------------------------------------
                ui.with_layout(egui::Layout::bottom_up(egui::Align::LEFT), |ui| {
                    ui.add_space(4.0);
                    let (f_sep, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 1.0), Sense::hover());
                    ui.painter().rect_filled(f_sep, 0.0, theme::PEMISAH_BARIS);
                    ui.add_space(8.0);

                    ui.horizontal(|ui| {
                        let total_devices = self.devices.len();
                        ui.label(
                            RichText::new(format!(
                                "Dipindai {} · {} perangkat terdaftar",
                                self.last_scanned_time, total_devices
                            ))
                            .font(theme::font_caption())
                            .color(theme::TEKS_REDUP),
                        );

                        // Notifikasi toast (warna netral/terang, BUKAN amber)
                        if let Some((ref note, timestamp)) = self.notification {
                            if timestamp.elapsed() > Duration::from_secs(4) {
                                self.notification = None;
                            } else {
                                let remaining = Duration::from_secs(4).saturating_sub(timestamp.elapsed());
                                ui.ctx().request_repaint_after(remaining);
                                ui.add_space(12.0);
                                ui.label(
                                    RichText::new(format!("· {note}"))
                                        .font(theme::font_caption())
                                        .color(theme::TEKS),
                                );
                            }
                        }

                        // Kalimat kanan
                        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                            ui.label(
                                RichText::new("Tampilan contoh dengan data simulasi")
                                    .font(theme::font_caption())
                                    .color(theme::TEKS_REDUP),
                            );

                            // Feature flag dev-states: Toolbar simulasi
                            #[cfg(feature = "dev-states")]
                            {
                                ui.add_space(16.0);
                                if ui.small_button("Simulasi: Putus").clicked() {
                                    self.simulated_server_error = !self.simulated_server_error;
                                }
                            }
                        });
                    });
                });
            });
    }
}

fn main() -> eframe::Result<()> {
    let native_options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_inner_size(Vec2::new(1080.0, 680.0))
            .with_min_inner_size(Vec2::new(880.0, 520.0))
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
        assert_eq!(devices.len(), 4);

        let registered_count = devices.iter().filter(|d| d.status != DeviceStatus::PendingPairing).count();
        let online_count = devices.iter().filter(|d| d.status == DeviceStatus::Online).count();
        let offline_count = devices.iter().filter(|d| d.status == DeviceStatus::Offline).count();
        let pending_count = devices.iter().filter(|d| d.status == DeviceStatus::PendingPairing).count();

        assert_eq!(registered_count, 3);
        assert_eq!(online_count, 2);
        assert_eq!(offline_count, 1);
        assert_eq!(pending_count, 1);
    }

    #[test]
    fn test_table_layout_alignment() {
        let layout = TableLayout::compute(1000.0);
        assert_eq!(layout.col_w[0], 110.0); // Status
        assert_eq!(layout.col_w[3], 110.0); // Engine
        assert_eq!(layout.col_w[4], 90.0);  // Suhu CPU
        assert_eq!(layout.col_w[5], 110.0); // Terakhir
        assert_eq!(layout.col_w[6], 190.0); // Aksi

        // Kolom 1 dan 2 harus proporsional dan sama lebar
        assert_eq!(layout.col_w[1], layout.col_w[2]);
        // Jarak antar kolom konsisten
        assert_eq!(layout.col_x[1], layout.col_x[0] + layout.col_w[0] + 16.0);
    }
}
