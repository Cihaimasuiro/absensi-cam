use eframe::egui::{self, Color32, RichText, Stroke, Vec2};
use launcher_core::{format_relative_time_id, DeviceStatus, EdgeDevice};
use crate::app::LauncherApp;
use crate::theme;

pub fn render_pending_banners(
    app: &mut LauncherApp,
    ui: &mut egui::Ui,
    approved_id: &mut Option<String>,
    rejected_id: &mut Option<String>,
) {
    let pending_devices: Vec<EdgeDevice> = app
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
                let is_narrow_banner = ui.available_width() < 820.0;

                if is_narrow_banner {
                    // Mode Adaptif Sempit (< 820px): Informasi perangkat di atas, tombol di baris baru
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
                            RichText::new(format!("Meminta {rel_time} · kedaluwarsa 09:48"))
                                .font(theme::font_caption())
                                .color(theme::TEKS_REDUP),
                        );

                        ui.add_space(14.0);

                        // Baris baru grup aksi: ComboBox Gedung, Setujui, Tolak
                        ui.horizontal(|ui| {
                            // Pilihan Gedung: border tunggal bawaan widget ComboBox (tinggi 36px)
                            ui.scope(|ui| {
                                ui.spacing_mut().interact_size.y = 36.0;
                                ui.visuals_mut().widgets.inactive.bg_fill = theme::PERMUKAAN;
                                egui::ComboBox::from_id_salt(format!("building_select_{}", dev.id))
                                    .selected_text(
                                        RichText::new(&app.selected_building)
                                            .font(theme::font_body())
                                            .color(theme::TEKS),
                                    )
                                    .show_ui(ui, |ui| {
                                        for b in &app.buildings {
                                            ui.selectable_value(
                                                &mut app.selected_building,
                                                b.clone(),
                                                RichText::new(b).font(theme::font_body()),
                                            );
                                        }
                                    });
                            });

                            ui.add_space(16.0);

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
                                *approved_id = Some(dev.id.clone());
                            }

                            ui.add_space(16.0);

                            let reject_btn = egui::Button::new(
                                RichText::new("Tolak")
                                    .font(theme::font_body())
                                    .color(theme::MERAH_OFFLINE),
                            )
                            .fill(Color32::TRANSPARENT)
                            .stroke(Stroke::NONE);

                            if ui.add(reject_btn).clicked() {
                                *rejected_id = Some(dev.id.clone());
                            }
                        });
                    });
                } else {
                    // Mode Penuh (>= 820px): Sejajar horizontal kiri-kanan
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
                                RichText::new(format!("Meminta {rel_time} · kedaluwarsa 09:48"))
                                    .font(theme::font_caption())
                                    .color(theme::TEKS_REDUP),
                            );
                        });

                        // Kolom Kanan: Pilihan Gedung (border tunggal 1px #3A403B, tinggi 36px),
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
                                *rejected_id = Some(dev.id.clone());
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
                                *approved_id = Some(dev.id.clone());
                            }

                            ui.add_space(16.0);

                            // Pilihan Gedung: border tunggal bawaan widget ComboBox (tinggi serasi 36px)
                            ui.scope(|ui| {
                                ui.spacing_mut().interact_size.y = 36.0;
                                ui.visuals_mut().widgets.inactive.bg_fill = theme::PERMUKAAN;
                                egui::ComboBox::from_id_salt(format!("building_select_{}", dev.id))
                                    .selected_text(
                                        RichText::new(&app.selected_building)
                                            .font(theme::font_body())
                                            .color(theme::TEKS),
                                    )
                                    .show_ui(ui, |ui| {
                                        for b in &app.buildings {
                                            ui.selectable_value(
                                                &mut app.selected_building,
                                                b.clone(),
                                                RichText::new(b).font(theme::font_body()),
                                            );
                                        }
                                    });
                            });
                        });
                    });
                }
            });

        ui.add_space(12.0);
    }

    if !pending_devices.is_empty() {
        ui.add_space(12.0);
    }
}
