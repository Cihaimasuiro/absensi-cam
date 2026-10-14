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
        }
    }

    fn filtered_devices(&self) -> Vec<EdgeDevice> {
        let mut list: Vec<EdgeDevice> = self
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
            .cloned()
            .collect();

        // Hierarchy rule: pending devices float to top
        list.sort_by_key(|d| match d.status {
            DeviceStatus::PendingPairing => 0,
            DeviceStatus::Online => 1,
            DeviceStatus::Offline => 2,
        });

        list
    }
}

// -----------------------------------------------------------------------------
// Custom UI Helpers (Painter-based components as requested)
// -----------------------------------------------------------------------------

/// Gambar titik status 8px: online = penuh hijau, offline = cincin 2px merah
fn draw_status_dot(ui: &mut egui::Ui, is_online: bool) {
    let (rect, _) = ui.allocate_exact_size(Vec2::new(10.0, 10.0), Sense::hover());
    let center = rect.center();
    let painter = ui.painter();
    if is_online {
        painter.circle_filled(center, 4.0, theme::HIJAU_ONLINE);
    } else {
        painter.circle_stroke(center, 4.0, Stroke::new(2.0, theme::MERAH_OFFLINE));
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
                    ui.ctx().request_repaint_after(Duration::from_millis(60));
                }
            }
        }

        let mut approved_id: Option<String> = None;
        let mut rejected_id: Option<String> = None;
        let mut forget_id: Option<String> = None;
        let mut copy_notification: Option<String> = None;
        let mut trigger_rename: Option<(String, String)> = None;

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
                        // Tombol outline "Pindai jaringan" (tinggi 36, radius 6)
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
                        .min_size(Vec2::new(130.0, 36.0))
                        .corner_radius(theme::RADIUS_SM);

                        let btn_resp = ui.add_enabled(!self.is_scanning, scan_btn);
                        if btn_resp.clicked() && !self.is_scanning {
                            self.is_scanning = true;
                            self.scan_started_at = Some(Instant::now());
                        }

                        ui.add_space(16.0);

                        // Titik status server + teks
                        ui.horizontal(|ui| {
                            draw_status_dot(ui, self.server_connected && !self.simulated_server_error);
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
                        let fill_rect = Rect::from_min_size(rect.min, Vec2::new(rect.width() * progress, 2.0));
                        painter.rect_filled(fill_rect, 0.0, theme::AMBER);
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
                                        // Aksi ubah URL
                                    }
                                    if ui.button(RichText::new("Coba lagi").font(theme::font_caption())).clicked() {
                                        self.simulated_server_error = false;
                                    }
                                });
                            });
                        });
                }

                // -----------------------------------------------------------------
                // Jarak antar bagian: 24px
                // -----------------------------------------------------------------
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
                                    ui.add_space(6.0);

                                    // MAC 20px mono + label "cocokkan dengan stiker"
                                    ui.horizontal(|ui| {
                                        ui.label(
                                            RichText::new(&dev.mac_address)
                                                .font(theme::font_mac_large())
                                                .color(theme::TEKS),
                                        );
                                        ui.add_space(8.0);
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

                                    // "Meminta 12 detik lalu · kedaluwarsa 9:48"
                                    let rel_time = format_relative_time_id(dev.last_seen_sec);
                                    ui.label(
                                        RichText::new(format!("Meminta {rel_time} · kedaluwarsa 09:48"))
                                            .font(theme::font_caption())
                                            .color(theme::TEKS_REDUP),
                                    );
                                });

                                // Kolom Kanan: Pilihan Gedung, Tombol Padat Amber "Setujui", Tombol Teks Merah "Tolak"
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

                                    // Pilihan Gedung (ComboBox)
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

                    ui.add_space(12.0);
                }

                if !pending_devices.is_empty() {
                    ui.add_space(12.0);
                }

                // -----------------------------------------------------------------
                // 3. Tab sebagai teks dengan garis bawah 2px & Kolom Cari 260px di kanan
                // -----------------------------------------------------------------
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

                    if draw_tab_item(ui, "semua", all_cnt, self.active_tab == FilterTab::All) {
                        self.active_tab = FilterTab::All;
                    }
                    ui.add_space(20.0);

                    if draw_tab_item(ui, "online", online_cnt, self.active_tab == FilterTab::Online) {
                        self.active_tab = FilterTab::Online;
                    }
                    ui.add_space(20.0);

                    if draw_tab_item(ui, "offline", offline_cnt, self.active_tab == FilterTab::Offline) {
                        self.active_tab = FilterTab::Offline;
                    }

                    if pending_cnt > 0 {
                        ui.add_space(20.0);
                        if draw_tab_item(ui, "menunggu", pending_cnt, self.active_tab == FilterTab::Pending) {
                            self.active_tab = FilterTab::Pending;
                        }
                    }

                    // Kolom cari 260px di kanan (tinggi 36, radius 6)
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        let search_edit = egui::TextEdit::singleline(&mut self.search_query)
                            .hint_text("cari perangkat, IP, MAC...")
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
                // 4. Tabel Tanpa Kartu
                // Kolom: Status 110 | Perangkat 1.4fr | Alamat 1.4fr | Engine 110 | Suhu CPU 90 | Terakhir 110 | Aksi 190, jarak kolom 16
                // Header kolom: huruf kecil biasa, 12px, redup (bukan kapital berjarak)
                // Baris: padding vertikal 16, hanya garis pemisah 1px antar baris
                // -----------------------------------------------------------------
                let filtered = self.filtered_devices();

                if self.devices.is_empty() {
                    // Daftar kosong total (0 perangkat) -> Tampilkan 3 langkah singkat
                    ui.vertical(|ui| {
                        ui.add_space(20.0);
                        ui.label(
                            RichText::new("belum ada perangkat terdaftar")
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
                } else if filtered.is_empty() {
                    // Filter kosong (ada perangkat tetapi pencarian / tab tidak cocok)
                    ui.vertical(|ui| {
                        ui.add_space(20.0);
                        ui.label(
                            RichText::new("tidak ada perangkat yang cocok")
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
                    // Hitung lebar proporsional 1.4fr
                    let col_gap = 16.0;
                    let fixed_width_sum = 110.0 + 110.0 + 90.0 + 110.0 + 190.0 + (6.0 * col_gap);
                    let available_width = ui.available_width();
                    let fr_avail = (available_width - fixed_width_sum).max(280.0);
                    let col_perangkat = fr_avail * 0.5;
                    let col_alamat = fr_avail * 0.5;

                    let widths = [
                        110.0,         // Status
                        col_perangkat, // Perangkat
                        col_alamat,    // Alamat
                        110.0,         // Engine
                        90.0,          // Suhu CPU
                        110.0,         // Terakhir
                        190.0,         // Aksi
                    ];

                    // Header Kolom: huruf kecil biasa, 12px, redup (bukan kapital berjarak)
                    let headers = [
                        "status",
                        "perangkat",
                        "alamat",
                        "engine",
                        "suhu cpu",
                        "terakhir",
                        "aksi",
                    ];

                    ui.horizontal(|ui| {
                        for (i, h) in headers.iter().enumerate() {
                            ui.allocate_ui_with_layout(
                                Vec2::new(widths[i], 24.0),
                                egui::Layout::left_to_right(egui::Align::Center),
                                |ui| {
                                    ui.label(
                                        RichText::new(*h)
                                            .font(theme::font_table_header())
                                            .color(theme::TEKS_REDUP),
                                    );
                                },
                            );
                            if i < headers.len() - 1 {
                                ui.add_space(col_gap);
                            }
                        }
                    });

                    // Garis pemisah header
                    ui.add_space(4.0);
                    let (sep_rect, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 1.0), Sense::hover());
                    ui.painter().rect_filled(sep_rect, 0.0, theme::PEMISAH_BARIS);
                    ui.add_space(4.0);

                    // Daftar Baris
                    egui::ScrollArea::vertical()
                        .max_height(ui.available_height() - 40.0)
                        .show(ui, |ui| {
                            for dev in filtered {
                                let is_offline = dev.status == DeviceStatus::Offline;
                                let is_online = dev.status == DeviceStatus::Online;
                                let is_outdated = dev.engine_version != LATEST_ENGINE_VERSION;

                                // Offline rule: seluruh teks baris redup
                                let text_color = if is_offline {
                                    theme::TEKS_REDUP
                                } else {
                                    theme::TEKS
                                };

                                // Padding vertikal 16 (8 atas, 8 bawah)
                                ui.add_space(8.0);

                                ui.horizontal(|ui| {
                                    // 1. Status (110px): titik 8px (penuh hijau / cincin merah), lalu teks
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[0], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
                                            draw_status_dot(ui, is_online);
                                            ui.add_space(6.0);
                                            let st_text = match dev.status {
                                                DeviceStatus::Online => "online",
                                                DeviceStatus::Offline => "offline",
                                                DeviceStatus::PendingPairing => "menunggu",
                                            };
                                            ui.label(
                                                RichText::new(st_text)
                                                    .font(theme::font_body())
                                                    .color(text_color),
                                            );
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 2. Perangkat (1.4fr): nama perangkat
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[1], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
                                            ui.label(
                                                RichText::new(&dev.hostname)
                                                    .font(theme::font_body_medium())
                                                    .color(text_color),
                                            );
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 3. Alamat (1.4fr): IP dan MAC dalam font Mono
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[2], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
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
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 4. Engine (110px): versi usang tanpa chip ("v0.2.8 · usang")
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[3], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
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
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 5. Suhu CPU (90px): mono
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[4], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
                                            let temp_text = if is_online {
                                                format!("{:.1}°C", dev.temperature_c)
                                            } else {
                                                "—".to_string()
                                            };
                                            ui.label(
                                                RichText::new(temp_text)
                                                    .font(theme::font_technical())
                                                    .color(text_color),
                                            );
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 6. Terakhir (110px): waktu relatif
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[5], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
                                            let rel = format_relative_time_id(dev.last_seen_sec);
                                            ui.label(
                                                RichText::new(rel)
                                                    .font(theme::font_caption())
                                                    .color(theme::TEKS_REDUP),
                                            );
                                        },
                                    );
                                    ui.add_space(col_gap);

                                    // 7. Aksi (190px): tombol outline (tinggi 36, radius 6) "Buka stream" / "Ping" + tombol tiga titik
                                    ui.allocate_ui_with_layout(
                                        Vec2::new(widths[6], 36.0),
                                        egui::Layout::left_to_right(egui::Align::Center),
                                        |ui| {
                                            let main_btn_text = if is_online {
                                                "Buka stream"
                                            } else {
                                                "Ping"
                                            };

                                            let main_btn = egui::Button::new(
                                                RichText::new(main_btn_text)
                                                    .font(theme::font_body())
                                                    .color(theme::TEKS),
                                            )
                                            .min_size(Vec2::new(104.0, 36.0))
                                            .corner_radius(theme::RADIUS_SM);

                                            if ui.add(main_btn).clicked() {
                                                if is_online {
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

                                            // Tombol ikon tiga titik kustom painter
                                            let dots_resp = draw_three_dots_button(ui);
                                            let popup_id = ui.make_persistent_id(format!("menu_{}", dev.id));
                                            if dots_resp.clicked() {
                                                if self.active_menu_device_id.as_deref() == Some(&dev.id) {
                                                    self.active_menu_device_id = None;
                                                } else {
                                                    self.active_menu_device_id = Some(dev.id.clone());
                                                }
                                            }

                                            if self.active_menu_device_id.as_deref() == Some(&dev.id) {
                                                egui::Area::new(popup_id)
                                                    .order(egui::Order::Foreground)
                                                    .fixed_pos(dots_resp.rect.left_bottom() + Vec2::new(-110.0, 4.0))
                                                    .show(ui.ctx(), |ui| {
                                                        egui::Frame::new()
                                                            .fill(theme::PERMUKAAN)
                                                            .stroke(Stroke::new(1.0, theme::GARIS))
                                                            .corner_radius(theme::RADIUS_SM)
                                                            .inner_margin(egui::Margin::symmetric(12, 8))
                                                            .show(ui, |ui| {
                                                                ui.set_min_width(140.0);
                                                                if ui.button(RichText::new("Salin IP").font(theme::font_body())).clicked() {
                                                                    ui.ctx().copy_text(dev.ip_address.clone());
                                                                    copy_notification = Some(format!("IP {} disalin", dev.ip_address));
                                                                    self.active_menu_device_id = None;
                                                                }
                                                                if ui.button(RichText::new("Salin MAC").font(theme::font_body())).clicked() {
                                                                    ui.ctx().copy_text(dev.mac_address.clone());
                                                                    copy_notification = Some(format!("MAC {} disalin", dev.mac_address));
                                                                    self.active_menu_device_id = None;
                                                                }
                                                                ui.add_space(4.0);
                                                                let (m_sep, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 1.0), Sense::hover());
                                                                ui.painter().rect_filled(m_sep, 0.0, theme::PEMISAH_BARIS);
                                                                ui.add_space(4.0);

                                                                if ui.button(RichText::new("Ubah nama").font(theme::font_body())).clicked() {
                                                                    trigger_rename = Some((dev.id.clone(), dev.hostname.clone()));
                                                                    self.active_menu_device_id = None;
                                                                }
                                                                if ui.button(
                                                                    RichText::new("Lupakan perangkat")
                                                                        .font(theme::font_body())
                                                                        .color(theme::MERAH_OFFLINE),
                                                                ).clicked() {
                                                                    forget_id = Some(dev.id.clone());
                                                                    self.active_menu_device_id = None;
                                                                }
                                                            });
                                                    });
                                            }
                                        },
                                    );
                                });

                                ui.add_space(8.0);

                                // Garis pemisah 1px antar baris
                                let (row_sep, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 1.0), Sense::hover());
                                ui.painter().rect_filled(row_sep, 0.0, theme::PEMISAH_BARIS);
                            }
                        });
                }

                // -----------------------------------------------------------------
                // Actions handling
                // -----------------------------------------------------------------
                if let Some(id) = approved_id {
                    if self.client.approve_device(&id).is_ok() {
                        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
                            dev.status = DeviceStatus::Online;
                            dev.pairing_code = None;
                            self.notification = Some((
                                format!("Perangkat {} disetujui ({})", dev.hostname, self.selected_building),
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

                        // Notifikasi toast jika ada
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
                                        .color(theme::AMBER),
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
            .with_title("Absensi-Cam // Perangkat"),
        ..Default::default()
    };

    eframe::run_native(
        "Absensi-Cam // Perangkat",
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

        let online_count = devices.iter().filter(|d| d.status == DeviceStatus::Online).count();
        let offline_count = devices.iter().filter(|d| d.status == DeviceStatus::Offline).count();
        let pending_count = devices.iter().filter(|d| d.status == DeviceStatus::PendingPairing).count();

        assert_eq!(online_count, 2);
        assert_eq!(offline_count, 1);
        assert_eq!(pending_count, 1);
    }
}
