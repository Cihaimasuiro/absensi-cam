use std::time::{Duration, Instant};
use eframe::egui::{self, Color32, Pos2, Rect, RichText, Sense, Stroke, Vec2};
use launcher_core::{format_relative_time_id, ApiClient, DeviceStatus, FilterTab};
use crate::app::{LauncherApp, LATEST_ENGINE_VERSION};
use crate::layout::TableLayout;
use crate::theme;
use crate::widgets::{
    child_cell, child_cell_vertical_center, draw_status_dot, draw_tab_item, draw_three_dots_button,
};

pub fn render_table(
    app: &mut LauncherApp,
    ui: &mut egui::Ui,
    trigger_rename: &mut Option<(String, String)>,
    copy_notification: &mut Option<String>,
    forget_id: &mut Option<String>,
    close_active_menu: &mut bool,
) {
    // -----------------------------------------------------------------
    // 3. Tab sebagai teks dengan garis bawah 2px & Kolom Cari 260px di kanan
    // Urutan: Semua, Menunggu, Online, Offline (Huruf Awal Kapital)
    // -----------------------------------------------------------------
    ui.horizontal(|ui| {
        // Hitungan tab 'Semua' hanya perangkat terdaftar
        let registered_cnt = app
            .devices
            .iter()
            .filter(|d| d.status != DeviceStatus::PendingPairing)
            .count();
        let pending_cnt = app
            .devices
            .iter()
            .filter(|d| d.status == DeviceStatus::PendingPairing)
            .count();
        let online_cnt = app
            .devices
            .iter()
            .filter(|d| d.status == DeviceStatus::Online)
            .count();
        let offline_cnt = app
            .devices
            .iter()
            .filter(|d| d.status == DeviceStatus::Offline)
            .count();

        if draw_tab_item(ui, "Semua", registered_cnt, app.active_tab == FilterTab::All) {
            app.active_tab = FilterTab::All;
        }
        ui.add_space(20.0);

        // Tetap tampilkan tab Menunggu agar bilah navigasi tidak bergeser
        if draw_tab_item(ui, "Menunggu", pending_cnt, app.active_tab == FilterTab::Pending) {
            app.active_tab = FilterTab::Pending;
        }
        ui.add_space(20.0);

        if draw_tab_item(ui, "Online", online_cnt, app.active_tab == FilterTab::Online) {
            app.active_tab = FilterTab::Online;
        }
        ui.add_space(20.0);

        if draw_tab_item(ui, "Offline", offline_cnt, app.active_tab == FilterTab::Offline) {
            app.active_tab = FilterTab::Offline;
        }

        // Kolom cari 260px di kanan (tinggi 36, radius 6)
        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            let search_edit = egui::TextEdit::singleline(&mut app.search_query)
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
    if let Some(ref dev_id) = app.rename_device_id.clone() {
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
                        egui::TextEdit::singleline(&mut app.rename_input)
                            .desired_width(220.0)
                            .font(theme::font_body()),
                    );
                    if ui.button(RichText::new("Simpan").font(theme::font_body())).clicked() {
                        if app.client.rename_device(dev_id, &app.rename_input).is_ok() {
                            if let Some(dev) = app.devices.iter_mut().find(|d| &d.id == dev_id) {
                                dev.hostname = app.rename_input.clone();
                            }
                            app.notification = Some((
                                format!("Nama diubah menjadi '{}'", app.rename_input),
                                Instant::now(),
                            ));
                        }
                        app.rename_device_id = None;
                        app.rename_input.clear();
                    }
                    if ui.button(RichText::new("Batal").font(theme::font_body())).clicked() {
                        app.rename_device_id = None;
                        app.rename_input.clear();
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
    let table_devices = app.filtered_table_devices();
    let available_table_width = ui.available_width();
    let layout = TableLayout::compute(available_table_width);

    if app.devices.is_empty() {
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
                app.devices = app.client.list_devices().unwrap_or_default();
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
                app.active_tab = FilterTab::All;
                app.search_query.clear();
            }
        });
    } else {
        // Header Kolom: huruf kapital awal, 12px, redup (adaptif mode kompak)
        let headers: &[(&str, usize)] = if layout.is_compact {
            &[
                ("Status", 0),
                ("Perangkat", 1),
                ("Alamat", 2),
                ("Aksi", 6),
            ]
        } else {
            &[
                ("Status", 0),
                ("Perangkat", 1),
                ("Alamat", 2),
                ("Engine", 3),
                ("Suhu CPU", 4),
                ("Terakhir", 5),
                ("Aksi", 6),
            ]
        };

        let (header_row_rect, _) = ui.allocate_exact_size(Vec2::new(layout.total_w, 24.0), Sense::hover());
        for (h, col_idx) in headers {
            let cell_rect = Rect::from_min_size(
                Pos2::new(header_row_rect.left() + layout.col_x[*col_idx], header_row_rect.top()),
                Vec2::new(layout.col_w[*col_idx], 24.0),
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
            .max_height(ui.available_height())
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
                    let is_just_approved = app
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

                    // 1. Kolom Status (100px)
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

                    // 2. Kolom Perangkat (1.4fr): truncate dengan elipsis jika nama sangat panjang
                    let cell_device = Rect::from_min_size(
                        Pos2::new(row_rect.left() + layout.col_x[1], row_rect.top()),
                        Vec2::new(layout.col_w[1], 56.0),
                    );
                    child_cell(ui, cell_device, |ui| {
                        ui.add(
                            egui::Label::new(
                                RichText::new(&dev.hostname)
                                    .font(theme::font_body_medium())
                                    .color(text_color),
                            )
                            .truncate(),
                        );
                    });

                    // 3. Kolom Alamat (1.4fr): IP (baris 1) & MAC (baris 2) rata tengah vertikal
                    let cell_addr = Rect::from_min_size(
                        Pos2::new(row_rect.left() + layout.col_x[2], row_rect.top()),
                        Vec2::new(layout.col_w[2], 56.0),
                    );
                    child_cell_vertical_center(ui, cell_addr, 36.0, |ui| {
                        ui.add(
                            egui::Label::new(
                                RichText::new(&dev.ip_address)
                                    .font(theme::font_technical())
                                    .color(text_color),
                            )
                            .truncate(),
                        );
                        ui.add(
                            egui::Label::new(
                                RichText::new(&dev.mac_address)
                                    .font(theme::font_technical_sm())
                                    .color(theme::TEKS_REDUP),
                            )
                            .truncate(),
                        );
                    });

                    // 4, 5, 6: Telemetri sekunder (hanya tampil pada mode penuh / !layout.is_compact)
                    if !layout.is_compact {
                        // 4. Kolom Engine (125px): versi usang tanpa chip
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

                        // 5. Kolom Suhu CPU (85px)
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

                        // 6. Kolom Terakhir (105px)
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
                    }

                    // 7. Kolom Aksi (160px): outline button (lebar 100px, tinggi 36) + tombol 3 titik (lebar 36) = total 144px
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

                            // Outline transparan, stroke #3A403B, lebar 100.0px (muat rapi bersama tombol ⋮ 36px di kolom 160px)
                            let main_btn = egui::Button::new(
                                RichText::new(main_btn_text)
                                    .font(theme::font_body())
                                    .color(theme::TEKS),
                            )
                            .fill(Color32::TRANSPARENT)
                            .stroke(Stroke::new(1.0, theme::OUTLINE_TOMBOL))
                            .corner_radius(theme::RADIUS_SM)
                            .min_size(Vec2::new(100.0, 36.0));

                            if ui.add(main_btn).clicked() {
                                if dev.status == DeviceStatus::Online {
                                    *copy_notification = Some(format!(
                                        "Membuka stream http://{}:5000/stream",
                                        dev.ip_address
                                    ));
                                } else {
                                    *copy_notification = Some(format!(
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
                                if app.active_menu_device_id.as_deref() == Some(&dev.id) {
                                    app.active_menu_device_id = None;
                                } else {
                                    app.active_menu_device_id = Some(dev.id.clone());
                                }
                            }

                            // Popover Menu ~180px, rata kanan di bawah/atas tombol titik tiga, tutup saat klik di luar
                            if app.active_menu_device_id.as_deref() == Some(&dev.id) {
                                let menu_width = 180.0;
                                let menu_height = 140.0;
                                let space_below = ui.clip_rect().bottom() - dots_rect.bottom();

                                // Buka ke atas bila ruang di bawah kurang dari tinggi menu (~140px + 48px padding)
                                let menu_y = if space_below < (menu_height + 48.0) {
                                    dots_rect.top() - menu_height - 4.0
                                } else {
                                    dots_rect.bottom() + 4.0
                                };

                                let menu_pos = Pos2::new(dots_rect.right() - menu_width, menu_y);
                                let menu_bounds = Rect::from_min_size(menu_pos, Vec2::new(menu_width, menu_height));

                                // Deteksi klik di luar popover untuk menutup otomatis
                                if ui.input(|i| i.pointer.any_pressed())
                                    && let Some(pos) = ui.input(|i| i.pointer.interact_pos())
                                    && !menu_bounds.contains(pos) && !dots_rect.contains(pos)
                                {
                                    *close_active_menu = true;
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
                                                    *copy_notification = Some(format!("IP {} disalin", dev.ip_address));
                                                    *close_active_menu = true;
                                                }
                                                if menu_item(ui, "Salin MAC", false) {
                                                    ui.ctx().copy_text(dev.mac_address.clone());
                                                    *copy_notification = Some(format!("MAC {} disalin", dev.mac_address));
                                                    *close_active_menu = true;
                                                }

                                                ui.add_space(2.0);
                                                let (m_sep, _) = ui.allocate_exact_size(Vec2::new(164.0, 1.0), Sense::hover());
                                                ui.painter().rect_filled(m_sep, 0.0, theme::PEMISAH_BARIS);
                                                ui.add_space(2.0);

                                                if menu_item(ui, "Ubah nama", false) {
                                                    *trigger_rename = Some((dev.id.clone(), dev.hostname.clone()));
                                                    *close_active_menu = true;
                                                }
                                                if menu_item(ui, "Lupakan perangkat", true) {
                                                    *forget_id = Some(dev.id.clone());
                                                    *close_active_menu = true;
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
                ui.add_space(16.0);
            });
    }
}
